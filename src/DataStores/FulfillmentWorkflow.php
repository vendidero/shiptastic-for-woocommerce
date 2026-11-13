<?php

namespace Vendidero\Shiptastic\DataStores;

use Vendidero\Shiptastic\BulkFulfillments\Workflow;
use Vendidero\Shiptastic\Package;
use WC_Data_Store_WP;
use WC_Object_Data_Store_Interface;
use Exception;

defined( 'ABSPATH' ) || exit;

/**
 * Fulfillment workflow data store.
 */
class FulfillmentWorkflow extends WC_Data_Store_WP implements WC_Object_Data_Store_Interface {

	protected $meta_type = 'stc_fulfillment_workflow';

	protected $internal_meta_keys = array(
		'_on_error',
		'_run_as',
		'_schedule',
	);

	protected $core_props = array(
		'title',
		'status',
		'priority',
		'mode',
		'filters',
		'steps',
		'date_created',
		'date_modified',
	);

	protected function get_core_data( $workflow ) {
		return array(
			'workflow_title'             => $workflow->get_title( 'edit' ),
			'workflow_status'            => $workflow->get_status( 'edit' ),
			'workflow_priority'          => $workflow->get_priority( 'edit' ),
			'workflow_mode'              => $workflow->get_mode( 'edit' ),
			'workflow_filters'           => wp_json_encode( $workflow->get_filters( 'edit' ) ),
			'workflow_steps'             => wp_json_encode( $workflow->get_steps( 'edit' ) ),
			'workflow_date_created_gmt'  => $workflow->get_date_created( 'edit' ) ? gmdate( 'Y-m-d H:i:s', $workflow->get_date_created( 'edit' )->getTimestamp() ) : null,
			'workflow_date_modified_gmt' => $workflow->get_date_modified( 'edit' ) ? gmdate( 'Y-m-d H:i:s', $workflow->get_date_modified( 'edit' )->getTimestamp() ) : null,
		);
	}

	/**
	 * @param Workflow $workflow
	 */
	public function create( &$workflow ) {
		global $wpdb;

		$workflow->set_date_created( time() );
		$workflow->set_date_modified( time() );

		$wpdb->insert( $wpdb->stc_fulfillment_workflows, $this->get_core_data( $workflow ) );

		if ( $workflow_id = $wpdb->insert_id ) {
			$workflow->set_id( $workflow_id );

			$this->save_internal_meta( $workflow );
			$workflow->save_meta_data();
			$workflow->apply_changes();

			self::clear_caches();

			do_action( 'woocommerce_shiptastic_new_fulfillment_workflow', $workflow_id, $workflow );
		}
	}

	/**
	 * @param Workflow $workflow
	 */
	public function update( &$workflow ) {
		global $wpdb;

		$changes = array_intersect( array_keys( $workflow->get_changes() ), $this->core_props );

		if ( ! empty( $changes ) || ! empty( $workflow->get_changes() ) ) {
			$workflow->set_date_modified( time() );
			$wpdb->update( $wpdb->stc_fulfillment_workflows, $this->get_core_data( $workflow ), array( 'workflow_id' => $workflow->get_id() ) );
		}

		$this->save_internal_meta( $workflow );
		$workflow->save_meta_data();
		$workflow->apply_changes();

		self::clear_caches();

		do_action( 'woocommerce_shiptastic_fulfillment_workflow_updated', $workflow->get_id(), $workflow );
	}

	/**
	 * @param Workflow $workflow
	 * @param array $args
	 */
	public function delete( &$workflow, $args = array() ) {
		global $wpdb;

		$workflow_id = $workflow->get_id();

		$wpdb->delete( $wpdb->stc_fulfillment_workflows, array( 'workflow_id' => $workflow_id ), array( '%d' ) );
		$wpdb->delete( $wpdb->stc_fulfillment_workflowmeta, array( 'stc_fulfillment_workflow_id' => $workflow_id ), array( '%d' ) );

		$workflow->set_id( 0 );
		self::clear_caches();

		do_action( 'woocommerce_shiptastic_fulfillment_workflow_deleted', $workflow_id, $workflow );
	}

	/**
	 * @param Workflow $workflow
	 *
	 * @throws Exception
	 */
	public function read( &$workflow ) {
		global $wpdb;

		$data = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->stc_fulfillment_workflows} WHERE workflow_id = %d LIMIT 1", $workflow->get_id() ) );

		if ( ! $data ) {
			throw new Exception( esc_html_x( 'Invalid workflow.', 'shipments', 'shiptastic-for-woocommerce' ) );
		}

		$workflow->set_props(
			array(
				'title'         => $data->workflow_title,
				'status'        => $data->workflow_status,
				'priority'      => $data->workflow_priority,
				'mode'          => $data->workflow_mode,
				'filters'       => (array) json_decode( (string) $data->workflow_filters, true ),
				'steps'         => (array) json_decode( (string) $data->workflow_steps, true ),
				'date_created'  => Package::is_valid_mysql_date( $data->workflow_date_created_gmt ) ? wc_string_to_timestamp( $data->workflow_date_created_gmt ) : null,
				'date_modified' => Package::is_valid_mysql_date( $data->workflow_date_modified_gmt ) ? wc_string_to_timestamp( $data->workflow_date_modified_gmt ) : null,
			)
		);

		$props = array();

		foreach ( $this->internal_meta_keys as $meta_key ) {
			$value = get_metadata( $this->meta_type, $workflow->get_id(), $meta_key, true );

			if ( '' !== $value ) {
				$props[ substr( $meta_key, 1 ) ] = $value;
			}
		}

		$workflow->set_props( $props );
		$workflow->read_meta_data();
		$workflow->set_object_read( true );
	}

	/**
	 * @param Workflow $workflow
	 */
	protected function save_internal_meta( $workflow ) {
		foreach ( $this->internal_meta_keys as $meta_key ) {
			$getter = 'get' . $meta_key;
			$value  = $workflow->$getter( 'edit' );

			if ( in_array( $value, array( '', array(), null ), true ) ) {
				delete_metadata( $this->meta_type, $workflow->get_id(), $meta_key );
			} else {
				update_metadata( $this->meta_type, $workflow->get_id(), $meta_key, $value );
			}
		}
	}

	public static function clear_caches() {
		wp_cache_delete( 'active-workflows', 'shiptastic-fulfillment-workflow' );
	}

	/**
	 * @return int[] Ids of active workflows ordered by priority (desc).
	 */
	public static function get_active_workflow_ids() {
		global $wpdb;

		$ids = wp_cache_get( 'active-workflows', 'shiptastic-fulfillment-workflow' );

		if ( false === $ids ) {
			$ids = array_map( 'absint', $wpdb->get_col( "SELECT workflow_id FROM {$wpdb->stc_fulfillment_workflows} WHERE workflow_status = 'active' ORDER BY workflow_priority DESC, workflow_id ASC" ) );

			wp_cache_set( 'active-workflows', $ids, 'shiptastic-fulfillment-workflow' );
		}

		return $ids;
	}

	protected function get_db_info() {
		global $wpdb;

		return array(
			'table'           => $wpdb->stc_fulfillment_workflowmeta,
			'object_id_field' => $this->meta_type . '_id',
			'meta_id_field'   => 'meta_id',
		);
	}
}
