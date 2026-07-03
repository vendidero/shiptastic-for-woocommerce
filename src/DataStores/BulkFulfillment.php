<?php

namespace Vendidero\Shiptastic\DataStores;

use Vendidero\Shiptastic\Package;
use WC_Data_Store_WP;
use WC_DateTime;
use WC_Object_Data_Store_Interface;
use Exception;
use WC_Data;

defined( 'ABSPATH' ) || exit;

class BulkFulfillment extends WC_Data_Store_WP implements WC_Object_Data_Store_Interface {

	/**
	 * Data stored in meta keys, but not considered "meta" for an order.
	 *
	 * @var array
	 */
	protected $internal_meta_keys = array();

	protected $core_props = array(
		'id',
		'date_created',
		'date_modified',
		'date_start',
		'date_end',
		'status',
		'type',
		'filters',
		'actions',
		'current_order',
		'current_action',
		'progress',
		'parent_id',
		'is_initialized',
		'order_count',
		'last_order',
		'first_order',
	);

	protected $meta_type = 'stc_bulk_fulfillment';

	/*
	|--------------------------------------------------------------------------
	| CRUD Methods
	|--------------------------------------------------------------------------
	*/

	/**
	 * Method to create a new fulfillment in the database.
	 *
	 * @param \Vendidero\Shiptastic\BulkFulfillments\BulkFulfillment $fulfillment Fulfillmnent object.
	 */
	public function create( &$fulfillment ) {
		global $wpdb;

		$fulfillment->set_date_created( current_time( 'mysql' ) );
		$fulfillment->set_date_modified( current_time( 'mysql' ) );

		$data = array(
			'fulfillment_date_created_gmt'  => $this->get_mysql_date_gmt( $fulfillment->get_date_created() ),
			'fulfillment_date_modified_gmt' => $this->get_mysql_date_gmt( $fulfillment->get_date_modified() ),
			'fulfillment_date_start_gmt'    => $this->get_mysql_date_gmt( $fulfillment->get_date_start() ),
			'fulfillment_date_end_gmt'      => $this->get_mysql_date_gmt( $fulfillment->get_date_end() ),
			'fulfillment_status'            => $fulfillment->get_status( 'edit' ),
			'fulfillment_type'              => $fulfillment->get_type( 'edit' ),
			'fulfillment_filters'           => empty( $fulfillment->get_filters( 'edit' ) ) ? '' : maybe_serialize( $fulfillment->get_filters( 'edit' ) ),
			'fulfillment_actions'           => empty( $fulfillment->get_filters( 'edit' ) ) ? '' : maybe_serialize( $fulfillment->get_actions( 'edit' ) ),
			'fulfillment_current_order'     => $fulfillment->get_current_order( 'edit' ),
			'fulfillment_current_action'    => $fulfillment->get_current_action( 'edit' ),
			'fulfillment_progress'          => $fulfillment->get_progress( 'edit' ),
			'fulfillment_parent_id'         => $fulfillment->get_parent_id( 'edit' ),
			'fulfillment_is_initialized'    => $fulfillment->get_is_initialized( 'edit' ) ? 1 : 0,
			'fulfillment_order_count'       => $fulfillment->get_order_count( 'edit' ),
			'fulfillment_last_order'        => $fulfillment->get_last_order( 'edit' ),
			'fulfillment_first_order'       => $fulfillment->get_first_order( 'edit' ),
		);

		$wpdb->insert(
			$wpdb->stc_bulk_fulfillments,
			$data
		);

		$fulfillment_id = $wpdb->insert_id;

		if ( $fulfillment_id ) {
			$fulfillment->set_id( $fulfillment_id );

			$this->save_fulfillment_data( $fulfillment );

			$fulfillment->save_meta_data();
			$fulfillment->apply_changes();
			$fulfillment->set_object_read( true );

			if ( ! doing_action( 'woocommerce_shiptastic_new_bulk_fulfillment' ) ) {
				/**
				 * Action that indicates that a new fulfillment has been created in the DB.
				 *
				 * @param integer                                                     $fulfillment_id The fulfillment id.
				 * @param \Vendidero\Shiptastic\BulkFulfillments\BulkFulfillment $fulfillment The fulfillment instance.
				 *
				 * @package Vendidero/Shiptastic
				 */
				do_action( 'woocommerce_shiptastic_new_bulk_fulfillment', $fulfillment_id, $fulfillment );
			}
		}
	}

