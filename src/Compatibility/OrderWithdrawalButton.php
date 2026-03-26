<?php

namespace Vendidero\Shiptastic\Compatibility;

use Vendidero\Shiptastic\Interfaces\Compatibility;

defined( 'ABSPATH' ) || exit;

class OrderWithdrawalButton implements Compatibility {

	public static function is_active() {
		return function_exists( 'eu_owb_order_confirm_withdrawal_request' );
	}

	public static function init() {
		add_action( 'eu_owb_woocommerce_withdrawal_request_confirmed', array( __CLASS__, 'on_request_updated' ), 10, 2 );
		add_filter( 'eu_owb_woocommerce_order_item_is_withdrawable', array( __CLASS__, 'item_is_withdrawable' ), 10, 3 );
		add_filter( 'eu_owb_woocommerce_order_date_delivered_raw', array( __CLASS__, 'date_delivered' ), 10, 2 );
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
	 * @param array $request
	 *
	 * @return void
	 */
	public static function on_request_updated( $order, $request ) {
		if ( $shipment_order = wc_stc_get_shipment_order( $order ) ) {
			$shipments   = $shipment_order->get_simple_shipments();
			$has_removed = false;

			$request = wp_parse_args(
				$request,
				array(
					'items'  => array(),
					'status' => '',
					'id'     => '',
				)
			);

			if ( in_array( $request['status'], array( 'confirmed', 'rejected' ), true ) ) {
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

			if ( 'confirmed' === $request['status'] && ! empty( $request['items'] ) ) {
				$default_status = apply_filters( 'woocommerce_shiptastic_withdrawal_return_shipment_status', 'requested', $order );

				$result = $shipment_order->create_returns(
					$request['items'],
					array(
						'status'                => $default_status,
						'is_customer_requested' => 'requested' === $default_status ? true : false,
					)
				);

				if ( ! is_wp_error( $result ) ) {
					foreach ( $result as $return ) {
						$return->update_meta_data( '_withdrawal_request', $request['id'] );
						$return->save();
					}
				}
			}
		}
	}
}
