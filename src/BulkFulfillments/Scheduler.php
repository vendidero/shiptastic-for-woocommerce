<?php

namespace Vendidero\Shiptastic\BulkFulfillments;

defined( 'ABSPATH' ) || exit;

/**
 * Runs background workflows on a schedule via Action Scheduler.
 *
 * - The next run is scheduled as a single action (interval: now + minutes, daily: next time in the site timezone)
 *   and rescheduled after each run - so daylight saving changes are respected.
 * - One run per workflow at a time, processed in chunks (`Runner::process_chunk()`), continued until no matching
 *   queue item is left.
 * - Runs are executed as the workflow's "run as" user, who must be able to manage WooCommerce.
 */
class Scheduler {

	const SCHEDULE_HOOK = 'woocommerce_shiptastic_fulfillment_workflow_scheduled';

	const RUN_HOOK = 'woocommerce_shiptastic_fulfillment_workflow_run';

	const RUN_GROUP = 'woocommerce-shiptastic-fulfillment-run';

	public static function init() {
		add_action( self::SCHEDULE_HOOK, array( __CLASS__, 'scheduled_run' ), 10, 1 );
		add_action( self::RUN_HOOK, array( __CLASS__, 'run_chunk' ), 10, 1 );

		add_action( 'woocommerce_shiptastic_new_fulfillment_workflow', array( __CLASS__, 'sync_schedule' ), 20, 1 );
		add_action( 'woocommerce_shiptastic_fulfillment_workflow_updated', array( __CLASS__, 'sync_schedule' ), 20, 1 );
		add_action( 'woocommerce_shiptastic_fulfillment_workflow_deleted', array( __CLASS__, 'sync_schedule' ), 20, 1 );

		add_action( 'admin_notices', array( __CLASS__, 'admin_notices' ) );
	}

	protected static function get_schedule_group( $workflow_id ) {
		return 'woocommerce-shiptastic-fulfillment-workflow-' . absint( $workflow_id );
	}

	/**
	 * @param Workflow $workflow
	 * @param int|null $now
	 *
	 * @return int|null Timestamp of the next scheduled run.
	 */
	public static function get_next_run( $workflow, $now = null ) {
		$now      = is_null( $now ) ? time() : $now;
		$schedule = $workflow->get_schedule();

		if ( ! $workflow->is_scheduled() ) {
			return null;
		}

		if ( 'interval' === $schedule['type'] ) {
			return $now + absint( $schedule['minutes'] ) * MINUTE_IN_SECONDS;
		}

		$timezone = wp_timezone();
		$next     = null;

		foreach ( $schedule['times'] as $time ) {
			list( $hour, $minute ) = array_map( 'absint', explode( ':', $time ) );

			$candidate = ( new \DateTimeImmutable( '@' . $now ) )->setTimezone( $timezone )->setTime( $hour, $minute );

			if ( $candidate->getTimestamp() <= $now ) {
				$candidate = $candidate->modify( '+1 day' )->setTime( $hour, $minute );
			}

			if ( is_null( $next ) || $candidate->getTimestamp() < $next ) {
				$next = $candidate->getTimestamp();
			}
		}

		return $next;
	}

	/**
	 * (Re)schedules the next run of a workflow after it was saved/deleted.
	 *
	 * @param int $workflow_id
	 */
	public static function sync_schedule( $workflow_id ) {
		if ( ! function_exists( 'WC' ) || ! WC()->queue() ) {
			return;
		}

		WC()->queue()->cancel_all( '', array(), self::get_schedule_group( $workflow_id ) );

		$workflow = new Workflow( $workflow_id );

		if ( $workflow->get_id() && ( $next = self::get_next_run( $workflow ) ) ) {
			WC()->queue()->schedule_single( $next, self::SCHEDULE_HOOK, array( 'workflow_id' => $workflow->get_id() ), self::get_schedule_group( $workflow->get_id() ) );
		}
	}

	/**
	 * @param int $workflow_id
	 *
	 * @return bool Whether a run of the workflow is in progress (pending chunk).
	 */
	public static function is_running( $workflow_id ) {
		if ( ! function_exists( 'WC' ) || ! WC()->queue() ) {
			return false;
		}

		return (bool) WC()->queue()->get_next( self::RUN_HOOK, array( 'workflow_id' => absint( $workflow_id ) ), self::RUN_GROUP );
	}

