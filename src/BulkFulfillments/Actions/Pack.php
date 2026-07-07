<?php

namespace Vendidero\Shiptastic\BulkFulfillments\Actions;

class Pack extends \Vendidero\Shiptastic\BulkFulfillments\FulfillmentAction {

	public static function get_title() {
		return _x( 'Pack', 'fulfillments', 'shiptastic-for-woocommerce' );
	}

	public static function get_description() {
		return _x( 'Pack shipments.', 'fulfillments', 'shiptastic-for-woocommerce' );
	}

	public static function get_supported_contexts() {
		return array( 'shipment' );
	}

	public function render() {
		// TODO: Implement render() method.
	}

	public function save() {
		// TODO: Implement save() method.
	}
}
