<?php

namespace Vendidero\Shiptastic\BulkFulfillments\Actions;

use Vendidero\Shiptastic\BulkFulfillments\Action;
use Vendidero\Shiptastic\BulkFulfillments\StepResult;

defined( 'ABSPATH' ) || exit;

/**
 * Packs a shipment: confirm packaging (and optionally weight/dimensions), then updates the shipment status
 * (default: ready for shipping).
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

	public static function get_settings_schema() {
		return array(
			'status_after_packing' => array(
				'type'    => 'select',
				'label'   => _x( 'Shipment status after packing', 'shipments', 'shiptastic-for-woocommerce' ),
				'default' => 'ready-for-shipping',
				'options' => array_intersect_key( wc_stc_get_shipment_statuses(), array_flip( array( 'processing', 'ready-for-shipping' ) ) ),
			),
		);
	}

	/**
	 * @param \Vendidero\Shiptastic\BulkFulfillments\StepContext $context
	 *
	 * @return array
	 */
	public function get_view_data( $context ) {
		$shipment = $context->get_shipment();

		if ( ! $shipment ) {
			return array();
		}

		$items = array();

		foreach ( $shipment->get_items() as $item ) {
			$items[] = array(
				'id'       => $item->get_order_item_id(),
				'name'     => $item->get_name(),
				'sku'      => $item->get_sku(),
				'gtin'     => $item->get_global_unique_id(),
				'quantity' => $item->get_quantity(),
			);
		}

		return array(
			'shipmentId'  => $shipment->get_id(),
			'items'       => $items,
			'packagingId' => $shipment->get_packaging_id(),
			'packaging'   => CreateShipments::get_packaging_data(),
			'weight'      => (float) $shipment->get_weight(),
			'length'      => (float) $shipment->get_length(),
			'width'       => (float) $shipment->get_width(),
			'height'      => (float) $shipment->get_height(),
		);
	}

	/**
	 * Expects packaging_id and optionally weight, length, width, height.
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

		$data         = wp_parse_args( (array) $data, array( 'packaging_id' => 0 ) );
		$packaging_id = absint( $data['packaging_id'] );

		if ( $packaging_id && ! wc_stc_get_packaging( $packaging_id ) ) {
			return StepResult::error( _x( 'The packaging does not exist.', 'shipments', 'shiptastic-for-woocommerce' ), 'invalid_packaging' );
		}

		$props = array();

		if ( $packaging_id ) {
			$props['packaging_id'] = $packaging_id;
		}

		foreach ( array( 'weight', 'length', 'width', 'height' ) as $prop ) {
			if ( isset( $data[ $prop ] ) && '' !== $data[ $prop ] ) {
				$props[ $prop ] = wc_format_decimal( $data[ $prop ] );
			}
		}

		$status = $context->get_setting( 'status_after_packing', 'ready-for-shipping' );

		$shipment->set_props( $props );

		if ( array_key_exists( $status, wc_stc_get_shipment_statuses() ) ) {
			$shipment->set_status( $status );
		}

		$shipment->save();

		return StepResult::done( $props );
	}
}
