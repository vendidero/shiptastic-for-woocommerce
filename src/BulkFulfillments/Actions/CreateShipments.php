<?php

namespace Vendidero\Shiptastic\BulkFulfillments\Actions;

class CreateShipments extends \Vendidero\Shiptastic\BulkFulfillments\FulfillmentAction {

	public static function get_title() {
		return _x( 'Create Shipments', 'fulfillments', 'shiptastic-for-woocommerce' );
	}

	public static function get_name() {
		return 'create_shipments';
	}

	public static function get_description() {
		return _x( 'Create shipments from the order items available to ship.', 'fulfillments', 'shiptastic-for-woocommerce' );
	}

	public static function get_must_run_before_actions() {
		return array();
	}

	public function render() {
		// TODO: Implement render() method.
	}
}
