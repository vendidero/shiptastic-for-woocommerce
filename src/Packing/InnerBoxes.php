<?php

namespace Vendidero\Shiptastic\Packing;

defined( 'ABSPATH' ) || exit;

/**
 * Inner boxes: A product (unit) may be shipped within multiple (inner) boxes, each having its own dimensions
 * and weight. Each inner box is treated as a separate item by the packing algorithm and each shipment item may
 * represent a single inner box of an order item.
 *
 * Core does not store inner boxes itself - extensions register them via the filters below.
 * Dimensions and weight are expected in the shop's dimension and weight unit.
 */
class InnerBoxes {

	protected static $order_item_cache = array();

	/**
	 * @param array $boxes
	 *
	 * @return array key => array( 'key', 'name', 'length', 'width', 'height', 'weight', 'index', 'count' )
	 */
	public static function parse( $boxes ) {
		$parsed = array();

		foreach ( (array) $boxes as $key => $box ) {
			if ( ! is_array( $box ) ) {
				continue;
			}

			$box = wp_parse_args(
				$box,
				array(
					'key'      => $key,
					'name'     => '',
					'length'   => 0,
					'width'    => 0,
					'height'   => 0,
					'weight'   => 0,
					'location' => '',
				)
			);

			$box_key = sanitize_key( (string) $box['key'] );

			if ( '' === $box_key ) {
				continue;
			}

			$parsed[ $box_key ] = array(
				'key'      => $box_key,
				'name'     => wc_clean( (string) $box['name'] ),
				'length'   => (float) wc_format_decimal( $box['length'] ),
				'width'    => (float) wc_format_decimal( $box['width'] ),
				'height'   => (float) wc_format_decimal( $box['height'] ),
				'weight'   => (float) wc_format_decimal( $box['weight'] ),
				// Optional storage location (within the product's warehouse) of the inner box.
				'location' => wc_clean( (string) $box['location'] ),
			);
		}

		$count = count( $parsed );
		$index = 0;

		foreach ( $parsed as $key => $box ) {
			$parsed[ $key ]['index'] = ++$index;
			$parsed[ $key ]['count'] = $count;
		}

		return $parsed;
	}

	/**
	 * @param \WC_Order_Item|int $order_item
	 *
	 * @return array
	 */
	public static function get_order_item_boxes( $order_item ) {
		if ( is_numeric( $order_item ) ) {
			$order_item_id = absint( $order_item );
			$order_item    = \WC_Order_Factory::get_order_item( $order_item_id );
		}

		if ( ! $order_item || ! is_a( $order_item, 'WC_Order_Item' ) ) {
			return array();
		}

		$cache_key = $order_item->get_id();

		if ( $cache_key > 0 && array_key_exists( $cache_key, self::$order_item_cache ) ) {
			return self::$order_item_cache[ $cache_key ];
		}

		/**
		 * Filter to register inner boxes for a certain order item.
		 *
		 * @param array          $boxes key => array( 'name', 'length', 'width', 'height', 'weight' ) in shop units.
		 * @param \WC_Order_Item $order_item The order item.
		 *
		 * @package Vendidero/Shiptastic
		 */
		$boxes = self::parse( apply_filters( 'woocommerce_shiptastic_order_item_inner_boxes', array(), $order_item ) );

		if ( $cache_key > 0 ) {
			self::$order_item_cache[ $cache_key ] = $boxes;
		}

		return $boxes;
	}

	/**
	 * @param array $cart_item
	 *
	 * @return array
	 */
	public static function get_cart_item_boxes( $cart_item ) {
		/**
		 * Filter to register inner boxes for a certain cart item.
		 *
		 * @param array $boxes key => array( 'name', 'length', 'width', 'height', 'weight' ) in shop units.
		 * @param array $cart_item The cart item.
		 *
		 * @package Vendidero/Shiptastic
		 */
		return self::parse( apply_filters( 'woocommerce_shiptastic_cart_item_inner_boxes', array(), $cart_item ) );
	}

	/**
	 * @param \WC_Order_Item|int $order_item
	 *
	 * @return bool
	 */
	public static function order_item_has_boxes( $order_item ) {
		return ! empty( self::get_order_item_boxes( $order_item ) );
	}

	/**
	 * @param \WC_Order_Item|int $order_item
	 * @param string             $key
	 *
	 * @return array|false
	 */
	public static function get_order_item_box( $order_item, $key ) {
		$boxes = self::get_order_item_boxes( $order_item );

		return array_key_exists( (string) $key, $boxes ) ? $boxes[ (string) $key ] : false;
	}

	/**
	 * @param array $box
	 *
	 * @return string
	 */
	public static function get_box_label( $box ) {
		if ( ! empty( $box['name'] ) ) {
			// Custom box names are used as is.
			$label = $box['name'];
		} else {
			/* translators: 1: box index 2: box count */
			$label = sprintf( _x( 'Box %1$d/%2$d', 'shipments-inner-box', 'shiptastic-for-woocommerce' ), $box['index'], $box['count'] );
		}

		/**
		 * Filter the label (suffix) of an inner box, e.g. used for shipment item names.
		 *
		 * @param string $label The label.
		 * @param array  $box The inner box.
		 *
		 * @package Vendidero/Shiptastic
		 */
		return apply_filters( 'woocommerce_shiptastic_inner_box_label', $label, $box );
	}

	public static function flush_cache( $order_item_id = 0 ) {
		if ( $order_item_id ) {
			unset( self::$order_item_cache[ $order_item_id ] );
		} else {
			self::$order_item_cache = array();
		}
	}
}
