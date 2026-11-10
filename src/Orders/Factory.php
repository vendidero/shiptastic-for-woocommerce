<?php
/**
 * Label Factory
 *
 * The label factory creates the right label objects.
 *
 * @version 1.0.0
 */
namespace Vendidero\Shiptastic\Orders;

use Vendidero\Shiptastic\Caches\Helper;
use Vendidero\Shiptastic\Order;
use Exception;

defined( 'ABSPATH' ) || exit;

/**
 * Order factory class
 */
class Factory {

	private static $order_list = null;

	/**
	 * Request-local map of shipment ids per order, e.g. primed for order list views.
	 *
	 * @var array
	 */
	private static $primed_shipment_ids = array();

	/**
	 * Load shipments for multiple orders at once (including related shipment data).
	 *
	 * @param \WC_Order[]|int[] $orders
	 */
	public static function prime_shipments( $orders ) {
		$order_objects = array();

		foreach ( (array) $orders as $order ) {
			if ( is_a( $order, 'WC_Order' ) ) {
				$order_objects[ $order->get_id() ] = $order;
			} elseif ( is_numeric( $order ) && absint( $order ) > 0 ) {
				$order_objects[ absint( $order ) ] = null;
			}
		}

		$order_ids = array_values(
			array_filter(
				array_keys( $order_objects ),
				function ( $order_id ) {
					return ! array_key_exists( $order_id, self::$primed_shipment_ids );
				}
			)
		);

		if ( empty( $order_ids ) ) {
			return;
		}

		$shipments = wc_stc_get_shipments(
			array(
				'order_id' => $order_ids,
				'limit'    => -1,
				'orderby'  => 'date_created',
				'type'     => array( 'simple', 'return' ),
				'order'    => 'ASC',
			)
		);

		foreach ( $order_ids as $order_id ) {
			self::$primed_shipment_ids[ $order_id ] = array();
		}

		foreach ( $shipments as $shipment ) {
			$order_id = absint( $shipment->get_order_id() );

			if ( array_key_exists( $order_id, self::$primed_shipment_ids ) ) {
				self::$primed_shipment_ids[ $order_id ][] = $shipment->get_id();
			}

			if ( ! empty( $order_objects[ $order_id ] ) ) {
				$shipment->set_order_instance( $order_objects[ $order_id ] );
			}
		}

		\Vendidero\Shiptastic\ShipmentFactory::prime_caches( $shipments, array( 'orders' => false ) );
	}

	/**
	 * Returns primed shipment ids for an order. Returns null in case shipments were not primed for the order.
	 *
	 * @param int $order_id
	 *
	 * @return null|int[]
	 */
	public static function get_primed_shipment_ids( $order_id ) {
		$order_id = absint( $order_id );

		return array_key_exists( $order_id, self::$primed_shipment_ids ) ? self::$primed_shipment_ids[ $order_id ] : null;
	}

	/**
	 * @param int $order_id
	 */
	public static function clear_primed_shipments( $order_id ) {
		unset( self::$primed_shipment_ids[ absint( $order_id ) ] );
	}

	/**
	 * Get order.
	 *
	 * @param  mixed $order
	 * @return Order|bool
	 */
	public static function get_order( $order ) {
		if ( is_numeric( $order ) ) {
			$order = wc_get_order( $order );
		}

		if ( is_a( $order, 'WC_Order' ) ) {
			try {
				if ( $cache = Helper::get_cache_object( 'shipment-orders' ) ) {
					$shipment_order = $cache->get( $order->get_id() );

					if ( ! is_null( $shipment_order ) ) {
						return $shipment_order;
					}
				}

				$shipment_order = new Order( $order );

				if ( $cache = Helper::get_cache_object( 'shipment-orders' ) ) {
					$cache->set( $shipment_order, $order->get_id() );
				}

				return $shipment_order;
			} catch ( Exception $e ) {
				wc_caught_exception( $e, __FUNCTION__, array( $order ) );
				return false;
			}
		} elseif ( is_a( $order, 'Vendidero\Shiptastic\Order' ) ) {
			return $order;
		}

		return false;
	}
}
