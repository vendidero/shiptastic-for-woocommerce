<?php

namespace Vendidero\Shiptastic\DataStores;

use Vendidero\Shiptastic\Package;
use Vendidero\Shiptastic\Warehouse\Helper;
use WC_Data_Store_WP;
use WC_Object_Data_Store_Interface;
use Exception;
use WC_Data;

defined( 'ABSPATH' ) || exit;

/**
 * Abstract Order Data Store: Stored in CPT.
 *
 * @version  3.0.0
 */
class Warehouse extends WC_Data_Store_WP implements WC_Object_Data_Store_Interface {

	protected $must_exist_meta_keys = array();

	protected $core_props = array(
		'type',
		'name',
		'title',
		'description',
		'date_created',
		'date_created_gmt',
		'order',
	);

	/**
	 * Data stored in meta keys, but not considered "meta" for a packaging.
	 *
	 * @var array
	 */
	protected $internal_meta_keys = array();

	protected $meta_type = 'stc_warehouse';

	/*
	|--------------------------------------------------------------------------
	| CRUD Methods
	|--------------------------------------------------------------------------
	*/

	/**
	 * Method to create a new warehouse in the database.
	 *
	 * @param \Vendidero\Shiptastic\Warehouse $warehouse Warehouse object.
	 */
	public function create( &$warehouse ) {
		global $wpdb;

		$warehouse->set_date_created( time() );
		$warehouse->set_name( $this->get_unqiue_name( $warehouse ) );

		if ( empty( $warehouse->get_type( 'edit' ) ) ) {
			$warehouse->set_type( 'self' );
		}

		$data = array(
			'warehouse_type'             => $warehouse->get_type( 'edit' ),
			'warehouse_description'      => $warehouse->get_description( 'edit' ),
			'warehouse_title'            => $warehouse->get_title( 'edit' ),
			'warehouse_name'             => $warehouse->get_name( 'edit' ),
			'warehouse_order'            => $warehouse->get_order( 'edit' ),
			'warehouse_date_created'     => $warehouse->get_date_created( 'edit' ) ? gmdate( 'Y-m-d H:i:s', $warehouse->get_date_created( 'edit' )->getOffsetTimestamp() ) : null,
			'warehouse_date_created_gmt' => $warehouse->get_date_created( 'edit' ) ? gmdate( 'Y-m-d H:i:s', $warehouse->get_date_created( 'edit' )->getTimestamp() ) : null,
		);

		$wpdb->insert(
			$wpdb->stc_warehouses,
			$data
		);

		$warehouse_id = $wpdb->insert_id;

		if ( $warehouse_id ) {
			$warehouse->set_id( $warehouse_id );

			$this->save_warehouse_data( $warehouse );

			$warehouse->save_meta_data();
			$warehouse->apply_changes();
			$warehouse->set_object_read( true );

			$this->clear_caches( $warehouse );

			/**
			 * Action that indicates that a new warehouse has been created in the DB.
			 *
			 * @param integer  $warehouse_id The warehouse id.
			 * @param \Vendidero\Shiptastic\Warehouse $warehouse The warehouse instance.
			 *
			 * @package Vendidero/Shiptastic
			 */
			do_action( 'woocommerce_shiptastic_new_warehouse', $warehouse_id, $warehouse );
		}
	}

