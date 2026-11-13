<?php

namespace Vendidero\Shiptastic\Warehouse;

defined( 'ABSPATH' ) || exit;

/**
 * Storage location helpers. Pick sequences are computed on demand from the product's storage location code
 * (product meta, variations fall back to their parent) and the location schema of its warehouse - there is
 * no lookup table to keep in sync.
 */
class StorageLocations {

	/**
	 * Returns the storage location data (warehouse, location_code, pick_sequence) for products with a
	 * storage location, keyed by product id.
	 *
	 * @param int[] $product_ids
	 *
	 * @return array
	 */
	public static function get_product_locations( $product_ids ) {
		$locations = array();

		foreach ( array_filter( array_unique( array_map( 'absint', (array) $product_ids ) ) ) as $product_id ) {
			if ( ! $product = wc_get_product( $product_id ) ) {
				continue;
			}

			$s_product = wc_shiptastic_get_product( $product );
			$code      = LocationSchema::normalize_code( $s_product->get_warehouse_location() );

			if ( '' === $code ) {
				continue;
			}

			$warehouse_name = wc_stc_get_product_warehouse_name( $s_product );

			$locations[ $product_id ] = array(
				'warehouse'     => $warehouse_name,
				'location_code' => $code,
				'pick_sequence' => self::get_pick_sequence( $code, $warehouse_name ),
			);
		}

		return $locations;
	}

	/**
	 * @param string $code
	 * @param string $warehouse_name
	 *
	 * @return string
	 */
	public static function get_pick_sequence( $code, $warehouse_name = '' ) {
		$warehouse = Helper::get_warehouse_by_name( $warehouse_name );

		if ( $warehouse && ! $warehouse->is_group() ) {
			return $warehouse->get_pick_sequence( $code );
		}

		return LocationSchema::get_natural_sequence( $code );
	}

	/**
	 * Sorts product ids along the picking path: by warehouse (warehouse order, default location last), then by
	 * pick sequence. Products without a storage location are placed at the end of their warehouse.
	 *
	 * @param int[] $product_ids
	 *
	 * @return int[]
	 */
	public static function sort_product_ids( $product_ids ) {
		$product_ids     = array_values( array_unique( array_map( 'absint', (array) $product_ids ) ) );
		$locations       = self::get_product_locations( $product_ids );
		$warehouse_order = array();

		foreach ( wc_stc_get_warehouse_list() as $index => $warehouse ) {
			$warehouse_order[ $warehouse->get_name() ] = $index;
		}

		$keys = array();

		foreach ( $product_ids as $position => $product_id ) {
			if ( isset( $locations[ $product_id ] ) ) {
				$warehouse_name = $locations[ $product_id ]['warehouse'];
				$sequence       = $locations[ $product_id ]['pick_sequence'];
			} else {
				$warehouse_name = wc_stc_get_product_warehouse_name( $product_id );
				$sequence       = '~';
			}

			$keys[ $product_id ] = array(
				isset( $warehouse_order[ $warehouse_name ] ) ? $warehouse_order[ $warehouse_name ] : PHP_INT_MAX,
				$sequence,
				$position,
			);
		}

		usort(
			$product_ids,
			function ( $a, $b ) use ( $keys ) {
				if ( $keys[ $a ][0] !== $keys[ $b ][0] ) {
					return $keys[ $a ][0] < $keys[ $b ][0] ? -1 : 1;
				}

				/**
				 * Pick sequences are compared as strings (numeric-looking strings must not be compared numerically).
				 */
				$compare = strcmp( $keys[ $a ][1], $keys[ $b ][1] );

				if ( 0 !== $compare ) {
					return $compare < 0 ? -1 : 1;
				}

				return $keys[ $a ][2] < $keys[ $b ][2] ? -1 : 1;
			}
		);

		return $product_ids;
	}
}
