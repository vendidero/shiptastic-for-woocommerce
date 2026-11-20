<?php

namespace Vendidero\Shiptastic\BulkFulfillments\Actions;

use Vendidero\Shiptastic\BulkFulfillments\Action;
use Vendidero\Shiptastic\BulkFulfillments\ShipmentLayout;
use Vendidero\Shiptastic\BulkFulfillments\StepResult;

defined( 'ABSPATH' ) || exit;

/**
 * Packs a shipment: verify the items (scan, swipe or count), confirm packaging, provider, weight and dimensions, then
 * updates the shipment status (default: ready for shipping).
 */
class Pack extends Action {

	public static function get_name() {
		return 'pack';
	}

	public static function get_title() {
		return _x( 'Pack', 'shipments', 'shiptastic-for-woocommerce' );
	}

	public static function get_description() {
		return _x( 'Pack the shipment and confirm the packaging.', 'shipments', 'shiptastic-for-woocommerce' );
	}

	public static function get_scopes() {
		return array( 'shipment' );
	}

	/**
	 * The shipment's items to verify (by scanning, swiping or counting) and its packaging + dimensions to use.
	 * Shipment details are edited via the shipments overlay.
	 *
	 * @param \Vendidero\Shiptastic\BulkFulfillments\StepContext $context
	 *
	 * @return array
	 */
	public function get_view_data( $context ) {
		$shipment = $context->get_shipment();

		if ( ! $shipment ) {
			return array();
		}

		$order_shipment = $context->order_shipment;
		$order          = $order_shipment->get_order();
		$items          = array();

		foreach ( ShipmentLayout::get_shipment_item_map( $shipment ) as $line_key => $quantity ) {
			list( $order_item_id, $inner_box ) = ShipmentLayout::parse_line_key( $line_key );

			if ( $order_item = $order->get_item( $order_item_id ) ) {
				$items[] = array_merge(
					ShipmentLayout::get_item_data( $order_shipment, $order_item, $inner_box ),
					array(
						'quantity' => $quantity,
						'location' => '',
					)
				);
			}
		}

		$packaging = $shipment->get_packaging();

		return array(
			'shipmentId'  => $shipment->get_id(),
			'items'       => $items,
			// Shown to make sure the right packaging is used, the best fitting packaging is suggested (packing.js).
			'packaging'   => $packaging ? html_entity_decode( wp_strip_all_tags( $packaging->get_title() ), ENT_QUOTES, get_bloginfo( 'charset' ) ) : '',
			'packagingId' => $shipment->get_packaging_id(),
			'provider'    => $shipment->get_shipping_provider(),
			'dimensions'  => html_entity_decode( wp_strip_all_tags( wc_stc_format_shipment_dimensions( $shipment->get_package_dimensions(), $shipment->get_dimension_unit() ) ), ENT_QUOTES, get_bloginfo( 'charset' ) ),
		);
	}

	/**
	 * Expects packed (order_item_id => quantity, all items of the shipment must be packed) and optionally the
	 * shipment props packaging_id, provider, weight, length, width, height (see ShipmentLayout::apply_props()).
	 * Missing items need to be removed from the shipment via the shipments overlay.
	 *
	 * @param \Vendidero\Shiptastic\BulkFulfillments\StepContext $context
	 * @param array $data
	 *
	 * @return StepResult
	 */
	public function process( $context, $data ) {
		$shipment = $context->get_shipment();

		if ( ! $shipment ) {
			return StepResult::error( _x( 'There is no shipment to pack, e.g. because all items are on backorder.', 'shipments', 'shiptastic-for-woocommerce' ), 'no_shipment' );
		}

		$data   = (array) $data;
		$packed = array();
		$short  = array();

		foreach ( (array) ( isset( $data['packed'] ) ? $data['packed'] : array() ) as $line_key => $quantity ) {
			$packed[ ShipmentLayout::normalize_line_key( $line_key ) ] = absint( $quantity );
		}

		foreach ( ShipmentLayout::get_shipment_item_map( $shipment ) as $line_key => $quantity ) {
			$packed_quantity = isset( $packed[ $line_key ] ) ? $packed[ $line_key ] : 0;

			if ( $packed_quantity < $quantity ) {
				list( $order_item_id, $inner_box ) = ShipmentLayout::parse_line_key( $line_key );
				$shipment_item                     = $shipment->get_item_by_order_item_id( $order_item_id, $inner_box );
				$short[]                           = sprintf( '%1$d/%2$d × %3$s', $packed_quantity, $quantity, $shipment_item ? $shipment_item->get_name() : $order_item_id );
			}
		}

		if ( ! empty( $short ) ) {
			/* translators: %s list of items */
			return StepResult::error( sprintf( _x( 'Not packed yet: %s. Remove missing items from the shipment in case they are not available.', 'shipments', 'shiptastic-for-woocommerce' ), implode( ', ', $short ) ), 'short_pack', array( 'packed' => $packed ) );
		}

		$props   = array_intersect_key( $data, array_flip( array( 'packaging_id', 'provider', 'weight', 'length', 'width', 'height' ) ) );
		$applied = ShipmentLayout::apply_props( $shipment, $props );

		if ( is_wp_error( $applied ) ) {
			return StepResult::from_wp_error( $applied );
		}

		// The shipment status is not changed: updating it (e.g. to ready for shipping) is a separate step (update_shipment_status).
		$shipment->save();

		return StepResult::done( $props );
	}
}
