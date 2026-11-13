<?php

namespace Vendidero\Shiptastic\BulkFulfillments\Actions;

use Vendidero\Shiptastic\BulkFulfillments\Action;
use Vendidero\Shiptastic\BulkFulfillments\StepResult;
use Vendidero\Shiptastic\Warehouse\StorageLocations;

defined( 'ABSPATH' ) || exit;

/**
 * Picks the items of a queue item's package shipments, sorted along the walking path. Picking less than required
 * (short pick) fails the step.
 *
 * Batch picking (before_queue phase, aggregated over a batch of queue items) follows with milestone 8.
 */
class Pick extends Action {

	public static function get_name() {
		return 'pick';
	}

	public static function get_title() {
		return _x( 'Pick', 'shipments', 'shiptastic-for-woocommerce' );
	}

	public static function get_description() {
		return _x( 'Pick the items from the storage locations.', 'shipments', 'shiptastic-for-woocommerce' );
	}

	/**
	 * @param \Vendidero\Shiptastic\BulkFulfillments\StepContext $context
	 *
	 * @return array order_item_id => quantity to pick
	 */
	public static function get_required_quantities( $context ) {
		$quantities = array();

		foreach ( $context->get_package_shipments() as $shipment ) {
			foreach ( $shipment->get_items() as $item ) {
				if ( $item->get_item_parent_id() > 0 ) {
					continue;
				}

				$order_item_id                = $item->get_order_item_id();
				$quantities[ $order_item_id ] = ( isset( $quantities[ $order_item_id ] ) ? $quantities[ $order_item_id ] : 0 ) + $item->get_quantity();
			}
		}

		return $quantities;
	}

	/**
	 * @param \Vendidero\Shiptastic\BulkFulfillments\StepContext $context
	 *
	 * @return array
	 */
	public function get_view_data( $context ) {
		$order_shipment = $context->order_shipment;
		$order          = $order_shipment->get_order();
		$by_product     = array();
		$items          = array();

		foreach ( self::get_required_quantities( $context ) as $order_item_id => $quantity ) {
			if ( ! $order_item = $order->get_item( $order_item_id ) ) {
				continue;
			}

			$product_id = is_callable( array( $order_item, 'get_variation_id' ) ) && $order_item->get_variation_id() ? $order_item->get_variation_id() : ( is_callable( array( $order_item, 'get_product_id' ) ) ? $order_item->get_product_id() : 0 );

			$items[ $order_item_id ] = array_merge(
				CreateShipments::get_item_data( $order_shipment, $order_item ),
				array(
					'quantity'   => $quantity,
					'product_id' => $product_id,
					'location'   => '',
					'warehouse'  => '',
				)
			);

			$by_product[ $product_id ][] = $order_item_id;
		}

		$locations = StorageLocations::get_product_locations( array_keys( $by_product ) );
		$sorted    = array();

		foreach ( StorageLocations::sort_product_ids( array_keys( $by_product ) ) as $product_id ) {
			foreach ( $by_product[ $product_id ] as $order_item_id ) {
				if ( isset( $locations[ $product_id ] ) ) {
					$items[ $order_item_id ]['location']  = $locations[ $product_id ]['location_code'];
					$items[ $order_item_id ]['warehouse'] = $locations[ $product_id ]['warehouse'];
				}

				$sorted[] = $items[ $order_item_id ];
			}
		}

		return array( 'items' => $sorted );
	}

	/**
	 * Expects picked: order_item_id => quantity.
	 *
	 * @param \Vendidero\Shiptastic\BulkFulfillments\StepContext $context
	 * @param array $data
	 *
	 * @return StepResult
	 */
	public function process( $context, $data ) {
		$picked   = array();
		$short    = array();
		$order    = $context->order_shipment->get_order();
		$required = self::get_required_quantities( $context );

		foreach ( (array) ( isset( $data['picked'] ) ? $data['picked'] : array() ) as $order_item_id => $quantity ) {
			$picked[ absint( $order_item_id ) ] = absint( $quantity );
		}

		foreach ( $required as $order_item_id => $quantity ) {
			$picked_quantity = isset( $picked[ $order_item_id ] ) ? $picked[ $order_item_id ] : 0;

			if ( $picked_quantity < $quantity ) {
				$order_item = $order->get_item( $order_item_id );
				$short[]    = sprintf( '%1$d/%2$d &times; %3$s', $picked_quantity, $quantity, $order_item ? $order_item->get_name() : $order_item_id );
			}
		}

		if ( ! empty( $short ) ) {
			/* translators: %s list of items */
			return StepResult::error( sprintf( _x( 'Short pick: %s', 'shipments', 'shiptastic-for-woocommerce' ), wp_strip_all_tags( html_entity_decode( implode( ', ', $short ) ) ) ), 'short_pick', array( 'picked' => $picked ) );
		}

		return StepResult::done( array( 'picked' => array_intersect_key( $picked, $required ) ) );
	}
}
