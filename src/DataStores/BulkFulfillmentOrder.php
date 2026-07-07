<?php

namespace Vendidero\Shiptastic\DataStores;

use WC_Data_Store_WP;
use WC_Object_Data_Store_Interface;
use Exception;
use WC_Data;

defined( 'ABSPATH' ) || exit;

class BulkFulfillmentOrder extends WC_Data_Store_WP implements WC_Object_Data_Store_Interface {

	/**
	 * Data stored in meta keys, but not considered "meta" for an order.
	 *
	 * @var array
	 */
	protected $internal_meta_keys = array();

	protected $core_props = array(
		'id',
		'order_id',
		'fulfillment_id',
		'current_shipment_id',
		'current_action_name',
		'date_locked',
		'locked_by',
		'status',
		'action_data',
	);

	protected $meta_type = 'stc_bulk_fulfillment_order';

	/*
	|--------------------------------------------------------------------------
	| CRUD Methods
	|--------------------------------------------------------------------------
	*/

	/**
	 * Method to create a new fulfillment order in the database.
	 *
	 * @param \Vendidero\Shiptastic\BulkFulfillments\BulkFulfillmentOrder $fulfillment_order Fulfillmnent order object.
	 */
	public function create( &$fulfillment_order ) {
		global $wpdb;

		$data = array(
			'fulfillment_order_order_id'            => $fulfillment_order->get_order_id( 'edit' ),
			'fulfillment_order_fulfillment_id'      => $fulfillment_order->get_fulfillment_id( 'edit' ),
			'fulfillment_order_current_shipment_id' => $fulfillment_order->get_current_shipment_id( 'edit' ),
			'fulfillment_order_current_action_name' => $fulfillment_order->get_current_action_name( 'edit' ),
			'fulfillment_order_date_locked_gmt'     => $this->get_mysql_date_gmt( $fulfillment_order->get_date_locked( 'edit' ) ),
			'fulfillment_order_locked_by'           => $fulfillment_order->get_locked_by( 'edit' ),
			'fulfillment_order_status'              => $fulfillment_order->get_status( 'edit' ),
			'fulfillment_order_action_data'         => empty( $fulfillment_order->get_action_data( 'edit' ) ) ? '' : maybe_serialize( $fulfillment_order->get_action_data( 'edit' ) ),
		);

		$wpdb->insert(
			$wpdb->stc_bulk_fulfillment_orders,
			$data
		);

		$fulfillment_order_id = $wpdb->insert_id;

		if ( $fulfillment_order_id ) {
			$fulfillment_order->set_id( $fulfillment_order_id );

			$this->save_fulfillment_order_data( $fulfillment_order );

			$fulfillment_order->save_meta_data();
			$fulfillment_order->apply_changes();
			$fulfillment_order->set_object_read( true );

			if ( ! doing_action( 'woocommerce_shiptastic_new_bulk_fulfillment_order' ) ) {
				/**
				 * Action that indicates that a new fulfillment order has been created in the DB.
				 *
				 * @param integer                                                     $fulfillment_order_id The fulfillment order id.
				 * @param \Vendidero\Shiptastic\BulkFulfillments\BulkFulfillmentOrder $fulfillment_order The fulfillment order instance.
				 *
				 * @package Vendidero/Shiptastic
				 */
				do_action( 'woocommerce_shiptastic_new_bulk_fulfillment_order', $fulfillment_order_id, $fulfillment_order );
			}
		}
	}

	protected function get_mysql_date_gmt( $value ) {
		return $value ? ( new \DateTime( $value ) )->setTimezone( new \DateTimeZone( '+00:00' ) )->format( 'Y-m-d H:i:s' ) : null;
	}

	/**
	 * Method to update a fulfillment order in the database.
	 *
	 * @param \Vendidero\Shiptastic\BulkFulfillments\BulkFulfillmentOrder $fulfillment_order Fulfillmnent order object.
	 */
	public function update( &$fulfillment_order ) {
		global $wpdb;

		$core_props       = $this->core_props;
		$changed_props    = array_keys( $fulfillment_order->get_changes() );
		$fulfillment_data = array();

		foreach ( $changed_props as $prop ) {
			if ( ! in_array( $prop, $core_props, true ) ) {
				continue;
			}

			switch ( $prop ) {
				case 'date_locked':
					$fulfillment_data[ "fulfillment_order_{$prop}_gmt" ] = $this->get_mysql_date_gmt( $fulfillment_order->{'get_' . $prop}( 'edit' ) );
					break;
				case 'action_data':
					$raw = $fulfillment_order->{'get_' . $prop}( 'edit' );
					$fulfillment_data[ "fulfillment_order_{$prop}" ] = empty( $raw ) ? '' : maybe_serialize( $raw );
					break;
				default:
					if ( is_callable( array( $fulfillment_order, 'get_' . $prop ) ) ) {
						$fulfillment_data[ "fulfillment_order_{$prop}" ] = $fulfillment_order->{'get_' . $prop}( 'edit' );
					}
					break;
			}
		}

		if ( ! empty( $fulfillment_data ) ) {
			$wpdb->update(
				$wpdb->stc_bulk_fulfillment_orders,
				$fulfillment_data,
				array( 'fulfillment_order_id' => $fulfillment_order->get_id() )
			);
		}

		$this->save_fulfillment_order_data( $fulfillment_order );

		$fulfillment_order->save_meta_data();
		$fulfillment_order->apply_changes();

		$fulfillment_order->set_object_read( true );

		if ( ! doing_action( 'woocommerce_shiptastic_bulk_fulfillment_order_updated' ) ) {
			/**
			 * Action that indicates that a new fulfillment order has been updated in the DB.
			 *
			 * @param integer                                                     $fulfillment_order_id The fulfillment order id.
			 * @param \Vendidero\Shiptastic\BulkFulfillments\BulkFulfillmentOrder $fulfillment_order The fulfillment order instance.
			 *
			 * @package Vendidero/Shiptastic
			 */
			do_action( 'woocommerce_shiptastic_bulk_fulfillment_order_updated', $fulfillment_order->get_id(), $fulfillment_order );
		}
	}

