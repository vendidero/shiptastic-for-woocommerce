<?php

namespace Vendidero\Shiptastic\BulkFulfillments;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Steps of run level phases (see RunPhases): before_queue steps run once per batch of a run (each claim of a run is
 * a batch, see RunItems), after_queue steps once when the run ends. Their results are logged per run, batch and
 * action, so that retries, ticks and resumed runs never execute a step twice for the same batch.
 *
 * Errors: the step stops its phase. Orders of a batch whose before_queue step failed are blocked until the step is
 * retried or skipped (get_blocking()). Failed after_queue steps are listed within the run's report (retry/skip).
 */
class RunSteps {

	const STATUSES = array( 'pending', 'done', 'error', 'skipped' );

	protected static function now() {
		return gmdate( 'Y-m-d H:i:s' );
	}

	/*
	|--------------------------------------------------------------------------
	| Log
	|--------------------------------------------------------------------------
	*/

	/**
	 * @param object $row
	 *
	 * @return array
	 */
	protected static function parse_row( $row ) {
		$data      = json_decode( (string) $row->run_step_data, true );
		$queue_ids = json_decode( (string) $row->run_step_queue_ids, true );

		return array(
			'id'            => absint( $row->run_step_id ),
			'run_id'        => absint( $row->run_step_run_id ),
			'batch'         => absint( $row->run_step_batch ),
			'phase'         => $row->run_step_phase,
			'action'        => $row->run_step_action,
			'status'        => $row->run_step_status,
			'queue_ids'     => is_array( $queue_ids ) ? array_map( 'absint', $queue_ids ) : array(),
			'data'          => is_array( $data ) ? $data : array(),
			'error'         => (string) $row->run_step_error,
			'date_modified' => $row->run_step_date_modified_gmt,
		);
	}

	/**
	 * @param int $run_id
	 * @param int $batch
	 * @param string $phase
	 * @param string $action
	 *
	 * @return array|null
	 */
	public static function get_entry( $run_id, $batch, $phase, $action ) {
		global $wpdb;

		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->stc_fulfillment_run_steps} WHERE run_step_run_id = %d AND run_step_batch = %d AND run_step_phase = %s AND run_step_action = %s", $run_id, $batch, $phase, $action ) );

