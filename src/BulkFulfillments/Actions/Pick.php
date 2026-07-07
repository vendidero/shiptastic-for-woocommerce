<?php

namespace Vendidero\Shiptastic\BulkFulfillments\Actions;

class Pick extends \Vendidero\Shiptastic\BulkFulfillments\FulfillmentAction {

	public static function get_title() {
		return _x( 'Pick', 'fulfillments', 'shiptastic-for-woocommerce' );
	}

	public static function get_description() {
		return _x( 'Pack items for shipments.', 'fulfillments', 'shiptastic-for-woocommerce' );
	}

	public static function get_name() {
		return 'pick';
	}

	public static function get_supported_contexts() {
		return array( 'shipment', 'order' );
	}

	public function render() {
		// TODO: Implement render() method.
	}
}