	/**
	 * Remove a fulfillment order from the database.
	 *
	 * @param \Vendidero\Shiptastic\BulkFulfillments\BulkFulfillmentOrder $fulfillment_order Fulfillmnent order object.
	 * @param bool                $force_delete Unused param.
	 */
	public function delete( &$fulfillment_order, $force_delete = false ) {
		global $wpdb;

		$wpdb->delete( $wpdb->stc_bulk_fulfillment_orders, array( 'fulfillment_order_id' => $fulfillment_order->get_id() ), array( '%d' ) );
		$wpdb->delete( $wpdb->stc_bulk_fulfillmentmeta, array( 'stc_bulk_fulfillment_order_id' => $fulfillment_order->get_id() ), array( '%d' ) );

		if ( ! doing_action( 'woocommerce_shiptastic_bulk_fulfillment_order_deleted' ) ) {
			/**
			 * Action that indicates that a fulfillment has been deleted from the DB.
			 *
			 * @param integer                                                     $fulfillment_order_id The fulfillment order id.
			 * @param \Vendidero\Shiptastic\BulkFulfillments\BulkFulfillmentOrder $fulfillment_order The fulfillment order object.
			 *
			 * @package Vendidero/Shiptastic
			 */
			do_action( 'woocommerce_shiptastic_bulk_fulfillment_deleted', $fulfillment_order->get_id(), $fulfillment_order );
		}
	}

