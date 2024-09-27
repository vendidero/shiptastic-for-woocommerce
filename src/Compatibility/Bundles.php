<?php

namespace Vendidero\Shiptastic\Compatibility;

use Vendidero\Shiptastic\Interfaces\Compatibility;
use Vendidero\Shiptastic\Product;
use Vendidero\Shiptastic\Shipment;

defined( 'ABSPATH' ) || exit;

class Bundles implements Compatibility {

	protected static $cart_bundled_by_map = array();

	public static function is_active() {
		return class_exists( 'WC_Bundles' );
	}

	public static function init() {
		add_action(
			'woocommerce_shiptastic_before_prepare_cart_contents',
			function () {
				self::$cart_bundled_by_map = array();
			}
		);

		add_filter( 'woocommerce_shiptastic_order_item_product', array( __CLASS__, 'get_product_from_item' ), 10, 2 );
		add_filter( 'woocommerce_shiptastic_cart_item', array( __CLASS__, 'adjust_cart_item' ), 10, 2 );
		add_action( 'woocommerce_shiptastic_shipment_items_synced', array( __CLASS__, 'apply_bundle_hierarchy' ), 10, 3 );
		add_filter( 'woocommerce_shiptastic_order_item_quantity_left_for_shipping', array( __CLASS__, 'maybe_remove_children' ), 10, 2 );
	}

	/**
	 * @param integer $quantity_left
	 * @param \WC_Order_Item $order_item
	 *
	 * @return integer
	 */
	public static function maybe_remove_children( $quantity_left, $order_item ) {
		if ( wc_pb_is_bundled_order_item( $order_item ) ) {
			if ( apply_filters( 'woocommerce_shiptastic_remove_hidden_bundled_items', 'yes' === $order_item->get_meta( '_bundled_item_hidden' ), $order_item ) ) {
				$quantity_left = 0;
			}
		}

		return $quantity_left;
	}

	/**
	 * Add parent bundle id to the child bundles.
	 *
	 * @param Shipment $shipment
	 *
	 * @return void
	 */
	public static function apply_bundle_hierarchy( $shipment ) {
		$map     = array();
		$parents = array();

		foreach ( $shipment->get_items() as $item ) {
			if ( $order_item = $item->get_order_item() ) {
				$map[ $item->get_order_item_id() ] = $item->get_id();

				if ( wc_pb_is_bundled_order_item( $order_item ) ) {
					$container_id = wc_pb_get_bundled_order_item_container( $order_item, false, true );

					if ( ! isset( $parents[ $container_id ] ) ) {
						$parents[ $container_id ] = array();
					}

					$parents[ $container_id ][] = $item;
				}
			}
		}

		foreach ( $parents as $order_item_id => $shipment_items ) {
			if ( array_key_exists( $order_item_id, $map ) ) {
				$parent_id = $map[ $order_item_id ];

				foreach ( $shipment_items as $shipment_item ) {
					$shipment_item->set_item_parent_id( $parent_id );
				}
			}
		}
	}

	/**
	 * @param Product|null $product
	 * @param \WC_Order_Item_Product $item
	 *
	 * @return Product
	 */
	public static function get_product_from_item( $product, $item ) {
		if ( ( ! $order = $item->get_order() ) || ! $product ) {
			return $product;
		}

		$reset_shipping_props = false;

		if ( wc_pb_is_bundle_container_order_item( $item, $order ) ) {
			if ( $product->needs_shipping() ) {
				if ( $bundle_weight = $item->get_meta( '_bundle_weight', true ) ) {
					if ( is_null( $bundle_weight ) ) {
						$bundle_weight = '';
					}

					$product->set_weight( $bundle_weight );
				}
			} else {
				$reset_shipping_props = true;
			}
		} elseif ( wc_pb_is_bundled_order_item( $item, $order ) ) {
			if ( $product->needs_shipping() ) {
				if ( 'no' === $item->get_meta( '_bundled_item_needs_shipping', true ) ) {
					$reset_shipping_props = true;
				}
			} else {
				$reset_shipping_props = true;
			}
		}

		if ( $reset_shipping_props ) {
			$product->set_weight( 0 );
			$product->set_shipping_width( 0 );
			$product->set_shipping_height( 0 );
			$product->set_shipping_length( 0 );
		}

		return $product;
	}

	/**
	 * Product Bundles cart item compatibility:
	 * In case the current item belongs to a parent bundle item (which contains the actual price)
	 * copy the pricing data from the parent once, e.g. for the first bundled item.
	 *
	 * @param $item
	 * @param $content_key
	 *
	 * @return mixed
	 */
	public static function adjust_cart_item( $item, $content_key ) {
		if ( isset( $item['bundled_by'] ) && 0.0 === (float) $item['line_total'] && function_exists( 'wc_pb_get_bundled_cart_item_container' ) ) {
			$bundled_by = $item['bundled_by'];

			if ( ! in_array( $bundled_by, self::$cart_bundled_by_map, true ) ) {
				if ( $container = wc_pb_get_bundled_cart_item_container( $item ) ) {
					$item['line_total']        = (float) $container['line_total'];
					$item['line_subtotal']     = (float) $container['line_subtotal'];
					$item['line_tax']          = (float) $container['line_tax'];
					$item['line_subtotal_tax'] = (float) $container['line_subtotal_tax'];

					self::$cart_bundled_by_map[] = $bundled_by;
				}
			}
		}

		return $item;
	}
}
