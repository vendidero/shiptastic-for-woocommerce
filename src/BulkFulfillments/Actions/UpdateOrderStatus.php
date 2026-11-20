<?php

namespace Vendidero\Shiptastic\BulkFulfillments\Actions;

use Vendidero\Shiptastic\BulkFulfillments\Action;
use Vendidero\Shiptastic\BulkFulfillments\RunPhases;
use Vendidero\Shiptastic\BulkFulfillments\StepResult;

defined( 'ABSPATH' ) || exit;

/**
 * Updates the order status (e.g. to completed), by default after all other steps of the order. Runs automatically.
 *
 * Note: WooCommerce sends its e-mails for the new status (e.g. "Order completed"). Orders leaving the eligible
 * statuses of the fulfillment queue leave the queue once their steps are done.
 */
class UpdateOrderStatus extends Action {

	public static function get_name() {
		return 'update_order_status';
	}

	public static function get_title() {
		return _x( 'Update order status', 'shipments', 'shiptastic-for-woocommerce' );
	}

	public static function get_description() {
		return _x( 'Update the status of the order, e.g. to completed.', 'shipments', 'shiptastic-for-woocommerce' );
	}

	public static function get_scopes() {
		return array( 'order' );
	}

	/**
	 * After all other steps of the order (default) or at its position within the step list.
	 */
	public static function get_phases() {
		return array( RunPhases::AFTER_ITEM, RunPhases::ITEM );
	}

	public static function get_modes() {
		return array( 'background' );
	}

	/**
	 * The order status does not depend on shipments.
	 */
	public static function get_requires() {
		return array();
	}

	/**
	 * @return array status (without wc- prefix) => title
	 */
	public static function get_statuses() {
		$statuses = array();

		foreach ( wc_get_order_statuses() as $status => $title ) {
			$status = 'wc-' === substr( $status, 0, 3 ) ? substr( $status, 3 ) : $status;

			// Statuses not resulting from fulfillment.
			if ( in_array( $status, array( 'pending', 'failed', 'refunded', 'checkout-draft' ), true ) ) {
				continue;
			}

			$statuses[ $status ] = $title;
		}

		/**
		 * Filter the statuses offered by the update order status step.
		 *
		 * @param array $statuses status => title
		 *
		 * @package Vendidero/Shiptastic
		 */
		return (array) apply_filters( 'woocommerce_shiptastic_fulfillment_update_order_statuses', $statuses );
	}

	public static function get_settings_schema() {
		return array(
			'status' => array(
				'type'    => 'select',
				'label'   => _x( 'New status', 'shipments', 'shiptastic-for-woocommerce' ),
				'default' => 'completed',
				'options' => self::get_statuses(),
			),
		);
	}

	/**
	 * @param \Vendidero\Shiptastic\BulkFulfillments\StepContext $context
	 *
	 * @return StepResult
	 */
	public function run( $context ) {
		$order    = $context->order_shipment ? $context->order_shipment->get_order() : null;
		$statuses = self::get_statuses();
		$status   = (string) $context->get_setting( 'status', 'completed' );

		if ( ! $order ) {
			return StepResult::error( _x( 'The order does not exist.', 'shipments', 'shiptastic-for-woocommerce' ), 'no_order' );
		}

		if ( ! array_key_exists( $status, $statuses ) ) {
			/* translators: %s order status */
			return StepResult::error( sprintf( _x( 'The order status %s is not available.', 'shipments', 'shiptastic-for-woocommerce' ), $status ), 'invalid_status' );
		}

		if ( ! $order->has_status( $status ) ) {
			$workflow = $context->workflow ? $context->workflow->get_title() : '';
			/* translators: %s workflow title */
			$order->update_status( $status, $workflow ? sprintf( _x( 'Fulfillment workflow "%s":', 'shipments', 'shiptastic-for-woocommerce' ), $workflow ) : '' );
		}

		return StepResult::done( array( 'status' => $status ) );
	}
}
