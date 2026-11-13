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

	/**
	 * @param string $name
	 *
	 * @return \Vendidero\Shiptastic\Warehouse|null
	 */
	public static function get_warehouse_by_name( $name ) {
		if ( '' === $name || null === $name ) {
			return null;
		}

		self::get_all_warehouses();

		if ( array_key_exists( $name, self::$warehouses_lookup ) ) {
			return self::$warehouses[ self::$warehouses_lookup[ $name ] ];
		}

		return null;
	}

	/**
	 * @param array $args
	 *
	 * @return \Vendidero\Shiptastic\Warehouse[]
	 */
	public static function get_group_list( $args = array() ) {
		return self::get_warehouse_list( array_merge( $args, array( 'type' => 'group' ) ) );
	}

	/**
	 * Returns the location (warehouse group or ungrouped warehouse) for a warehouse name.
	 * Returns null for the default location (no or unknown warehouse).
	 *
	 * @param string $warehouse_name
	 *
	 * @return \Vendidero\Shiptastic\Warehouse|null
	 */
	public static function get_location_by_warehouse_name( $warehouse_name ) {
		if ( $warehouse = self::get_warehouse_by_name( $warehouse_name ) ) {
			return $warehouse->get_location();
		}

		return null;
	}

	/**
	 * Returns the location instance by its name (a group name or the name of an ungrouped warehouse).
	 * Returns null for the default location.
	 *
	 * @param string $location_name
	 *
	 * @return \Vendidero\Shiptastic\Warehouse|null
	 */
	public static function get_location( $location_name ) {
		return self::get_location_by_warehouse_name( $location_name );
	}

	/**
	 * Returns the name of the warehouse a product is stored in, in case the warehouse exists.
	 *
	 * @param \WC_Product|\Vendidero\Shiptastic\Product|int $product
	 *
	 * @return string
	 */
	public static function get_product_warehouse_name( $product ) {
		$s_product = null;

		if ( is_a( $product, '\Vendidero\Shiptastic\Product' ) ) {
			$s_product = $product;
		} elseif ( $product ) {
			$s_product = wc_shiptastic_get_product( $product );
		}

		$warehouse_name = '';

		if ( $s_product && $s_product->get_product() ) {
			$name = $s_product->get_warehouse_name();

			if ( ! empty( $name ) && ( $warehouse = self::get_warehouse_by_name( $name ) ) && ! $warehouse->is_group() ) {
				$warehouse_name = $warehouse->get_name();
			}
		}

		return apply_filters( 'woocommerce_shiptastic_product_warehouse_name', $warehouse_name, $s_product );
	}

	/**
	 * Returns the location name a product ships from, used to split packages: the warehouse group, the
	 * (ungrouped) warehouse itself or an empty string for the default location.
	 *
	 * @param \WC_Product|\Vendidero\Shiptastic\Product|int $product
	 *
	 * @return string
	 */
	public static function get_product_location_name( $product ) {
		$location_name = '';

		if ( $location = self::get_location_by_warehouse_name( self::get_product_warehouse_name( $product ) ) ) {
			$location_name = $location->get_name();
		}

		/**
		 * Filter to adjust the location (warehouse group or ungrouped warehouse) a product is shipped from.
		 * An empty string refers to the default location.
		 *
		 * @param string $location_name The location name.
		 * @param \WC_Product|\Vendidero\Shiptastic\Product|int $product The product.
		 *
		 * @package Vendidero/Shiptastic
		 */
		return apply_filters( 'woocommerce_shiptastic_product_location_name', $location_name, $product );
	}
}
