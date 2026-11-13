<?php

namespace Vendidero\Shiptastic;

defined( 'ABSPATH' ) || exit;

/**
 * Records the number of backordered units per order item at the time Woo reduces stock (similar to Woo's own
 * "Backordered" item meta). Backordered units are never added to shipments automatically - users are warned and may
 * add them manually.
 *
 * Note: Backorders are not released automatically once stock becomes available again. Allocating restocked units to
 * orders is a stock management concern (Woo core).
 */
class Backorders {

	const ITEM_META_KEY = '_stc_backordered_qty';

	public static function init() {
		add_action( 'woocommerce_reduce_order_item_stock', array( __CLASS__, 'record_item_backorders' ), 10, 3 );
		add_action( 'woocommerce_restore_order_item_stock', array( __CLASS__, 'clear_item_backorders' ), 10, 4 );

		add_filter( 'woocommerce_hidden_order_itemmeta', array( __CLASS__, 'hide_item_meta' ) );
	}

	public static function hide_item_meta( $meta ) {
		$meta[] = self::ITEM_META_KEY;

		return $meta;
	}

	/**
	 * @param \WC_Order_Item $item
	 *
	 * @return int
	 */
	public static function get_item_backordered_quantity( $item ) {
		if ( ! is_a( $item, 'WC_Order_Item_Product' ) ) {
			return 0;
		}

		$quantity = absint( $item->get_meta( self::ITEM_META_KEY, true ) );

		/**
		 * Filter to adjust the number of units of an order item on backorder, e.g. to integrate
		 * a stock management solution which allocates restocked units to orders.
		 *
		 * @param int                    $quantity The backordered quantity.
		 * @param \WC_Order_Item_Product $item The order item.
		 *
		 * @package Vendidero/Shiptastic
		 */
		return absint( apply_filters( 'woocommerce_shiptastic_order_item_backordered_quantity', $quantity, $item ) );
	}

	/**
	 * @param \WC_Order_Item_Product $item
	 * @param int $quantity
	 *
	 * @return void
	 */
	public static function set_item_backordered_quantity( $item, $quantity ) {
		$quantity = max( 0, (int) $quantity );

		if ( $quantity > 0 ) {
			$item->update_meta_data( self::ITEM_META_KEY, $quantity );
		} else {
			$item->delete_meta_data( self::ITEM_META_KEY );
		}

		$item->save();
	}

	/**
	 * Records the number of backordered units right after Woo reduced stock for an order item.
	 *
	 * @param \WC_Order_Item_Product $item
	 * @param array $change
	 * @param \WC_Order $order
	 *
	 * @return void
	 */
	public static function record_item_backorders( $item, $change, $order ) {
		if ( ! is_a( $item, 'WC_Order_Item_Product' ) || ! isset( $change['from'], $change['to'] ) ) {
			return;
		}

		$reduced     = (float) $change['from'] - (float) $change['to'];
		$backordered = self::calculate_backordered_quantity( $change['from'], $reduced );

		if ( $backordered > 0 ) {
			self::set_item_backordered_quantity( $item, $backordered );

			/**
			 * Action that fires after backordered units have been recorded for an order item.
			 *
			 * @param \WC_Order_Item_Product $item The order item.
			 * @param int                    $backordered The backordered quantity.
			 * @param \WC_Order              $order The order.
			 *
			 * @package Vendidero/Shiptastic
			 */
			do_action( 'woocommerce_shiptastic_order_item_backordered', $item, $backordered, $order );
		}
	}

	/**
	 * Calculates the number of units which could not be served from stock.
	 *
	 * @param int|float $stock_before Stock before reducing.
	 * @param int|float $quantity Quantity reduced.
	 *
	 * @return int
	 */
	public static function calculate_backordered_quantity( $stock_before, $quantity ) {
		$quantity     = (int) ceil( (float) $quantity );
		$stock_before = (float) $stock_before;

		return (int) max( 0, min( $quantity, $quantity - max( 0, floor( $stock_before ) ) ) );
	}

	/**
	 * Stock was restored for an order item (e.g. order cancelled) - its backorder is gone.
	 */
	public static function clear_item_backorders( $item, $new_stock, $old_stock, $order ) {
		if ( is_a( $item, 'WC_Order_Item_Product' ) && self::get_item_backordered_quantity( $item ) > 0 ) {
			self::set_item_backordered_quantity( $item, 0 );
		}
	}
}
