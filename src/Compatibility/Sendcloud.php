<?php

namespace Vendidero\Shiptastic\Compatibility;

use Vendidero\Shiptastic\Extensions;
use Vendidero\Shiptastic\Interfaces\Compatibility;
use Vendidero\Shiptastic\Package;
use Vendidero\Shiptastic\Shipment;
use Vendidero\Shiptastic\ShippingProvider\Helper;

defined( 'ABSPATH' ) || exit;

class Sendcloud implements Compatibility {

	public static function is_active() {
		return Extensions::is_plugin_active( 'sendcloud-connected-shipping' );
	}

	public static function init() {
		add_filter( 'woocommerce_shiptastic_extract_tracking_info_from_string', array( __CLASS__, 'parse_tracking' ), 10, 2 );
		add_filter( 'woocommerce_shiptastic_order_note_tracking_mark_shipment_as_shipped', array( __CLASS__, 'maybe_mark_shipped' ), 10, 3 );
	}

	/**
	 * @param boolean $mark_as_shipped
	 * @param Shipment $shipment
	 * @param array $tracking_info
	 *
	 * @return boolean
	 */
	public static function maybe_mark_shipped( $mark_as_shipped, $shipment, $tracking_info ) {
		if ( 'sendcloud' === $tracking_info['third_party_provider'] ) {
			$mark_as_shipped = apply_filters( 'woocommerce_shiptastic_sendcloud_mark_shipment_as_shipped', false, $shipment );
		}

		return $mark_as_shipped;
	}

	/**
	 * @param array $tracking_info
	 * @param string $tracking_str
	 *
	 * @return array
	 */
	public static function parse_tracking( $tracking_info, $tracking_str ) {
		$tracking_info = wp_parse_args(
			$tracking_info,
			array(
				'third_party_provider' => '',
			)
		);

		if ( 'sendcloud' === $tracking_info['third_party_provider'] ) {
			preg_match( '/The (.*?) tracking number for/', $tracking_str, $matches );
			$shipping_provider_title = '';

			if ( 2 === count( $matches ) ) {
				$shipping_provider_title = $matches[1];
			}

			if ( ! empty( $shipping_provider_title ) ) {
				$tracking_info['shipping_provider_title'] = $shipping_provider_title;

				$provider = Helper::instance()->get_shipping_provider_by_title( $shipping_provider_title );
				$provider = apply_filters( 'woocommerce_shiptastic_sendcloud_shipping_provider', $provider, $shipping_provider_title );

				if ( $provider ) {
					$tracking_info['shipping_provider_name'] = $provider->get_name();
				}
			}
		}

		return $tracking_info;
	}
}
