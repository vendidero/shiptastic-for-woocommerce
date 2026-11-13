<?php

namespace Vendidero\Shiptastic\BulkFulfillments\Actions;

use Vendidero\Shiptastic\BulkFulfillments\Action;
use Vendidero\Shiptastic\BulkFulfillments\StepResult;
use Vendidero\Shiptastic\BulkFulfillments\ShipmentLayout;

defined( 'ABSPATH' ) || exit;

/**
 * Creates the shipments of a queue item's package automatically (by packaging rules). Backordered units are never
 * added automatically. Within the fulfillment app shipments are edited via the shipments overlay (see ShipmentLayout),
 * which is available during the whole run - that's why this action has no interactive mode.
 */
class CreateShipments extends Action {

	public static function get_name() {
		return 'create_shipments';
	}

	public static function get_title() {
		return _x( 'Create shipments', 'shipments', 'shiptastic-for-woocommerce' );
	}

	public static function get_description() {
		return _x( 'Create shipments from the items available to ship.', 'shipments', 'shiptastic-for-woocommerce' );
	}

	public static function get_modes() {
		return array( 'background' );
	}

	public static function get_requires() {
		return array();
	}

	/**
	 * @param \Vendidero\Shiptastic\BulkFulfillments\StepContext $context
	 *
	 * @return StepResult
	 */
	public function run( $context ) {
		$order_shipment = $context->order_shipment;

		if ( ! $order_shipment ) {
			return StepResult::error( _x( 'The order does not exist.', 'shipments', 'shiptastic-for-woocommerce' ), 'order_not_found' );
		}

		$created = $order_shipment->create_shipments(
			ShipmentLayout::get_default_status(),
			array(
				'location'        => $context->queue_item->get_location(),
				'shipping_method' => $context->queue_item->get_shipping_method(),
			)
		);

		if ( is_wp_error( $created ) ) {
			return StepResult::from_wp_error( $created );
		}

		$shipments = $context->get_package_shipments();

		if ( empty( $shipments ) ) {
			return StepResult::error( _x( 'No shipment could be created, e.g. because all items are on backorder.', 'shipments', 'shiptastic-for-woocommerce' ), 'no_shipments' );
		}

		return StepResult::done( array( 'shipment_ids' => array_keys( $shipments ) ) );
	}
}
