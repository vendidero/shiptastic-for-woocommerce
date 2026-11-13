<?php
/**
 * BulkFulfillment Factory
 *
 * The factory creates the bulk fulfillment objects.
 *
 * @version 1.0.0
 */
namespace Vendidero\Shiptastic\BulkFulfillments;

use Exception;

defined( 'ABSPATH' ) || exit;

/**
 * Label factory class
 */
class Factory {

	public static function get_available_fulfillment_actions() {
		return array(
			'create_shipments' => '\Vendidero\Shiptastic\BulkFulfillments\Actions\CreateShipments',
			'pack'             => '\Vendidero\Shiptastic\BulkFulfillments\Actions\Pack',
			'pick'             => '\Vendidero\Shiptastic\BulkFulfillments\Actions\Pick',
		);
	}

	public static function get_fulfillment_action( $action_name, $args = array() ) {
		$classname = null;
		$available = self::get_available_fulfillment_actions();

		if ( array_key_exists( $action_name, $available ) ) {
			$classname = $available[ $action_name ];
		}

		$classname = apply_filters( 'woocommerce_shiptastic_bulk_fulfillment_action_class', $classname, $action_name, $args );

		if ( ! class_exists( $classname ) ) {
			return false;
		}

		try {
			$action = new $classname( $args );

			if ( ! is_a( $action, '\Vendidero\Shiptastic\BulkFulfillments\FulfillmentAction' ) ) {
				throw new Exception( 'Fulfillment action does not exist' );
			}

			return $action;
		} catch ( Exception $e ) {
			wc_caught_exception( $e, __FUNCTION__, array( $action_name ) );
			return false;
		}
	}
}
