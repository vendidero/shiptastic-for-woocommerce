<?php

namespace Vendidero\Shiptastic\BulkFulfillments\Actions;

use Vendidero\Shiptastic\BulkFulfillments\Action;
use Vendidero\Shiptastic\BulkFulfillments\StepResult;

defined( 'ABSPATH' ) || exit;

/**
 * Marks a shipment as ready for shipping (automatable). Validates the shipment first, e.g. a shipping provider is
 * needed to create labels later on.
 */
class MarkReady extends Action {

	public static function get_name() {
		return 'mark_ready';
	}

	public static function get_title() {
		return _x( 'Mark as ready for shipping', 'shipments', 'shiptastic-for-woocommerce' );
	}

	public static function get_description() {
		return _x( 'Validate the shipment and mark it as ready for shipping.', 'shipments', 'shiptastic-for-woocommerce' );
	}

	public static function get_scopes() {
		return array( 'shipment' );
	}

	/**
	 * Right after each shipment's steps (default, e.g. after packing it, before the next shipment) or once all
	 * shipments went through the previous steps.
	 */
	public static function get_phases() {
		return array( \Vendidero\Shiptastic\BulkFulfillments\RunPhases::AFTER_SHIPMENT, \Vendidero\Shiptastic\BulkFulfillments\RunPhases::ITEM );
	}

	/**
	 * Runs automatically by default, may be confirmed per shipment within the app instead.
	 */
	public static function get_modes() {
		return array( 'background', 'interactive' );
	}

	public static function get_settings_schema() {
		return array(
			'require_shipping_provider' => array(
				'type'    => 'checkbox',
				'label'   => _x( 'Require a shipping provider', 'shipments', 'shiptastic-for-woocommerce' ),
				'default' => true,
			),
			'require_packaging'         => array(
				'type'    => 'checkbox',
				'label'   => _x( 'Require a packaging', 'shipments', 'shiptastic-for-woocommerce' ),
				'default' => false,
			),
		);
	}

	/**
	 * @param \Vendidero\Shiptastic\BulkFulfillments\StepContext $context
	 *
	 * @return StepResult
	 */
	public function run( $context ) {
		$shipment = $context->get_shipment();

		if ( ! $shipment ) {
			return StepResult::error( _x( 'There is no shipment, e.g. because all items are on backorder.', 'shipments', 'shiptastic-for-woocommerce' ), 'no_shipment' );
		}

		if ( 0 === count( $shipment->get_items() ) ) {
			/* translators: %d shipment id */
			return StepResult::error( sprintf( _x( 'Shipment #%d does not contain any items.', 'shipments', 'shiptastic-for-woocommerce' ), $shipment->get_id() ), 'shipment_empty' );
		}

		if ( wc_string_to_bool( $context->get_setting( 'require_shipping_provider', true ) ) && '' === (string) $shipment->get_shipping_provider() ) {
			/* translators: %d shipment id */
			return StepResult::error( sprintf( _x( 'Shipment #%d has no shipping provider.', 'shipments', 'shiptastic-for-woocommerce' ), $shipment->get_id() ), 'missing_shipping_provider' );
		}

		if ( wc_string_to_bool( $context->get_setting( 'require_packaging', false ) ) && ! $shipment->get_packaging_id() ) {
			/* translators: %d shipment id */
			return StepResult::error( sprintf( _x( 'Shipment #%d has no packaging.', 'shipments', 'shiptastic-for-woocommerce' ), $shipment->get_id() ), 'missing_packaging' );
		}

		/**
		 * Filter to validate a shipment before it is marked as ready for shipping.
		 *
		 * @param true|\WP_Error $valid
		 * @param \Vendidero\Shiptastic\Shipment $shipment
		 * @param \Vendidero\Shiptastic\BulkFulfillments\StepContext $context
		 *
		 * @package Vendidero/Shiptastic
		 */
		$valid = apply_filters( 'woocommerce_shiptastic_fulfillment_validate_ready_for_shipping', true, $shipment, $context );

		if ( is_wp_error( $valid ) ) {
			return StepResult::from_wp_error( $valid );
		}

		// Validated in any case, e.g. packing may have set the status already.
		if ( ! $shipment->has_status( 'ready-for-shipping' ) ) {
			$shipment->set_status( 'ready-for-shipping' );
			$shipment->save();
		}

		return StepResult::done( array( 'status' => 'ready-for-shipping' ) );
	}
}
