<?php

namespace Vendidero\Shiptastic\BulkFulfillments\Actions;

use Vendidero\Shiptastic\BulkFulfillments\Action;
use Vendidero\Shiptastic\BulkFulfillments\StepResult;

defined( 'ABSPATH' ) || exit;

/**
 * Updates the status of a shipment, e.g. to ready for shipping (default). Validates the shipment first, e.g. a shipping
 * provider is needed to create labels later on. Replaces the former `mark_ready` step (saved steps are migrated, see
 * Workflow::migrate_steps()).
 *
 * Note: shipments set to shipped/delivered leave the fulfillment process (no further steps for them).
 */
class UpdateShipmentStatus extends Action {

	public static function get_name() {
		return 'update_shipment_status';
	}

	public static function get_title() {
		return _x( 'Update shipment status', 'shipments', 'shiptastic-for-woocommerce' );
	}

	public static function get_description() {
		return _x( 'Validate the shipment and update its status, e.g. to ready for shipping.', 'shipments', 'shiptastic-for-woocommerce' );
	}

	public static function get_scopes() {
		return array( 'shipment' );
	}

	/**
	 * Runs automatically by default, may be confirmed per shipment within the app instead.
	 */
	public static function get_modes() {
		return array( 'background', 'interactive' );
	}

	/**
	 * @return array status => title of the statuses a shipment may be updated to.
	 */
	public static function get_statuses() {
		$statuses = array_intersect_key( wc_stc_get_shipment_statuses(), array_flip( array( 'processing', 'ready-for-shipping', 'shipped', 'delivered' ) ) );

		/**
		 * Filter the statuses offered by the update shipment status step.
		 *
		 * @param array $statuses status => title
		 *
		 * @package Vendidero/Shiptastic
		 */
		return (array) apply_filters( 'woocommerce_shiptastic_fulfillment_update_shipment_statuses', $statuses );
	}

	public static function get_settings_schema() {
		return array(
			'status'                    => array(
				'type'    => 'select',
				'label'   => _x( 'New status', 'shipments', 'shiptastic-for-woocommerce' ),
				'default' => 'ready-for-shipping',
				'options' => self::get_statuses(),
			),
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
	 * @return string The status to set.
	 */
	protected function get_status( $context ) {
		$status = (string) $context->get_setting( 'status', 'ready-for-shipping' );

		return array_key_exists( $status, self::get_statuses() ) ? $status : 'ready-for-shipping';
	}

	/**
	 * Review screen (interactive mode): the status the shipment is updated to.
	 */
	public function get_view_data( $context ) {
		$data     = parent::get_view_data( $context );
		$statuses = self::get_statuses();
		$status   = $this->get_status( $context );

		/* translators: %s shipment status */
		$data['description'] = sprintf( _x( 'Validate the shipment and update its status to %s.', 'shipments', 'shiptastic-for-woocommerce' ), $statuses[ $status ] );

		return $data;
	}

	/**
	 * @param \Vendidero\Shiptastic\BulkFulfillments\StepContext $context
	 *
	 * @return StepResult
	 */
	public function run( $context ) {
		$shipment = $context->get_shipment();
		$status   = $this->get_status( $context );

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
		 * Filter to validate a shipment before its status is updated within a fulfillment workflow.
		 *
		 * @param true|\WP_Error $valid
		 * @param \Vendidero\Shiptastic\Shipment $shipment
		 * @param string $status The new status.
		 * @param \Vendidero\Shiptastic\BulkFulfillments\StepContext $context
		 *
		 * @package Vendidero/Shiptastic
		 */
		$valid = apply_filters( 'woocommerce_shiptastic_fulfillment_validate_shipment_status', true, $shipment, $status, $context );

		if ( is_wp_error( $valid ) ) {
			return StepResult::from_wp_error( $valid );
		}

		// Validated in any case, e.g. packing may have set the status already.
		if ( ! $shipment->has_status( $status ) ) {
			$shipment->set_status( $status );
			$shipment->save();
		}

		return StepResult::done( array( 'status' => $status ) );
	}
}