	protected function get_mysql_date_gmt( $value ) {
		return $value ? ( new \DateTime( $value ) )->setTimezone( new \DateTimeZone( '+00:00' ) )->format( 'Y-m-d H:i:s' ) : null;
	}

	/**
	 * Method to update a shipping provider in the database.
	 *
	 * @param \Vendidero\Shiptastic\BulkFulfillments\BulkFulfillment $fulfillment Fulfillmnent object.
	 */
	public function update( &$fulfillment ) {
		global $wpdb;

		$core_props       = $this->core_props;
		$changed_props    = array_keys( $fulfillment->get_changes() );
		$fulfillment_data = array();

		if ( ! empty( $changed_props ) ) {
			$fulfillment->set_date_modified( current_time( 'mysql' ) );
			$changed_props[] = 'date_modified';
		}

		foreach ( $changed_props as $prop ) {
			if ( ! in_array( $prop, $core_props, true ) ) {
				continue;
			}

			switch ( $prop ) {
				case 'date_created':
				case 'date_modified':
				case 'date_start':
				case 'date_end':
					$fulfillment_data[ "fulfillment_{$prop}_gmt" ] = $this->get_mysql_date_gmt( $fulfillment->{'get_' . $prop}( 'edit' ) );
					break;
				case 'is_initialized':
					$fulfillment_data[ "fulfillment_{$prop}" ] = $fulfillment->get_is_initialized( 'edit' ) ? 1 : 0;
					break;
				case 'filters':
				case 'actions':
					$raw                                       = $fulfillment->{'get_' . $prop}( 'edit' );
					$fulfillment_data[ "fulfillment_{$prop}" ] = empty( $raw ) ? '' : maybe_serialize( $raw );
					break;
				default:
					if ( is_callable( array( $fulfillment, 'get_' . $prop ) ) ) {
						$fulfillment_data[ "fulfillment_{$prop}" ] = $fulfillment->{'get_' . $prop}( 'edit' );
					}
					break;
			}
		}

		if ( ! empty( $fulfillment_data ) ) {
			$wpdb->update(
				$wpdb->stc_bulk_fulfillments,
				$fulfillment_data,
				array( 'fulfillment_id' => $fulfillment->get_id() )
			);
		}

		$this->save_fulfillment_data( $fulfillment );

		$fulfillment->save_meta_data();
		$fulfillment->apply_changes();

		$fulfillment->set_object_read( true );

		if ( ! doing_action( 'woocommerce_shiptastic_bulk_fulfillment_updated' ) ) {
			/**
			 * Action that indicates that a new fulfillment has been updated in the DB.
			 *
			 * @param integer                                                     $fulfillment_id The fulfillment id.
			 * @param \Vendidero\Shiptastic\BulkFulfillments\BulkFulfillment $fulfillment The fulfillment instance.
			 *
			 * @package Vendidero/Shiptastic
			 */
			do_action( 'woocommerce_shiptastic_bulk_fulfillment_updated', $fulfillment->get_id(), $fulfillment );
		}
	}

	/**
	 * Remove a fulfillment from the database.
	 *
	 * @param \Vendidero\Shiptastic\BulkFulfillments\BulkFulfillment $fulfillment Fulfillmnent object.
	 * @param bool                $force_delete Unused param.
	 */
	public function delete( &$fulfillment, $force_delete = false ) {
		global $wpdb;

		$wpdb->delete( $wpdb->stc_bulk_fulfillments, array( 'fulfillment_id' => $fulfillment->get_id() ), array( '%d' ) );
		$wpdb->delete( $wpdb->stc_bulk_fulfillmentmeta, array( 'stc_bulk_fulfillment_id' => $fulfillment->get_id() ), array( '%d' ) );

		$this->delete_orders( $fulfillment->get_id() );

		if ( ! doing_action( 'woocommerce_shiptastic_bulk_fulfillment_deleted' ) ) {
			/**
			 * Action that indicates that a fulfillment has been deleted from the DB.
			 *
			 * @param integer                                                     $fulfillment_id The fulfillment id.
			 * @param \Vendidero\Shiptastic\BulkFulfillments\BulkFulfillment $fulfillment The fulfillment object.
			 *
			 * @package Vendidero/Shiptastic
			 */
			do_action( 'woocommerce_shiptastic_bulk_fulfillment_deleted', $fulfillment->get_id(), $fulfillment );
		}
	}

