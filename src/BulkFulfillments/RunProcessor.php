<?php

namespace Vendidero\Shiptastic\BulkFulfillments;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Interactive runs: staff working through the orders of a workflow within the fulfillment app. Runs of other
 * sources (e.g. automation runs) are processed by extensions.
 */
class RunProcessor {

	public static function init() {
		add_action( RunExport::CLEANUP_HOOK, array( __CLASS__, 'finish_idle_interactive' ), 5 );
	}

	/**
	 * Starts an interactive run (staff working through the orders of a workflow within the app) or returns the user's
	 * active interactive run of the workflow (e.g. after a reload or on another device). All claims of the run share
	 * the run's lock token: the server attributes step results (files, counts) to the run and returns its locked
	 * orders when resuming.
	 *
	 * @param Workflow $workflow
	 * @param int $user_id
	 *
	 * @return Run|WP_Error
	 */
	public static function start_interactive( $workflow, $user_id = 0 ) {
		$user_id = $user_id ? absint( $user_id ) : get_current_user_id();

		if ( ! $workflow->get_id() ) {
			return new WP_Error( 'workflow_not_found', _x( 'The workflow does not exist.', 'shipments', 'shiptastic-for-woocommerce' ), array( 'status' => 404 ) );
		}

		if ( 'interactive' !== $workflow->get_mode() ) {
			return new WP_Error( 'workflow_not_interactive', _x( 'This workflow cannot be worked on within the app.', 'shipments', 'shiptastic-for-woocommerce' ), array( 'status' => 400 ) );
		}

		$active = Run::query(
			array(
				'workflow_id' => $workflow->get_id(),
				'user_id'     => $user_id,
				'source'      => 'interactive',
				'status'      => array( 'running' ),
				'limit'       => 1,
			)
		);

		if ( ! empty( $active ) ) {
			Run::touch( $active[0]->get_id() );

			return Run::get( $active[0]->get_id() );
		}

		return Run::create(
			array(
				'workflow_id' => $workflow->get_id(),
				'source'      => 'interactive',
				'user_id'     => $user_id,
				'lock_token'  => wp_generate_password( 32, false ),
			)
		);
	}

	/**
	 * Ends an interactive run: its (remaining) orders are released, the export stays available.
	 *
	 * @param Run $run
	 *
	 * @return Run
	 */
	public static function finish_interactive( $run ) {
		if ( ! $run->is_interactive() || ! $run->is_active() ) {
			return $run;
		}

		// Steps at the end of the run (e.g. a shipment list) for the orders completed within it.
		$finished = RunSteps::finish( $run, 'interactive' );
		$run      = $finished['run'];

		foreach ( Queue::get_by_token( $run->lock_token ) as $item ) {
			// Still reserved when the run ends: put back (counted like orders put back during the run).
			RunItems::set_status( $run->get_id(), $item->get_id(), 'released' );
			Run::update( $run->get_id(), array( 'increment' => array( 'processed', 'released' ) ) );
			Queue::release( $item->get_id(), $run->lock_token );
		}

		return Run::update(
			$run->get_id(),
			array(
				'status'     => 'completed',
				'lock_token' => '',
			)
		);
	}

	/**
	 * Ends interactive runs nobody worked on for a while (e.g. the browser was closed), see the daily cleanup.
	 */
	public static function finish_idle_interactive() {
		/**
		 * Filter to adjust the number of hours after which an idle interactive fulfillment run is finished.
		 *
		 * @param int $hours
		 *
		 * @package Vendidero/Shiptastic
		 */
		$hours = max( 1, absint( apply_filters( 'woocommerce_shiptastic_fulfillment_interactive_run_idle_hours', 12 ) ) );
		$runs  = Run::query(
			array(
				'source'          => 'interactive',
				'status'          => array( 'running' ),
				'modified_before' => gmdate( 'Y-m-d H:i:s', time() - $hours * HOUR_IN_SECONDS ),
				'limit'           => 200,
			)
		);

		foreach ( $runs as $run ) {
			self::finish_interactive( $run );
		}
	}
}
