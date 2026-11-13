<?php

namespace Vendidero\Shiptastic\BulkFulfillments;

defined( 'ABSPATH' ) || exit;

/**
 * Per queue item action state, shared across workflows: an action done by one workflow (e.g. create_shipments)
 * is considered done for every other workflow, too. Shipment scope actions are tracked per shipment.
 */
class StepLog {

	public static function get_statuses() {
		return array( 'open', 'done', 'error', 'skipped' );
	}

	/**
	 * Records (inserts or updates) the state of an action for a queue item.
	 *
	 * @param int $queue_id
	 * @param string $action
	 * @param array $args status, shipment_id, data, workflow_id, user_id, error
	 *
	 * @return bool
	 */
	public static function record( $queue_id, $action, $args = array() ) {
		global $wpdb;

		$args = wp_parse_args(
			$args,
			array(
				'status'      => 'done',
				'shipment_id' => 0,
				'data'        => array(),
				'workflow_id' => 0,
				'user_id'     => get_current_user_id(),
				'error'       => '',
			)
		);

		$status = in_array( $args['status'], self::get_statuses(), true ) ? $args['status'] : 'open';

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$result = $wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$wpdb->stc_fulfillment_steps} ( step_queue_id, step_action, step_shipment_id, step_status, step_data, step_workflow_id, step_user_id, step_error, step_date_modified_gmt )
				VALUES ( %d, %s, %d, %s, %s, %d, %d, %s, %s )
				ON DUPLICATE KEY UPDATE step_status = VALUES( step_status ), step_data = VALUES( step_data ), step_workflow_id = VALUES( step_workflow_id ), step_user_id = VALUES( step_user_id ), step_error = VALUES( step_error ), step_date_modified_gmt = VALUES( step_date_modified_gmt )",
				absint( $queue_id ),
				sanitize_key( $action ),
				absint( $args['shipment_id'] ),
				$status,
				wp_json_encode( (array) $args['data'] ),
				absint( $args['workflow_id'] ),
				absint( $args['user_id'] ),
				(string) $args['error'],
				gmdate( 'Y-m-d H:i:s' )
			)
		);

		return false !== $result;
	}

	/**
	 * @param int $queue_id
	 *
	 * @return array[] Entries: action, shipment_id, status, data, workflow_id, user_id, error, date_modified_gmt
	 */
	public static function get_entries( $queue_id ) {
		global $wpdb;

		$rows    = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->stc_fulfillment_steps} WHERE step_queue_id = %d ORDER BY step_id ASC", absint( $queue_id ) ) );
		$entries = array();

		foreach ( $rows as $row ) {
			$entries[] = array(
				'action'            => $row->step_action,
				'shipment_id'       => absint( $row->step_shipment_id ),
				'status'            => $row->step_status,
				'data'              => (array) json_decode( (string) $row->step_data, true ),
				'workflow_id'       => absint( $row->step_workflow_id ),
				'user_id'           => absint( $row->step_user_id ),
				'error'             => (string) $row->step_error,
				'date_modified_gmt' => $row->step_date_modified_gmt,
			);
		}

		return $entries;
	}

	/**
	 * @param int $queue_id
	 *
	 * @return string[] Actions done (order scope) for the queue item.
	 */
	public static function get_done_actions( $queue_id ) {
		global $wpdb;

		return $wpdb->get_col( $wpdb->prepare( "SELECT step_action FROM {$wpdb->stc_fulfillment_steps} WHERE step_queue_id = %d AND step_shipment_id = 0 AND step_status = 'done'", absint( $queue_id ) ) );
	}

	/**
	 * Deletes (reopens) a step entry.
	 *
	 * @param int $queue_id
	 * @param string $action
	 * @param int $shipment_id
	 */
	public static function delete( $queue_id, $action, $shipment_id = 0 ) {
		global $wpdb;

		$wpdb->delete(
			$wpdb->stc_fulfillment_steps,
			array(
				'step_queue_id'    => absint( $queue_id ),
				'step_action'      => $action,
				'step_shipment_id' => absint( $shipment_id ),
			),
			array( '%d', '%s', '%d' )
		);
	}

	public static function delete_for_queue_item( $queue_id ) {
		global $wpdb;

		$wpdb->delete( $wpdb->stc_fulfillment_steps, array( 'step_queue_id' => absint( $queue_id ) ), array( '%d' ) );
	}
}
