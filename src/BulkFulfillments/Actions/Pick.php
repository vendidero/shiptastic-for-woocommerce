<?php

namespace Vendidero\Shiptastic\BulkFulfillments\Actions;

use Vendidero\Shiptastic\BulkFulfillments\Action;
use Vendidero\Shiptastic\BulkFulfillments\ShipmentLayout;
use Vendidero\Shiptastic\BulkFulfillments\StepResult;
use Vendidero\Shiptastic\Packing\InnerBoxes;
use Vendidero\Shiptastic\Warehouse\LocationSchema;
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
	 * @return array line key => quantity to pick (inner boxes are picked separately)
	 */
	public static function resets_on_shipment_changes() {
		return true;
	}

	public static function get_required_quantities( $context ) {
		$quantities = array();

		foreach ( $context->get_package_shipments() as $shipment ) {
			foreach ( ShipmentLayout::get_shipment_item_map( $shipment ) as $line_key => $quantity ) {
				$quantities[ $line_key ] = ( isset( $quantities[ $line_key ] ) ? $quantities[ $line_key ] : 0 ) + $quantity;
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
		$items          = array();
		$sort_entries   = array();
		$product_ids    = array();
		$lines          = array();

		foreach ( self::get_required_quantities( $context ) as $line_key => $quantity ) {
			list( $order_item_id, $inner_box ) = ShipmentLayout::parse_line_key( $line_key );

			if ( ! $order_item = $order->get_item( $order_item_id ) ) {
				continue;
			}

			$product_id    = is_callable( array( $order_item, 'get_variation_id' ) ) && $order_item->get_variation_id() ? $order_item->get_variation_id() : ( is_callable( array( $order_item, 'get_product_id' ) ) ? $order_item->get_product_id() : 0 );
			$product_ids[] = $product_id;

			$lines[ $line_key ] = array( $order_item, $inner_box, $product_id, $quantity );
		}

		$locations = StorageLocations::get_product_locations( $product_ids );

		foreach ( $lines as $line_key => $line ) {
			list( $order_item, $inner_box, $product_id, $quantity ) = $line;

			$location  = isset( $locations[ $product_id ] ) ? $locations[ $product_id ]['location_code'] : '';
			$warehouse = isset( $locations[ $product_id ] ) ? $locations[ $product_id ]['warehouse'] : wc_stc_get_product_warehouse_name( $product_id );

			/**
			 * Inner boxes may be stored at a custom storage location (within the product's warehouse).
			 */
			if ( '' !== $inner_box && ( $box = InnerBoxes::get_order_item_box( $order_item, $inner_box ) ) && ! empty( $box['location'] ) ) {
				$location = LocationSchema::normalize_code( $box['location'] );
			}

			$items[ $line_key ] = array_merge(
				ShipmentLayout::get_item_data( $order_shipment, $order_item, $inner_box ),
				array(
					'quantity'   => $quantity,
					'product_id' => $product_id,
					'location'   => $location,
					'warehouse'  => '' !== $location ? $warehouse : '',
				)
			);

			$sort_entries[ $line_key ] = array(
				'warehouse' => $warehouse,
				'location'  => $location,
			);
		}

		$sorted = array();

		foreach ( StorageLocations::sort_entries( $sort_entries ) as $line_key ) {
			$sorted[] = $items[ $line_key ];
		}

		return array( 'items' => $sorted );
	}

	/**
	 * Expects picked: line key => quantity.
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

		foreach ( (array) ( isset( $data['picked'] ) ? $data['picked'] : array() ) as $line_key => $quantity ) {
			$picked[ ShipmentLayout::normalize_line_key( $line_key ) ] = absint( $quantity );
		}

		foreach ( $required as $line_key => $quantity ) {
			$picked_quantity = isset( $picked[ $line_key ] ) ? $picked[ $line_key ] : 0;

			if ( $picked_quantity < $quantity ) {
				list( $order_item_id, $inner_box ) = ShipmentLayout::parse_line_key( $line_key );
				$order_item                        = $order->get_item( $order_item_id );
				$short[]                           = sprintf( '%1$d/%2$d &times; %3$s', $picked_quantity, $quantity, $order_item ? ShipmentLayout::get_item_data( $context->order_shipment, $order_item, $inner_box )['name'] : $line_key );
			}
		}

		if ( ! empty( $short ) ) {
			/* translators: %s list of items */
			return StepResult::error( sprintf( _x( 'Short pick: %s', 'shipments', 'shiptastic-for-woocommerce' ), wp_strip_all_tags( html_entity_decode( implode( ', ', $short ) ) ) ), 'short_pick', array( 'picked' => $picked ) );
		}

		return StepResult::done( array( 'picked' => array_intersect_key( $picked, $required ) ) );
	}
}