	/**
	 * Read a fulfillment from the database.
	 *
	 * @param \Vendidero\Shiptastic\BulkFulfillments\BulkFulfillment $fulfillment Fulfillmnent object.
	 *
	 * @throws Exception Throw exception if invalid shipping provider.
	 */
	public function read( &$fulfillment ) {
		global $wpdb;

		$data = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->stc_bulk_fulfillments} WHERE fulfillment_id = %d LIMIT 1",
				$fulfillment->get_id()
			)
		);

		if ( $data ) {
			$fulfillment->set_props(
				array(
					'date_created'   => $this->string_to_timestamp( $data->fulfillment_date_created_gmt ),
					'date_modified'  => $this->string_to_timestamp( $data->fulfillment_date_modified_gmt ),
					'date_start'     => $this->string_to_timestamp( $data->fulfillment_date_start_gmt ),
					'date_end'       => $this->string_to_timestamp( $data->fulfillment_date_end_gmt ),
					'status'         => $data->fulfillment_status,
					'type'           => $data->fulfillment_type,
					'filters'        => maybe_unserialize( $data->fulfillment_filters ),
					'actions'        => maybe_unserialize( $data->fulfillment_actions ),
					'current_order'  => $data->fulfillment_current_order,
					'current_action' => $data->fulfillment_current_action,
					'progress'       => $data->fulfillment_progress,
					'parent_id'      => $data->fulfillment_parent_id,
					'is_initialized' => $data->fulfillment_is_initialized,
					'order_count'    => $data->fulfillment_order_count,
					'last_order'     => $data->fulfillment_last_order,
					'first_order'    => $data->fulfillment_first_order,
				)
			);

			$this->read_fulfillment_data( $fulfillment );

			$fulfillment->read_meta_data();
			$fulfillment->set_object_read( true );

			if ( ! doing_action( 'woocommerce_shiptastic_bulk_fulfillment_loaded' ) ) {
				/**
				 * Action that indicates that a shipping provider has been loaded from DB.
				 *
				 * @param \Vendidero\Shiptastic\BulkFulfillments\BulkFulfillment $fulfillment The fulfillment object.
				 *
				 * @package Vendidero/Shiptastic
				 */
				do_action( 'woocommerce_shiptastic_bulk_fulfillment_loaded', $fulfillment );
			}
		} else {
			throw new Exception( esc_html_x( 'Invalid fulfillment.', 'shipments', 'shiptastic-for-woocommerce' ) );
		}
	}

	/*
	|--------------------------------------------------------------------------
	| Additional Methods
	|--------------------------------------------------------------------------
	*/

	/**
	 * Read extra data associated with the shipping provider.
	 *
	 * @param \Vendidero\Shiptastic\BulkFulfillments\BulkFulfillment $fulfillment Fulfillmnent object.
	 */
	protected function read_fulfillment_data( &$fulfillment ) {
		$props     = array();
		$meta_keys = $this->internal_meta_keys;

		foreach ( $fulfillment->get_extra_data_keys() as $key ) {
			$meta_keys[] = '_' . $key;
		}

		foreach ( $meta_keys as $meta_key ) {
			if ( function_exists( 'get_metadata_raw' ) ) {
				$value = get_metadata_raw( $this->meta_type, $fulfillment->get_id(), $meta_key, true );
			} else {
				$value = null;

				if ( metadata_exists( $this->meta_type, $fulfillment->get_id(), $meta_key ) ) {
					$value = get_metadata( $this->meta_type, $fulfillment->get_id(), $meta_key, true );
				}
			}

			if ( ! is_null( $value ) ) {
				$props[ substr( $meta_key, 1 ) ] = $value;
			}
		}

		$fulfillment->set_props( $props );
	}

	/**
	 * Save shipping provider data.
	 *
	 * @param \Vendidero\Shiptastic\BulkFulfillments\BulkFulfillment $fulfillment Fulfillmnent object.
	 */
	protected function save_fulfillment_data( &$fulfillment ) {
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
		$extra_data_keys = $fulfillment->get_extra_data_keys();

		foreach ( $extra_data_keys as $key ) {
			$meta_key_to_props[ '_' . $key ] = $key;
		}

		$props_to_update = $this->get_props_to_update( $fulfillment, $meta_key_to_props, $this->meta_type );

		foreach ( $props_to_update as $meta_key => $prop ) {
			if ( ! is_callable( array( $fulfillment, "get_$prop" ) ) ) {
				continue;
			}

			$value = $fulfillment->{"get_$prop"}( 'edit' );
			$value = is_string( $value ) ? wp_slash( $value ) : $value;

			if ( is_bool( $value ) ) {
				$value = wc_bool_to_string( $value );
			}

			$updated = $this->update_or_delete_meta( $fulfillment, $meta_key, $value );

			if ( $updated ) {
				$updated_props[] = $prop;
			}
		}

		if ( ! doing_action( 'woocommerce_shiptastic_fulfillment_object_updated_props' ) ) {
			/**
			 * Action that indicates updating fulfillment props.
			 *
			 * @param \Vendidero\Shiptastic\BulkFulfillments\BulkFulfillment $fulfillment The fulfillment instance.
			 * @param array $updated_props The updated props.
			 *
			 * @package Vendidero/Shiptastic
			 */
			do_action( 'woocommerce_shiptastic_fulfillment_object_updated_props', $fulfillment, $updated_props );
		}
	}

	private static function generate_in_query_sql( $values ) {
		global $wpdb;

		$in_query = array();

		foreach ( $values as $value ) {
			$in_query[] = $wpdb->prepare( "'%s'", $value ); // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.QuotedSimplePlaceholder
		}

		return '(' . implode( ',', $in_query ) . ')';
	}

	public function get_order_count( $fulfillment_id ) {
		global $wpdb;

		return absint( $wpdb->get_var( $wpdb->prepare( "SELECT COUNT( * ) FROM {$wpdb->stc_bulk_fulfillment_orders} WHERE fulfillment_order_fulfillment_id = %d", $fulfillment_id ) ) );
	}

	public function delete_orders( $fulfillment_id ) {
		global $wpdb;

		$wpdb->delete( $wpdb->stc_bulk_fulfillment_orders, array( 'fulfillment_order_fulfillment_id' => $fulfillment_id ), array( '%d' ) );
	}

	public function get_orders( $fulfillment_id, $args = array() ) {
		global $wpdb;

		$args = wp_parse_args(
			$args,
			array(
				'limit'  => 10,
				'before' => -1,
				'after'  => -1,
				'id'     => -1,
			)
		);

		$where = '';

		if ( -1 !== $args['before'] ) {
			$where .= $wpdb->prepare( ' AND (fulfillment_order_id < %d)', $args['before'] ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		} elseif ( -1 !== $args['after'] ) {
			$where .= $wpdb->prepare( ' AND (fulfillment_order_id > %d)', $args['after'] ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		} elseif ( -1 !== $args['id'] ) {
			$where .= $wpdb->prepare( ' AND (fulfillment_order_id >= %d)', $args['id'] ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}

		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->stc_bulk_fulfillment_orders} WHERE fulfillment_order_fulfillment_id = %d {$where} ORDER BY fulfillment_order_id ASC LIMIT %d", $fulfillment_id, $args['limit'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	public function get_first_order_id( $fulfillment_id ) {
		global $wpdb;

		return absint( $wpdb->get_var( $wpdb->prepare( "SELECT fulfillment_order_id AS order_id FROM {$wpdb->stc_bulk_fulfillment_orders} WHERE fulfillment_order_fulfillment_id = %d ORDER BY fulfillment_order_id ASC LIMIT 1", $fulfillment_id ) ) );
	}

	public function get_last_order_id( $fulfillment_id ) {
		global $wpdb;

		return absint( $wpdb->get_var( $wpdb->prepare( "SELECT fulfillment_order_id AS order_id FROM {$wpdb->stc_bulk_fulfillment_orders} WHERE fulfillment_order_fulfillment_id = %d ORDER BY fulfillment_order_id DESC LIMIT 1", $fulfillment_id ) ) );
	}

	public function fetch_orders( $args ) {
		global $wpdb;

		$args = wp_parse_args(
			$args,
			array(
				'limit'           => 50,
				'offset'          => 0,
				'date_start'      => null,
				'date_end'        => null,
				'order'           => 'ASC',
				'shipping_status' => array_keys( wc_stc_get_shipment_order_shipping_statuses() ),
			)
		);

		$args['order']    = in_array( strtoupper( $args['order'] ), array( 'ASC', 'DESC' ), true ) ? strtoupper( $args['order'] ) : 'ASC';
		$args['date_end'] = is_null( $args['date_end'] ) ? new \WC_DateTime() : $args['date_end'];

		$shipping_status_in = self::generate_in_query_sql( $args['shipping_status'] );

		if ( Package::is_hpos_enabled() ) {
			$orders_table_name = \Automattic\WooCommerce\Internal\DataStores\Orders\OrdersTableDataStore::get_orders_table_name();
			$meta_table_name   = \Automattic\WooCommerce\Internal\DataStores\Orders\OrdersTableDataStore::get_meta_table_name();

			$joins = array(
				"INNER JOIN {$meta_table_name} AS mt0 ON {$orders_table_name}.id = mt0.order_id AND mt0.meta_key = '_shipping_status'",
			);

			$where_date_sql = '';

			if ( $args['date_start'] ) {
				$where_date_sql = $wpdb->prepare( " AND ({$orders_table_name}.date_created_gmt >= %s)", $this->get_mysql_date_gmt( $args['date_start'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			}

			$where_date_sql .= $wpdb->prepare( " AND ({$orders_table_name}.date_created_gmt <= %s)", $this->get_mysql_date_gmt( $args['date_end'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

			$join_sql = implode( ' ', $joins );

			// @codingStandardsIgnoreStart
			$sql = $wpdb->prepare(
				"
			SELECT {$orders_table_name}.id as order_id FROM {$orders_table_name}  
			$join_sql
			WHERE 1=1 
				AND ( {$orders_table_name}.type = 'shop_order' ) AND ( mt0.meta_value IN {$shipping_status_in} ) {$where_date_sql}
			GROUP BY {$orders_table_name}.id 
			ORDER BY {$orders_table_name}.id %s 
			LIMIT %d, %d",
				$args['order'],
				$args['offset'],
				$args['limit']
			);
			// @codingStandardsIgnoreEnd
		} else {
			$joins = array(
				"INNER JOIN {$wpdb->postmeta} AS mt0 ON {$wpdb->posts}.ID = mt0.post_id AND (mt0.meta_key = '_shipping_status')",
			);

			$join_sql = implode( ' ', $joins );

			$where_date_sql = '';

			if ( $args['date_start'] ) {
				$where_date_sql = $wpdb->prepare( " AND ({$wpdb->posts}.post_date_gmt >= %s)", $this->get_mysql_date_gmt( $args['date_start'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			}

			$where_date_sql .= $wpdb->prepare( " AND ({$wpdb->posts}.post_date_gmt <= %s)", $this->get_mysql_date_gmt( $args['date_end'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

			// @codingStandardsIgnoreStart
			$sql = $wpdb->prepare(
				"
			SELECT {$wpdb->posts}.ID as order_id FROM {$wpdb->posts}  
			$join_sql
			WHERE 1=1 
				AND ( {$wpdb->posts}.post_type = 'shop_order' ) AND ( mt0.meta_value IN {$shipping_status_in} ) {$where_date_sql}
			GROUP BY {$wpdb->posts}.ID 
			ORDER BY {$wpdb->posts}.ID %s 
			LIMIT %d, %d",
				$args['order'],
				$args['offset'],
				$args['limit']
			);
			// @codingStandardsIgnoreEnd
		}

		$results = $wpdb->get_results( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		return $results;
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
	protected function update_or_delete_meta( $fulfillment, $meta_key, $meta_value ) {
		if ( in_array( $meta_value, array( array(), '' ), true ) && ! in_array( $meta_key, $this->must_exist_meta_keys, true ) ) {
			$updated = delete_metadata( $this->meta_type, $fulfillment->get_id(), $meta_key );
		} else {
			$updated = update_metadata( $this->meta_type, $fulfillment->get_id(), $meta_key, $meta_value );
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
		$table           = $wpdb->stc_bulk_fulfillmentmeta;
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
