<?php

namespace Vendidero\Shiptastic\Compatibility;

use Vendidero\OrderWithdrawalButton\WithdrawalOrder;
use Vendidero\Shiptastic\Interfaces\Compatibility;
use Vendidero\Shiptastic\Order;

defined( 'ABSPATH' ) || exit;

class OrderWithdrawalButton implements Compatibility {

	public static function is_active() {
		return function_exists( 'eu_owb_order_confirm_withdrawal_request' );
	}

	public static function init() {
		add_action( 'eu_owb_woocommerce_withdrawal_request_confirmed', array( __CLASS__, 'on_request_updated' ), 10, 2 );
		add_filter( 'eu_owb_woocommerce_order_item_is_withdrawable', array( __CLASS__, 'item_is_withdrawable' ), 10, 3 );
		add_filter( 'eu_owb_woocommerce_order_date_delivered_raw', array( __CLASS__, 'date_delivered' ), 10, 2 );
		add_filter( 'woocommerce_shiptastic_shipment_order_item_shippable_quantity', array( __CLASS__, 'shippable_quantity' ), 10, 3 );
	}

	/**
	 * @param int $total_quantity
	 * @param \WC_Order_Item $order_item
	 * @param Order $order
	 *
	 * @return int
	 */
	public static function shippable_quantity( $total_quantity, $order_item, $order ) {
		if ( ! function_exists( 'eu_owb_get_order_withdrawals' ) ) {
			return $total_quantity;
		}

		$withdrawals = eu_owb_get_order_withdrawals( $order->get_order(), array( 'status' => 'confirmed' ) );

		foreach ( $withdrawals as $withdrawal ) {
			foreach ( $withdrawal->get_items() as $withdrawal_item ) {
				if ( $withdrawal_item->get_parent_id() === $order_item->get_id() ) {
					$total_quantity -= $withdrawal_item->get_quantity();

					if ( is_callable( array( $withdrawal_item, 'get_refunded_quantity' ) ) ) {
						/**
						 * This is the refunded qty already deducted by core from total_quantity
						 */
						$refunded_qty = absint( $order->get_order()->get_qty_refunded_for_item( $order_item->get_id() ) );

						if ( $refunded_qty < 0 ) {
							$refunded_qty *= -1;
						}

						/**
						 * (Re-) add qty refunded
						 */
						if ( $withdrawal_item->get_refunded_quantity() > 0 ) {
							$max_to_add = min( $refunded_qty, $withdrawal_item->get_refunded_quantity() );

							if ( $max_to_add > 0 ) {
								$total_quantity += $max_to_add;
							}
						}
					}
				}
			}
		}

		return $total_quantity;
	}

	/**
	 * @param \WC_DateTime|null $date_completed
	 * @param \WC_Order $order
	 *
	 * @return \WC_DateTime|null
	 */
	public static function date_delivered( $date_completed, $order ) {
		if ( ! $shipment_order = wc_stc_get_shipment_order( $order ) ) {
			return $date_completed;
		}

		if ( $shipment_order->get_date_delivered() ) {
			$date_completed = $shipment_order->get_date_delivered();
		} elseif ( $shipment_order->get_date_shipped() ) {
			$date_completed = $shipment_order->get_date_shipped();
		}

		return $date_completed;
	}

	/**
	 * @param boolean $is_withdrawable
	 * @param \WC_Order_Item_Product $order_item
	 * @param null|\WC_Order $order
	 *
	 * @return boolean
	 */
	public static function item_is_withdrawable( $is_withdrawable, $order_item, $order = null ) {
		if ( ! $order ) {
			$order = $order_item->get_order();
		}

		if ( ! $order ) {
			return $is_withdrawable;
		}

		if ( $shipment_order = wc_stc_get_shipment_order( $order ) ) {
			if ( $shipment_order->order_item_is_non_returnable( $order_item ) ) {
				$is_withdrawable = false;
			}
		}

		return $is_withdrawable;
	}

	/**
	 * @param \WC_Order $order
	 * @param WithdrawalOrder $request
	 *
	 * @return void
	 */
	public static function on_request_updated( $order, $request ) {
		if ( ! is_a( $request, '\Vendidero\OrderWithdrawalButton\WithdrawalOrder' ) ) {
			return;
		}

		if ( $shipment_order = wc_stc_get_shipment_order( $order ) ) {
			$shipments   = $shipment_order->get_simple_shipments();
			$has_removed = false;

			if ( $request->has_status( array( 'confirmed', 'rejected' ) ) ) {
				foreach ( $shipments as $shipment ) {
					if ( $shipment->is_editable() ) {
						$shipment_order->remove_shipment( $shipment->get_id() );
						$has_removed = true;
					}
				}

				if ( $has_removed ) {
					$shipment_order->save();
				}
			}

			$request_items = $request->get_items();

			if ( $request->has_status( 'confirmed' ) && ! empty( $request_items ) ) {
				$default_status   = apply_filters( 'woocommerce_shiptastic_withdrawal_return_shipment_status', 'requested', $order );
				$request_item_map = array();

				foreach ( $request_items as $item ) {
					if ( empty( $item->get_parent_id() ) ) {
						continue;
					}

					$request_item_map[ $item->get_parent_id() ] = array(
						'quantity' => $item->get_quantity(),
					);
				}

				if ( ! empty( $request_item_map ) ) {
					$result = $shipment_order->create_returns(
						$request_item_map,
						array(
							'status'                => $default_status,
							'is_customer_requested' => 'requested' === $default_status ? true : false,
						)
					);

					if ( ! is_wp_error( $result ) ) {
						foreach ( $result as $return ) {
							$return->update_meta_data( '_withdrawal_request', $request->get_id() );
							$return->save();
						}
					}
				}
			}
		}
	}
}