	/**
	 * Generate a unique name to save to the object.
	 *
	 * @param \Vendidero\Shiptastic\Warehouse $warehouse Warehouse object.
	 * @return string
	 */
	protected function get_unqiue_name( $warehouse ) {
		global $wpdb;

		$slug = sanitize_key( $warehouse->get_title() );

		// Post slugs must be unique across all posts.
		$check_sql            = "SELECT warehouse_name FROM $wpdb->stc_warehouses WHERE warehouse_name = %s AND warehouse_id != %d LIMIT 1";
		$warehouse_name_check = $wpdb->get_var( $wpdb->prepare( $check_sql, $slug, $warehouse->get_id() ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		if ( $warehouse_name_check ) {
			$suffix = 2;
			do {
				$alt_warehouse_name   = _truncate_post_slug( $slug, 200 - ( strlen( $suffix ) + 1 ) ) . "-$suffix";
				$warehouse_name_check = $wpdb->get_var( $wpdb->prepare( $check_sql, $alt_warehouse_name, $warehouse->get_id() ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				++$suffix;
			} while ( $warehouse_name_check );
			$slug = $alt_warehouse_name;
		}

		return $slug;
	}

	/**
	 * Method to update a warehouse in the database.
	 *
	 * @param \Vendidero\Shiptastic\Warehouse $warehouse Warehouse object.
	 */
	public function update( &$warehouse ) {
		global $wpdb;

		$updated_props  = array();
		$core_props     = $this->core_props;
		$changed_props  = array_keys( $warehouse->get_changes() );
		$warehouse_data = array();

		foreach ( $changed_props as $prop ) {
			if ( ! in_array( $prop, $core_props, true ) ) {
				continue;
			}

			switch ( $prop ) {
				case 'date_created':
					if ( is_callable( array( $warehouse, 'get_' . $prop ) ) ) {
						$warehouse_data[ 'warehouse_' . $prop ]          = $warehouse->{'get_' . $prop}( 'edit' ) ? gmdate( 'Y-m-d H:i:s', $warehouse->{'get_' . $prop}( 'edit' )->getOffsetTimestamp() ) : null;
						$warehouse_data[ 'warehouse_' . $prop . '_gmt' ] = $warehouse->{'get_' . $prop}( 'edit' ) ? gmdate( 'Y-m-d H:i:s', $warehouse->{'get_' . $prop}( 'edit' )->getTimestamp() ) : null;
					}
					break;
				default:
					if ( is_callable( array( $warehouse, 'get_' . $prop ) ) ) {
						$warehouse_data[ 'warehouse_' . $prop ] = $warehouse->{'get_' . $prop}( 'edit' );
					}
					break;
			}
		}

		if ( ! empty( $warehouse_data ) ) {
			$wpdb->update(
				$wpdb->stc_warehouses,
				$warehouse_data,
				array( 'warehouse_id' => $warehouse->get_id() )
			);
		}

		$this->save_warehouse_data( $warehouse );

		$warehouse->save_meta_data();
		$warehouse->apply_changes();
		$warehouse->set_object_read( true );

		$this->clear_caches( $warehouse );

		/**
		 * Action that indicates that a warehouse has been updated in the DB.
		 *
		 * @param integer  $warehouse_id The warehouse id.
		 * @param \Vendidero\Shiptastic\Warehouse $warehouse The warehouse instance.
		 *
		 * @package Vendidero/Shiptastic
		 */
		do_action( 'woocommerce_shiptastic_warehouse_updated', $warehouse->get_id(), $warehouse );
	}

	/**
	 * Remove a warehouse from the database.
	 *
	 * @param \Vendidero\Shiptastic\Warehouse $warehouse Warehouse object.
	 * @param bool                $force_delete Unused param.
	 */
	public function delete( &$warehouse, $force_delete = false ) {
		global $wpdb;

		$wpdb->delete( $wpdb->stc_warehouses, array( 'warehouse_id' => $warehouse->get_id() ), array( '%d' ) );
		$wpdb->delete( $wpdb->stc_warehousemeta, array( 'stc_warehouse_id' => $warehouse->get_id() ), array( '%d' ) );

		$this->clear_caches( $warehouse );

		/**
		 * Action that indicates that a warehouse has been deleted from the DB.
		 *
		 * @param integer  $warehouse_id The warehouse id.
		 * @param \Vendidero\Shiptastic\Warehouse $warehouse The warehouse instance.
		 *
		 * @package Vendidero/Shiptastic
		 */
		do_action( 'woocommerce_shiptastic_warehouse_deleted', $warehouse->get_id(), $warehouse );
	}

	/**
	 * Read a warehouse from the database.
	 *
	 * @param \Vendidero\Shiptastic\Warehouse $warehouse Warehouse object.
	 *
	 * @throws Exception Throw exception if invalid warehouse.
	 */
	public function read( &$warehouse, $data = null ) {
		global $wpdb;

		if ( is_null( $data ) ) {
			$data = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT * FROM {$wpdb->stc_warehouses} WHERE warehouse_id = %d LIMIT 1",
					$warehouse->get_id()
				)
			);
		}

		if ( $data ) {
			$warehouse->set_props(
				array(
					'name'         => $data->warehouse_name,
					'title'        => $data->warehouse_title,
					'type'         => $data->warehouse_type,
					'description'  => $data->warehouse_description,
					'order'        => $data->warehouse_order,
					'date_created' => Package::is_valid_mysql_date( $data->warehouse_date_created_gmt ) ? wc_string_to_timestamp( $data->warehouse_date_created_gmt ) : null,
				)
			);

			$this->read_warehouse_data( $warehouse );

			$warehouse->read_meta_data();
			$warehouse->set_object_read( true );

			/**
			 * Action that indicates that a warehouse has been loaded from DB.
			 *
			 * @param \Vendidero\Shiptastic\Warehouse $warehouse The warehouse object.
			 *
			 * @package Vendidero/Shiptastic
			 */
			do_action( 'woocommerce_shiptastic_warehouse_loaded', $warehouse );
		} else {
			throw new Exception( esc_html_x( 'Invalid warehouse.', 'shipments', 'shiptastic-for-woocommerce' ) );
		}
	}

	/**
	 * Clear any caches.
	 *
	 * @param \Vendidero\Shiptastic\Warehouse $warehouse Warehouse object.
	 */
	protected function clear_caches( &$warehouse ) {
		wp_cache_delete( 'warehouse-list', 'shiptastic-warehouse' );
		wp_cache_delete( $warehouse->get_id(), $this->meta_type . '_meta' );

		Helper::clear_cache();

		if ( $cache = \Vendidero\Shiptastic\Caches\Helper::get_cache_object( 'warehouses' ) ) {
			$cache->remove( $warehouse->get_id() );
		}
	}

	/*
	|--------------------------------------------------------------------------
	| Additional Methods
	|--------------------------------------------------------------------------
	*/

	/**
	 * Read extra data associated with the warehouse.
	 *
	 * @param \Vendidero\Shiptastic\Warehouse $warehouse Warehouse object.
	 */
	protected function read_warehouse_data( &$warehouse ) {
		$props = array();

		foreach ( $this->internal_meta_keys as $meta_key ) {
			$props[ substr( $meta_key, 1 ) ] = get_metadata( 'stc_warehouse', $warehouse->get_id(), $meta_key, true );
		}

		$warehouse->set_props( $props );
	}

	/**
	 * @param \Vendidero\Shiptastic\Warehouse $warehouse
	 */
	protected function save_warehouse_data( &$warehouse ) {
		$updated_props     = array();
		$meta_key_to_props = array();

		foreach ( $this->internal_meta_keys as $meta_key ) {
			$prop_name = substr( $meta_key, 1 );

			if ( in_array( $prop_name, $this->core_props, true ) ) {
				continue;
			}

			$meta_key_to_props[ $meta_key ] = $prop_name;
		}

		$props_to_update = $this->get_props_to_update( $warehouse, $meta_key_to_props, $this->meta_type );

		foreach ( $props_to_update as $meta_key => $prop ) {
			if ( ! is_callable( array( $warehouse, "get_$prop" ) ) ) {
				continue;
			}

			$value = $warehouse->{"get_$prop"}( 'edit' );
			$value = is_string( $value ) ? wp_slash( $value ) : $value;

			$updated = $this->update_or_delete_meta( $warehouse, $meta_key, $value );

			if ( $updated ) {
				$updated_props[] = $prop;
			}
		}

		/**
		 * Action that fires after updating a warehouses' properties.
		 *
		 * @param \Vendidero\Shiptastic\Warehouse $warehouse The warehouse object.
		 * @param array                           $changed_props The updated properties.
		 *
		 * @package Vendidero/Shiptastic
		 */
		do_action( 'woocommerce_shiptastic_warehouse_object_updated_props', $warehouse, $updated_props );
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
	 * @param WC_Data $packaging The WP_Data object (WC_Coupon for coupons, etc).
	 * @param string  $meta_key Meta key to update.
	 * @param mixed   $meta_value Value to save.
	 *
	 * @return bool True if updated/deleted.
	 * @since 3.6.0 Added to prevent empty meta being stored unless required.
	 *
	 */
	protected function update_or_delete_meta( $packaging, $meta_key, $meta_value ) {
		if ( in_array( $meta_value, array( array(), '' ), true ) && ! in_array( $meta_key, $this->must_exist_meta_keys, true ) ) {
			$updated = delete_metadata( $this->meta_type, $packaging->get_id(), $meta_key );
		} else {
			$updated = update_metadata( $this->meta_type, $packaging->get_id(), $meta_key, $meta_value );
		}

		return (bool) $updated;
	}

	/**
	 * Get valid WP_Query args from a WC_Order_Query's query variables.
	 *
	 * @param array $query_vars query vars from a WC_Order_Query.
	 * @return array
	 */
	protected function get_wp_query_args( $query_vars ) {
		global $wpdb;

		$wp_query_args = parent::get_wp_query_args( $query_vars );

		// Force type to be existent
		if ( isset( $query_vars['type'] ) ) {
			$wp_query_args['type'] = $query_vars['type'];
		}

		if ( ! isset( $wp_query_args['date_query'] ) ) {
			$wp_query_args['date_query'] = array();
		}

		if ( ! isset( $wp_query_args['meta_query'] ) ) {
			$wp_query_args['meta_query'] = array(); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
		}

		// Allow Woo to treat these props as date query compatible
		$date_queries = array(
			'date_created',
		);

		foreach ( $date_queries as $db_key ) {
			if ( isset( $query_vars[ $db_key ] ) && '' !== $query_vars[ $db_key ] ) {

				// Remove any existing meta queries for the same keys to prevent conflicts.
				$existing_queries = wp_list_pluck( $wp_query_args['meta_query'], 'key', true );
				$meta_query_index = array_search( $db_key, $existing_queries, true );

				if ( false !== $meta_query_index ) {
					unset( $wp_query_args['meta_query'][ $meta_query_index ] );
				}

				$date_query_args = $this->parse_date_for_wp_query( $query_vars[ $db_key ], 'post_date', array() );

				/**
				 * Replace date query columns after Woo parsed dates.
				 * Include table name because otherwise WP_Date_Query won't accept our custom column.
				 */
				if ( isset( $date_query_args['date_query'] ) && ! empty( $date_query_args['date_query'] ) ) {
					$date_query = $date_query_args['date_query'][0];

					if ( 'post_date' === $date_query['column'] ) {
						$date_query['column'] = $wpdb->stc_shipments . '.shipment_' . $db_key;
					}

					$wp_query_args['date_query'][] = $date_query;
				}
			}
		}

		if ( ! isset( $query_vars['paginate'] ) || ! $query_vars['paginate'] ) {
			$wp_query_args['no_found_rows'] = true;
		}

		/**
		 * Filter to adjust warehouse query arguments after parsing.
		 *
		 * @param array     $wp_query_args Array containing parsed query arguments.
		 * @param array     $query_vars The original query arguments.
		 * @param Warehouse $data_store The warehouse data store object.
		 *
		 * @package Vendidero/Shiptastic
		 */
		return apply_filters( 'woocommerce_shiptastic_warehouse_data_store_get_shipments_query', $wp_query_args, $query_vars, $this );
	}

	/**
	 * @return array
	 */
	public static function get_all_warehouses() {
		global $wpdb;

		// Get from cache if available.
		$results = wp_cache_get( 'warehouse-list', 'shiptastic-warehouse' );

		if ( false === $results ) {
			$query = "
				SELECT warehouse_id FROM {$wpdb->stc_warehouses} 
				ORDER BY warehouse_order ASC
			";

			$results = $wpdb->get_results( $query ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

			wp_cache_set( 'warehouse-list', $results, 'shiptastic-warehouse' );
		}

		return $results;
	}

	/**
	 * @param string $warehouse_name
	 *
	 * @return array
	 */
	public static function get_warehouse_by_name( $warehouse_name ) {
		global $wpdb;

		$data = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->stc_warehouses} WHERE warehouse_name = %s LIMIT 1",
				$warehouse_name
			)
		);

		return $data;
	}

	/**
	 * Table structure is slightly different between meta types, this function will return what we need to know.
	 *
	 * @return array Array elements: table, object_id_field, meta_id_field
	 */
	protected function get_db_info() {
		global $wpdb;

		$meta_id_field   = 'meta_id'; // for some reason users calls this umeta_id so we need to track this as well.
		$table           = $wpdb->stc_warehousemeta;
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

	public function get_query_args( $query_vars ) {
		return $this->get_wp_query_args( $query_vars );
	}
}
