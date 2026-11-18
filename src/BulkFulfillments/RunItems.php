<?php

namespace Vendidero\Shiptastic\BulkFulfillments;

defined( 'ABSPATH' ) || exit;

/**
 * The orders (queue items) of a run: the batch they were claimed with (each claim of a run is a batch, see
 * RunSteps) and how they ended within the run (completed, failed, released). Steps running at the end of a run cover
 * its completed orders.
 */
class RunItems {

	const STATUSES = array( 'claimed', 'completed', 'failed', 'released' );

	protected static function now() {
		return gmdate( 'Y-m-d H:i:s' );
	}

	/**
	 * Adds claimed queue items to a run as a new batch.
	 *
	 * @param int $run_id
	 * @param int[] $queue_ids
	 *
	 * @return int The batch number (1, 2, ...), 0 in case no items were added.
	 */
	public static function add_batch( $run_id, $queue_ids ) {
		global $wpdb;

		$queue_ids = array_values( array_unique( array_filter( array_map( 'absint', (array) $queue_ids ) ) ) );

		if ( empty( $queue_ids ) || ! $run_id ) {
			return 0;
		}

		$batch = absint( $wpdb->get_var( $wpdb->prepare( "SELECT MAX(run_item_batch) FROM {$wpdb->stc_fulfillment_run_items} WHERE run_item_run_id = %d", $run_id ) ) ) + 1;

		foreach ( $queue_ids as $queue_id ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->query(
				$wpdb->prepare(
					"INSERT INTO {$wpdb->stc_fulfillment_run_items} (run_item_run_id, run_item_queue_id, run_item_batch, run_item_status, run_item_date_modified_gmt) VALUES (%d, %d, %d, 'claimed', %s)
					ON DUPLICATE KEY UPDATE run_item_batch = VALUES(run_item_batch), run_item_status = 'claimed', run_item_date_modified_gmt = VALUES(run_item_date_modified_gmt)",
					$run_id,
					$queue_id,
					$batch,
					self::now()
				)
			);
		}

		return $batch;
	}

	/**
	 * @param int $run_id
	 * @param int $queue_id
	 * @param string $status completed|failed|released
	 */
	public static function set_status( $run_id, $queue_id, $status ) {
		global $wpdb;

		if ( ! $run_id || ! $queue_id || ! in_array( $status, self::STATUSES, true ) ) {
			return;
		}

		$wpdb->update(
			$wpdb->stc_fulfillment_run_items,
			array(
				'run_item_status'            => $status,
				'run_item_date_modified_gmt' => self::now(),
			),
			array(
				'run_item_run_id'   => absint( $run_id ),
				'run_item_queue_id' => absint( $queue_id ),
			)
		);
	}

	/**
	 * @param int $run_id
	 * @param array $args batch (int), status (string|string[])
	 *
	 * @return int[]
	 */
	public static function get_queue_ids( $run_id, $args = array() ) {
		global $wpdb;

		$args  = wp_parse_args(
			$args,
			array(
				'batch'  => null,
				'status' => null,
			)
		);
		$where = $wpdb->prepare( 'run_item_run_id = %d', $run_id );

		if ( ! is_null( $args['batch'] ) ) {
			$where .= $wpdb->prepare( ' AND run_item_batch = %d', $args['batch'] );
		}

		if ( ! is_null( $args['status'] ) ) {
			$statuses = array_values( array_intersect( (array) $args['status'], self::STATUSES ) );

			if ( empty( $statuses ) ) {
				return array();
			}

			$where .= " AND run_item_status IN ('" . implode( "','", array_map( 'esc_sql', $statuses ) ) . "')";
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		return array_map( 'absint', $wpdb->get_col( "SELECT run_item_queue_id FROM {$wpdb->stc_fulfillment_run_items} WHERE {$where} ORDER BY run_item_id ASC" ) );
	}

	/**
	 * @param int $run_id
	 * @param int $queue_id
	 *
	 * @return int The batch the item was (last) claimed with, 0 in case it is not part of the run.
	 */
	public static function get_batch( $run_id, $queue_id ) {
		global $wpdb;

		return absint( $wpdb->get_var( $wpdb->prepare( "SELECT run_item_batch FROM {$wpdb->stc_fulfillment_run_items} WHERE run_item_run_id = %d AND run_item_queue_id = %d", $run_id, $queue_id ) ) );
	}

	/**
	 * Records a step executed for an order within a run (the report lists the steps per order).
	 *
	 * @param int $run_id
	 * @param int $queue_id
	 * @param Step $step
	 * @param StepResult $result
	 */
	public static function add_step( $run_id, $queue_id, $step, $result ) {
		global $wpdb;

		$steps = self::get_steps( $run_id, $queue_id );

		if ( is_null( $steps ) ) {
			return;
		}

		$action  = $step->get_action();
		$steps[] = array(
			'key'    => $step->get_key(),
			'title'  => $action ? $action::get_title() : $step->action,
			'mode'   => $step->mode,
			'status' => $result->is_error() ? 'error' : $result->get_status(),
			'error'  => $result->is_error() ? $result->get_message() : '',
			'time'   => time(),
		);

		$wpdb->update(
			$wpdb->stc_fulfillment_run_items,
			array(
				'run_item_steps'             => wp_json_encode( $steps ),
				'run_item_date_modified_gmt' => self::now(),
			),
			array(
				'run_item_run_id'   => absint( $run_id ),
				'run_item_queue_id' => absint( $queue_id ),
			)
		);
	}

	/**
	 * @param int $run_id
	 * @param int $queue_id
	 *
	 * @return array|null Steps recorded for the order within the run, null in case the order is not part of the run.
	 */
	protected static function get_steps( $run_id, $queue_id ) {
		global $wpdb;

		$row = $wpdb->get_row( $wpdb->prepare( "SELECT run_item_steps FROM {$wpdb->stc_fulfillment_run_items} WHERE run_item_run_id = %d AND run_item_queue_id = %d", $run_id, $queue_id ) );

		if ( ! $row ) {
			return null;
		}

		$steps = json_decode( (string) $row->run_item_steps, true );

		return is_array( $steps ) ? $steps : array();
	}

	/**
	 * @param int $run_id
	 *
	 * @return array[] The run's orders (queue_id, batch, status, steps, date_modified), in the order they were claimed.
	 */
	public static function get_items( $run_id ) {
		global $wpdb;

		return array_map(
			function ( $row ) {
				$steps = json_decode( (string) $row->run_item_steps, true );

				return array(
					'queue_id'      => absint( $row->run_item_queue_id ),
					'batch'         => absint( $row->run_item_batch ),
					'status'        => $row->run_item_status,
					'steps'         => is_array( $steps ) ? $steps : array(),
					'date_modified' => $row->run_item_date_modified_gmt,
				);
			},
			$wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->stc_fulfillment_run_items} WHERE run_item_run_id = %d ORDER BY run_item_id ASC", $run_id ) )
		);
	}

	public static function delete_for_run( $run_id ) {
		global $wpdb;

		$wpdb->delete( $wpdb->stc_fulfillment_run_items, array( 'run_item_run_id' => absint( $run_id ) ) );
	}
}
