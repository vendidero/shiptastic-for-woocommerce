<?php

namespace Vendidero\Shiptastic\Warehouse;

use Vendidero\Shiptastic\DataStores\Warehouse;

defined( 'ABSPATH' ) || exit;

class Helper {

	protected static $warehouses = null;

	protected static $warehouses_lookup = null;

	/**
	 * @return \Vendidero\Shiptastic\Warehouse[]
	 */
	public static function get_all_warehouses() {
		if ( is_null( self::$warehouses ) ) {
			self::$warehouses        = array();
			self::$warehouses_lookup = array();

			try {
				foreach ( Warehouse::get_all_warehouses() as $key => $warehouse ) {
					if ( $the_warehouse = wc_stc_get_warehouse( $warehouse ) ) {
						self::$warehouses[ $key ]                              = $the_warehouse;
						self::$warehouses_lookup[ $the_warehouse->get_name() ] = $key;
					}
				}
			} catch ( \Exception $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
			}
		}

		return self::$warehouses;
	}

	/**
	 * @param $args
	 *
	 * @return \Vendidero\Shiptastic\Warehouse[]
	 */
	public static function get_warehouse_list( $args = array() ) {
		$the_list  = self::get_all_warehouses();
		$all_types = array_keys( wc_stc_get_warehouse_types() );
		$args      = wp_parse_args(
			$args,
			array(
				'type' => $all_types,
			)
		);

		if ( ! is_array( $args['type'] ) ) {
			$args['type'] = array_filter( array( $args['type'] ) );
		}

		$types = array_filter( wc_clean( $args['type'] ) );
		$types = empty( $types ) ? $all_types : $types;

		foreach ( $the_list as $key => $warehouse ) {
			if ( ! in_array( $warehouse->get_type(), $types, true ) ) {
				unset( $the_list[ $key ] );
				continue;
			}
		}

		return array_values( $the_list );
	}

	public static function clear_cache() {
		self::$warehouses        = null;
		self::$warehouses_lookup = null;
	}
}