	/**
	 * Read a fulfillment order from the database.
	 *
	 * @param \Vendidero\Shiptastic\BulkFulfillments\BulkFulfillmentOrder $fulfillment_order Fulfillmnent order object.
	 *
	 * @throws Exception Throw exception if invalid fulfillment order.
	 */
	public function read( &$fulfillment_order, $data = null ) {
		global $wpdb;

		if ( is_null( $data ) ) {
			$data = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT * FROM {$wpdb->stc_bulk_fulfillment_orders} WHERE fulfillment_order_id = %d LIMIT 1",
					$fulfillment_order->get_id()
				)
			);
		}

		if ( $data ) {
			$fulfillment_order->set_props(
				array(
					'order_id'            => $data->fulfillment_order_order_id,
					'fulfillment_id'      => $data->fulfillment_order_fulfillment_id,
					'current_shipment_id' => $data->fulfillment_order_current_shipment_id,
					'current_action_name' => $data->fulfillment_order_current_action_name,
					'date_locked'         => $this->string_to_timestamp( $data->fulfillment_order_date_locked_gmt ),
					'locked_by'           => $data->fulfillment_order_locked_by,
					'status'              => $data->fulfillment_order_status,
					'action_data'         => maybe_unserialize( $data->fulfillment_order_action_data ),
				)
			);

			$this->read_fulfillment_order_data( $fulfillment_order );

			$fulfillment_order->read_meta_data();
			$fulfillment_order->set_object_read( true );

			if ( ! doing_action( 'woocommerce_shiptastic_bulk_fulfillment_order_loaded' ) ) {
				/**
				 * Action that indicates that a fulfillment order has been loaded from DB.
				 *
				 * @param \Vendidero\Shiptastic\BulkFulfillments\BulkFulfillmentOrder $fulfillment_order The fulfillment order object.
				 *
				 * @package Vendidero/Shiptastic
				 */
				do_action( 'woocommerce_shiptastic_bulk_fulfillment_order_loaded', $fulfillment_order );
			}
		} else {
			throw new Exception( esc_html_x( 'Invalid fulfillment order.', 'shipments', 'shiptastic-for-woocommerce' ) );
		}
	}

	/*
	|--------------------------------------------------------------------------
	| Additional Methods
	|--------------------------------------------------------------------------
	*/

	/**
	 * Read extra data associated with the fulfillment order.
	 *
	 * @param \Vendidero\Shiptastic\BulkFulfillments\BulkFulfillmentOrder $fulfillment_order Fulfillmnent order object.
	 */
	protected function read_fulfillment_order_data( &$fulfillment_order ) {
		$props     = array();
		$meta_keys = $this->internal_meta_keys;

		foreach ( $fulfillment_order->get_extra_data_keys() as $key ) {
			$meta_keys[] = '_' . $key;
		}

		foreach ( $meta_keys as $meta_key ) {
			if ( function_exists( 'get_metadata_raw' ) ) {
				$value = get_metadata_raw( $this->meta_type, $fulfillment_order->get_id(), $meta_key, true );
			} else {
				$value = null;

				if ( metadata_exists( $this->meta_type, $fulfillment_order->get_id(), $meta_key ) ) {
					$value = get_metadata( $this->meta_type, $fulfillment_order->get_id(), $meta_key, true );
				}
			}

			if ( ! is_null( $value ) ) {
				$props[ substr( $meta_key, 1 ) ] = $value;
			}
		}

		$fulfillment_order->set_props( $props );
	}

	/**
	 * Save fulfillment order data.
	 *
	 * @param \Vendidero\Shiptastic\BulkFulfillments\BulkFulfillmentOrder $fulfillment_order Fulfillmnent order object.
	 */
	protected function save_fulfillment_order_data( &$fulfillment_order ) {
		$updated_props     = array();
		$meta_key_to_props = array();

		foreach ( $this->internal_meta_keys as $meta_key ) {
			$prop_name = substr( $meta_key, 1 );

			if ( in_array( $prop_name, $this->core_props, true ) ) {
				continue;
			}

			$meta_key_to_props[ $meta_key ] = $prop_name;
		}

		// Make sure to take extra data (like product url or text for external products) into account.
		$extra_data_keys = $fulfillment_order->get_extra_data_keys();

		foreach ( $extra_data_keys as $key ) {
			$meta_key_to_props[ '_' . $key ] = $key;
		}

		$props_to_update = $this->get_props_to_update( $fulfillment_order, $meta_key_to_props, $this->meta_type );

		foreach ( $props_to_update as $meta_key => $prop ) {
			if ( ! is_callable( array( $fulfillment_order, "get_$prop" ) ) ) {
				continue;
			}

			$value = $fulfillment_order->{"get_$prop"}( 'edit' );
			$value = is_string( $value ) ? wp_slash( $value ) : $value;

			if ( is_bool( $value ) ) {
				$value = wc_bool_to_string( $value );
			}

			$updated = $this->update_or_delete_meta( $fulfillment_order, $meta_key, $value );

			if ( $updated ) {
				$updated_props[] = $prop;
			}
		}

		if ( ! doing_action( 'woocommerce_shiptastic_fulfillment_order_object_updated_props' ) ) {
			/**
			 * Action that indicates updating fulfillment order props.
			 *
			 * @param \Vendidero\Shiptastic\BulkFulfillments\BulkFulfillmentOrder $fulfillment_order The fulfillment order instance.
			 * @param array $updated_props The updated props.
			 *
			 * @package Vendidero/Shiptastic
			 */
			do_action( 'woocommerce_shiptastic_fulfillment_order_object_updated_props', $fulfillment_order, $updated_props );
		}
	}

	/**
	 * Update meta data in, or delete it from, the database.
	 *
	 * Avoids storing meta when it's either an empty string or empty array.
	 * Other empty values such as numeric 0 and null should still be stored.
	 * Data-stores can force meta to exist using `must_exist_meta_keys`.
	 *
	 * Note: WordPress `get_metadata` function returns an empty string when meta data does not exist.
	 *
	 * @param WC_Data $fulfillment The WP_Data object (WC_Coupon for coupons, etc).
	 * @param string  $meta_key Meta key to update.
	 * @param mixed   $meta_value Value to save.
	 *
	 * @return bool True if updated/deleted.
	 * @since 3.6.0 Added to prevent empty meta being stored unless required.
	 *
	 */
	protected function update_or_delete_meta( $fulfillment_order, $meta_key, $meta_value ) {
		if ( in_array( $meta_value, array( array(), '' ), true ) && ! in_array( $meta_key, $this->must_exist_meta_keys, true ) ) {
			$updated = delete_metadata( $this->meta_type, $fulfillment_order->get_id(), $meta_key );
		} else {
			$updated = update_metadata( $this->meta_type, $fulfillment_order->get_id(), $meta_key, $meta_value );
		}

		return (bool) $updated;
	}

	/**
	 * Table structure is slightly different between meta types, this function will return what we need to know.
	 *
	 * @return array Array elements: table, object_id_field, meta_id_field
	 */
	protected function get_db_info() {
		global $wpdb;

		$meta_id_field   = 'meta_id'; // for some reason users calls this umeta_id so we need to track this as well.
		$table           = $wpdb->stc_bulk_fulfillment_ordermeta;
		$object_id_field = $this->meta_type . '_id';

		if ( ! empty( $this->object_id_field_for_meta ) ) {
			$object_id_field = $this->object_id_field_for_meta;
		}

		return array(
			'table'           => $table,
			'object_id_field' => $object_id_field,
			'meta_id_field'   => $meta_id_field,
		);
	}
}