		return $row ? self::parse_row( $row ) : null;
	}

	/**
	 * @param int $entry_id
	 *
	 * @return array|null
	 */
	public static function get_entry_by_id( $entry_id ) {
		global $wpdb;

		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->stc_fulfillment_run_steps} WHERE run_step_id = %d", $entry_id ) );

		return $row ? self::parse_row( $row ) : null;
	}

	/**
	 * @param int $run_id
	 *
	 * @return array[]
	 */
	public static function get_entries( $run_id ) {
		global $wpdb;

		return array_map( array( __CLASS__, 'parse_row' ), $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->stc_fulfillment_run_steps} WHERE run_step_run_id = %d ORDER BY run_step_batch = 0, run_step_batch ASC, run_step_id ASC", $run_id ) ) );
	}

	/**
	 * @param int $run_id
	 * @param int $batch
	 * @param string $phase
	 * @param string $action
	 * @param array $args status, queue_ids, data, error
	 */
	public static function record( $run_id, $batch, $phase, $action, $args = array() ) {
		global $wpdb;

		$args = wp_parse_args(
			$args,
			array(
				'status'    => 'done',
				'queue_ids' => array(),
				'data'      => array(),
				'error'     => '',
			)
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$wpdb->stc_fulfillment_run_steps} (run_step_run_id, run_step_batch, run_step_phase, run_step_action, run_step_status, run_step_queue_ids, run_step_data, run_step_error, run_step_date_modified_gmt) VALUES (%d, %d, %s, %s, %s, %s, %s, %s, %s)
				ON DUPLICATE KEY UPDATE run_step_status = VALUES(run_step_status), run_step_queue_ids = VALUES(run_step_queue_ids), run_step_data = VALUES(run_step_data), run_step_error = VALUES(run_step_error), run_step_date_modified_gmt = VALUES(run_step_date_modified_gmt)",
				$run_id,
				$batch,
				$phase,
				$action,
				in_array( $args['status'], self::STATUSES, true ) ? $args['status'] : 'done',
				wp_json_encode( array_values( array_map( 'absint', (array) $args['queue_ids'] ) ) ),
				wp_json_encode( (array) $args['data'] ),
				(string) $args['error'],
				self::now()
			)
		);
	}

	public static function delete_for_run( $run_id ) {
		global $wpdb;

		$wpdb->delete( $wpdb->stc_fulfillment_run_steps, array( 'run_step_run_id' => absint( $run_id ) ) );
	}

	/*
	|--------------------------------------------------------------------------
	| Execution
	|--------------------------------------------------------------------------
	*/

	/**
	 * @param Workflow $workflow
	 * @param string $phase
	 *
	 * @return Step[] The workflow's steps of a run level phase (they run automatically).
	 */
	public static function get_phase_steps( $workflow, $phase ) {
		$steps = array();

		if ( ! $workflow ) {
			return $steps;
		}

		foreach ( $workflow->get_phase_steps( $phase ) as $step ) {
			$steps[] = new Step(
				array(
					'action'   => $step['action'],
					'instance' => isset( $step['instance'] ) ? $step['instance'] : '',
					'mode'     => 'background',
					'settings' => (array) $step['settings'],
					'phase'    => $phase,
				)
			);
		}

		return $steps;
	}

	/**
	 * @param Workflow $workflow
	 * @param string $phase
	 *
	 * @return bool
	 */
	public static function has_phase( $workflow, $phase ) {
		return ! empty( self::get_phase_steps( $workflow, $phase ) );
	}

	/**
	 * @return array The result of a phase: status (none: no steps, done, error), run, entry (the failed entry),
	 *               message, code.
	 */
	protected static function get_default_result( $run ) {
		return array(
			'status'  => 'none',
			'run'     => $run,
			'entry'   => null,
			'message' => '',
			'code'    => '',
		);
	}

	/**
	 * Starts a batch of a run: the claimed queue items are added to the run (RunItems), steps of the before_queue
	 * phase run for them. Item steps they require (e.g. creating shipments) run for the batch's items before.
	 *
	 * @param Run $run
	 * @param QueueItem[] $items
	 * @param string $token The lock token of the claimed items.
	 * @param string $source interactive|background|scheduled
	 *
	 * @return array See get_default_result(), plus batch.
	 */
	public static function start_batch( $run, $items, $token, $source = 'interactive' ) {
		$queue_ids = array_map(
			function ( $item ) {
				return $item->get_id();
			},
			array_values( $items )
		);
		$batch     = RunItems::add_batch( $run->get_id(), $queue_ids );
		$workflow  = $run->get_workflow();
		$result    = array_merge( self::get_default_result( $run ), array( 'batch' => $batch ) );

		if ( ! $batch || ! self::has_phase( $workflow, RunPhases::BEFORE_QUEUE ) ) {
			return $result;
		}

		$run = self::prepare_batch( $run, $workflow, $queue_ids, $token, $source );

		return array_merge( self::run_phase( $run, RunPhases::BEFORE_QUEUE, $batch, $queue_ids, $source ), array( 'batch' => $batch ) );
	}

	/**
	 * Runs the item steps required by the before_queue steps (e.g. creating shipments for a picking list) for the
	 * batch's items, as far as they run automatically. Errors are left to the item (shown once it is worked on).
	 *
	 * @param Run $run
	 * @param Workflow $workflow
	 * @param int[] $queue_ids
	 * @param string $token
	 * @param string $source
	 *
	 * @return Run
	 */
	protected static function prepare_batch( $run, $workflow, $queue_ids, $token, $source ) {
		$required = array();

		foreach ( self::get_phase_steps( $workflow, RunPhases::BEFORE_QUEUE ) as $step ) {
			$required = array_merge( $required, Workflow::get_required_actions( $step->action ) );
		}

		$required = array_unique( $required );

		if ( empty( $required ) ) {
			return $run;
		}

		foreach ( $queue_ids as $queue_id ) {
			for ( $i = 0; $i < Runner::MAX_STEPS_PER_CALL; $i++ ) {
				$item = Queue::get( $queue_id );
				$next = $item ? Runner::get_next_step( $item, $workflow ) : null;

				if ( ! $next || ! in_array( $next->action, $required, true ) || ! $next->get_action()::supports_mode( 'background' ) ) {
					break;
				}

				$result = Runner::run_step( $queue_id, $workflow, $token, $next, 'interactive' === $source ? 'background' : $source );

				if ( $result->is_error() ) {
					break;
				}

				$run = RunExport::add_files( $run, $item, $next, $result );
			}
		}

		return $run;
	}

	/**
	 * Runs the steps of a run level phase which are not done (or skipped) yet for the batch. Stops at the first error.
	 *
	 * @param Run $run
	 * @param string $phase
	 * @param int $batch
	 * @param int[] $queue_ids
	 * @param string $source
	 *
	 * @return array See get_default_result().
	 */
	public static function run_phase( $run, $phase, $batch, $queue_ids, $source = 'background' ) {
		$workflow = $run->get_workflow();
		$result   = self::get_default_result( $run );

		foreach ( self::get_phase_steps( $workflow, $phase ) as $step ) {
			$entry = self::get_entry( $run->get_id(), $batch, $phase, $step->get_log_key() );

			if ( $entry && in_array( $entry['status'], array( 'done', 'skipped' ), true ) ) {
				$result['status'] = 'done';
				continue;
			}

			// E.g. no order was completed within the run.
			if ( empty( $queue_ids ) ) {
				self::record(
					$run->get_id(),
					$batch,
					$phase,
					$step->get_log_key(),
					array(
						'status' => 'skipped',
						'error'  => _x( 'No order was completed.', 'shipments', 'shiptastic-for-woocommerce' ),
					)
				);
				$result['status'] = 'done';
				continue;
			}

			$action  = $step->get_action();
			$context = new RunContext( $run, $workflow, $step, $phase, $batch, $queue_ids, $source );

			/**
			 * Action that fires before a step of a run level phase (start of batch, end of run) runs.
			 *
			 * @param Step       $step
			 * @param RunContext $context
			 *
			 * @package Vendidero/Shiptastic
			 */
			do_action( 'woocommerce_shiptastic_fulfillment_before_run_step', $step, $context );

			try {
				$step_result = $action ? $action->run( $context ) : StepResult::error( _x( 'The action does not exist.', 'shipments', 'shiptastic-for-woocommerce' ) );
			} catch ( \Exception $e ) {
				$step_result = StepResult::error( $e->getMessage(), 'exception' );
			}

			if ( ! is_a( $step_result, StepResult::class ) ) {
				$step_result = StepResult::error( _x( 'The action returned an invalid result.', 'shipments', 'shiptastic-for-woocommerce' ) );
			}

			self::record(
				$run->get_id(),
				$batch,
				$phase,
				$step->get_log_key(),
				array(
					'status'    => $step_result->is_error() ? 'error' : 'done',
					'queue_ids' => $queue_ids,
					'data'      => $step_result->get_data(),
					'error'     => $step_result->is_error() ? $step_result->get_message() : '',
				)
			);

			/**
			 * Action that fires after a step of a run level phase (start of batch, end of run) ran.
			 *
			 * @param Step       $step
			 * @param StepResult $step_result
			 * @param RunContext $context
			 *
			 * @package Vendidero/Shiptastic
			 */
			do_action( 'woocommerce_shiptastic_fulfillment_after_run_step', $step, $step_result, $context );

			if ( $step_result->is_error() ) {
				return array_merge(
					$result,
					array(
						'status'  => 'error',
						'entry'   => self::get_entry( $run->get_id(), $batch, $phase, $step->get_log_key() ),
						'message' => $step_result->get_message(),
						'code'    => $step_result->get_code(),
					)
				);
			}

			$run              = RunExport::add_files( $run, null, $step, $step_result, RunPhases::BEFORE_QUEUE === $phase ? 'batch-' . $batch : 'run' );
			$result['run']    = $run;
			$result['status'] = 'done';
		}

		return $result;
	}

	/**
	 * Runs the after_queue steps of a run (once it ends) for the orders completed within the run.
	 *
	 * @param Run $run
	 * @param string $source
	 *
	 * @return array See get_default_result().
	 */
	public static function finish( $run, $source = 'background' ) {
		if ( ! self::has_phase( $run->get_workflow(), RunPhases::AFTER_QUEUE ) ) {
			return self::get_default_result( $run );
		}

		return self::run_phase( $run, RunPhases::AFTER_QUEUE, 0, RunItems::get_queue_ids( $run->get_id(), array( 'status' => 'completed' ) ), $source );
	}

	/**
	 * Orders of a batch whose before_queue steps failed (or did not finish) are blocked until the steps are retried
	 * or skipped.
	 *
	 * @param Run $run
	 * @param int $queue_id
	 *
	 * @return WP_Error|null
	 */
	public static function get_blocking( $run, $queue_id ) {
		if ( ! $run ) {
			return null;
		}

		$batch = RunItems::get_batch( $run->get_id(), $queue_id );

		if ( ! $batch ) {
			return null;
		}

		foreach ( self::get_phase_steps( $run->get_workflow(), RunPhases::BEFORE_QUEUE ) as $step ) {
			$entry = self::get_entry( $run->get_id(), $batch, RunPhases::BEFORE_QUEUE, $step->get_log_key() );

			if ( $entry && in_array( $entry['status'], array( 'pending', 'error' ), true ) ) {
				$action = $step->get_action();

				/* translators: 1: step title 2: error message */
				return new WP_Error( 'run_step_pending', sprintf( _x( '%1$s (start of batch) failed: %2$s Retry or skip it to continue.', 'shipments', 'shiptastic-for-woocommerce' ), $action ? $action::get_title() : $step->action, $entry['error'] ), array( 'entry' => $entry['id'] ) );
			}
		}

		return null;
	}

	/**
	 * @param Run $run
	 * @param int $entry_id
	 *
	 * @return array|WP_Error
	 */
	protected static function get_run_entry( $run, $entry_id ) {
		$entry = self::get_entry_by_id( $entry_id );

		if ( ! $entry || $entry['run_id'] !== $run->get_id() ) {
			return new WP_Error( 'run_step_not_found', _x( 'The step does not exist.', 'shipments', 'shiptastic-for-woocommerce' ), array( 'status' => 404 ) );
		}

		return $entry;
	}

	/**
	 * Retries a failed run level step (and the following steps of its phase).
	 *
	 * @param Run $run
	 * @param int $entry_id
	 * @param string $source
	 *
	 * @return array|WP_Error See get_default_result().
	 */
	public static function retry( $run, $entry_id, $source = 'background' ) {
		$entry = self::get_run_entry( $run, $entry_id );

		if ( is_wp_error( $entry ) ) {
			return $entry;
		}

		$queue_ids = RunPhases::AFTER_QUEUE === $entry['phase'] ? RunItems::get_queue_ids( $run->get_id(), array( 'status' => 'completed' ) ) : $entry['queue_ids'];

		return self::run_phase( $run, $entry['phase'], $entry['batch'], $queue_ids, $source );
	}

	/**
	 * Skips a failed run level step, the following steps of its phase run.
	 *
	 * @param Run $run
	 * @param int $entry_id
	 * @param string $source
	 *
	 * @return array|WP_Error See get_default_result().
	 */
	public static function skip( $run, $entry_id, $source = 'background' ) {
		$entry = self::get_run_entry( $run, $entry_id );

		if ( is_wp_error( $entry ) ) {
			return $entry;
		}

		self::record(
			$run->get_id(),
			$entry['batch'],
			$entry['phase'],
			$entry['action'],
			array(
				'status'    => 'skipped',
				'queue_ids' => $entry['queue_ids'],
				'data'      => $entry['data'],
				'error'     => $entry['error'],
			)
		);

		$queue_ids = RunPhases::AFTER_QUEUE === $entry['phase'] ? RunItems::get_queue_ids( $run->get_id(), array( 'status' => 'completed' ) ) : $entry['queue_ids'];

		return self::run_phase( $run, $entry['phase'], $entry['batch'], $queue_ids, $source );
	}

	/**
	 * @param array $entry
	 *
	 * @return array REST/app data of a run level step.
	 */
	public static function prepare_entry( $entry ) {
		$action = ActionRegistry::get( $entry['action'] );

		return array(
			'id'          => $entry['id'],
			'batch'       => $entry['batch'],
			'phase'       => $entry['phase'],
			'phase_title' => RunPhases::get_title( $entry['phase'] ),
			'action'      => $entry['action'],
			'title'       => $action ? $action::get_title() : $entry['action'],
			'status'      => $entry['status'],
			'message'     => $entry['error'],
			'order_count' => count( $entry['queue_ids'] ),
		);
	}

	/**
	 * @param Run $run
	 *
	 * @return array[] REST/app data of the run's run level steps.
	 */
	public static function prepare( $run ) {
		return array_map( array( __CLASS__, 'prepare_entry' ), self::get_entries( $run->get_id() ) );
	}
}