	/**
	 * A scheduled run starts: schedule the next one and process the first chunk.
	 *
	 * @param int $workflow_id
	 */
	public static function scheduled_run( $workflow_id ) {
		$workflow = new Workflow( $workflow_id );

		if ( ! $workflow->get_id() || ! $workflow->is_scheduled() ) {
			return;
		}

		self::sync_schedule( $workflow->get_id() );

		if ( self::is_running( $workflow->get_id() ) ) {
			return;
		}

		self::run_chunk( $workflow->get_id() );
	}

	/**
	 * Processes a chunk of a scheduled run as the workflow's "run as" user and continues with the next chunk
	 * in case more items may be left.
	 *
	 * @param int $workflow_id
	 *
	 * @return array|null The chunk result.
	 */
	public static function run_chunk( $workflow_id ) {
		$workflow = new Workflow( $workflow_id );

		if ( ! $workflow->get_id() || ! $workflow->is_active() || $workflow->is_paused() || 'background' !== $workflow->get_mode() ) {
			return null;
		}

		$user = $workflow->get_run_as() ? get_user_by( 'id', $workflow->get_run_as() ) : false;

		if ( ! $user || ! user_can( $user, 'manage_woocommerce' ) ) {
			self::pause( $workflow, _x( 'The user to run the workflow as does not exist or may not manage WooCommerce.', 'shipments', 'shiptastic-for-woocommerce' ) );

			return null;
		}

		$previous_user = get_current_user_id();
		wp_set_current_user( $user->ID );

		$result = Runner::process_chunk(
			$workflow,
			array(
				'source'  => 'scheduled',
				'user_id' => $user->ID,
			)
		);

		wp_set_current_user( $previous_user );

		if ( ! $result['stopped'] && $result['claimed'] >= Queue::get_claim_limit( 'scheduled' ) && function_exists( 'WC' ) && WC()->queue() ) {
			WC()->queue()->schedule_single( time(), self::RUN_HOOK, array( 'workflow_id' => $workflow->get_id() ), self::RUN_GROUP );
		}

		return $result;
	}

	/**
	 * Pauses the schedule of a workflow (e.g. a scheduled run stopped on an error) and notifies the admin.
	 *
	 * @param Workflow $workflow
	 * @param string $reason
	 */
	public static function pause( $workflow, $reason ) {
		$workflow->set_paused( true );
		$workflow->set_paused_reason( $reason );
		$workflow->save();

		/* translators: 1: workflow title 2: reason */
		$message = sprintf( _x( 'The scheduled fulfillment workflow "%1$s" has been paused: %2$s', 'shipments', 'shiptastic-for-woocommerce' ), $workflow->get_title(), $reason );

		wp_mail(
			get_option( 'admin_email' ),
			/* translators: %s workflow title */
			sprintf( _x( 'Fulfillment workflow "%s" paused', 'shipments', 'shiptastic-for-woocommerce' ), $workflow->get_title() ),
			$message . "\n\n" . _x( 'Please resolve the error and resume the workflow.', 'shipments', 'shiptastic-for-woocommerce' )
		);

		/**
		 * Action that fires after a workflow schedule has been paused.
		 *
		 * @param Workflow $workflow
		 * @param string   $reason
		 *
		 * @package Vendidero/Shiptastic
		 */
		do_action( 'woocommerce_shiptastic_fulfillment_workflow_paused', $workflow, $reason );
	}

	/**
	 * @param Workflow $workflow
	 */
	public static function resume( $workflow ) {
		$workflow->set_paused( false );
		$workflow->set_paused_reason( '' );
		$workflow->save();
	}

	public static function admin_notices() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		foreach ( \Vendidero\Shiptastic\DataStores\FulfillmentWorkflow::get_active_workflow_ids() as $workflow_id ) {
			$workflow = new Workflow( $workflow_id );

			if ( ! $workflow->is_paused() ) {
				continue;
			}
			?>
			<div class="notice notice-error">
				<p>
					<?php
					/* translators: 1: workflow title 2: reason */
					echo esc_html( sprintf( _x( 'The scheduled fulfillment workflow "%1$s" has been paused: %2$s', 'shipments', 'shiptastic-for-woocommerce' ), $workflow->get_title(), $workflow->get_paused_reason() ) );
					?>
				</p>
			</div>
			<?php
		}
	}
}
