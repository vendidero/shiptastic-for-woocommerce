<?php
/**
 * Packaging Factory
 *
 * The packaging factory creates the right packaging objects.
 *
 * @version 1.0.0
 * @package Vendidero/Shiptastic
 */
namespace Vendidero\Shiptastic;

use Exception;
use Vendidero\Shiptastic\Caches\Helper;

defined( 'ABSPATH' ) || exit;

/**
 * Warehouse factory class
 */
class WarehouseFactory {

	/**
	 * Get warehouse.
	 *
	 * @param  mixed $warehouse_id (default: false) Warehouse name to get or empty if new.
	 * @return Warehouse|bool
	 */
	public static function get_warehouse( $warehouse = false ) {
		$warehouse_data = self::get_warehouse_data( $warehouse );
		$classname      = '\Vendidero\Shiptastic\Warehouse';
		$warehouse_id   = ! empty( $warehouse_data->warehouse_id ) ? absint( $warehouse_data->warehouse_id ) : absint( $warehouse_data );

		/**
		 * We did not find a warehouse based on name
		 */
		if ( empty( $warehouse_id ) && ! is_numeric( $warehouse ) ) {
			return false;
		}

		if ( ! empty( $warehouse_id ) ) {
			if ( $cache = Helper::get_cache_object( 'warehouses' ) ) {
				$warehouse = $cache->get( $warehouse_id );

				if ( ! is_null( $warehouse ) ) {
					return $warehouse;
				}
			}
		}

		/**
		 * Filter to adjust the classname used to construct a warehouse.
		 *
		 * @param string  $classname The classname to be used.
		 * @param integer $warehouse_id The warehouse id.
		 *
		 * @package Vendidero/Shiptastic
		 */
		$classname = apply_filters( 'woocommerce_shiptastic_warehouse_class', $classname, $warehouse_id );

		if ( ! class_exists( $classname ) ) {
			return false;
		}

		try {
			$warehouse = new $classname( $warehouse_data );

			if ( $warehouse_id > 0 && empty( $warehouse->get_id() ) ) {
				throw new Exception( 'Warehouse does not exist' );
			}

			if ( $warehouse && $warehouse->get_id() > 0 && ( $cache = Helper::get_cache_object( 'warehouses' ) ) ) {
				$cache->set( $warehouse, $warehouse->get_id() );
			}

			return $warehouse;
		} catch ( Exception $e ) {
			wc_caught_exception( $e, __FUNCTION__, array( $warehouse_id ) );
			return false;
		}
	}

	public static function get_warehouse_data( $warehouse ) {
		if ( is_numeric( $warehouse ) ) {
			return $warehouse;
		} elseif ( $warehouse instanceof Warehouse ) {
			return $warehouse->get_id();
		} elseif ( ! empty( $warehouse->warehouse_id ) ) {
			return $warehouse;
		} else {
			return self::get_warehouse_by_name( $warehouse );
		}
	}

	protected static function get_warehouse_by_name( $warehouse ) {
		return \Vendidero\Shiptastic\DataStores\Warehouse::get_warehouse_by_name( $warehouse );
	}
}
