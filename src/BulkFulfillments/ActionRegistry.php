<?php

namespace Vendidero\Shiptastic\BulkFulfillments;

defined( 'ABSPATH' ) || exit;

/**
 * Registry of available fulfillment actions.
 */
class ActionRegistry {

	protected static $instances = array();

	/**
	 * @return string[] name => class name
	 */
	public static function get_action_classes() {
		/**
		 * Filter to register fulfillment actions: action name => class name (extending `Action`).
		 *
		 * @param string[] $actions
		 *
		 * @package Vendidero/Shiptastic
		 */
		return (array) apply_filters(
			'woocommerce_shiptastic_fulfillment_actions',
			array(
				'create_shipments' => '\Vendidero\Shiptastic\BulkFulfillments\Actions\CreateShipments',
				'pick'             => '\Vendidero\Shiptastic\BulkFulfillments\Actions\Pick',
				'pack'             => '\Vendidero\Shiptastic\BulkFulfillments\Actions\Pack',
			)
		);
	}

	/**
	 * @param string $name
	 *
	 * @return Action|null
	 */
	public static function get( $name ) {
		$classes = self::get_action_classes();

		if ( ! isset( $classes[ $name ] ) || ! class_exists( $classes[ $name ] ) || ! is_subclass_of( $classes[ $name ], '\Vendidero\Shiptastic\BulkFulfillments\Action' ) ) {
			return null;
		}

		if ( ! isset( self::$instances[ $name ] ) || get_class( self::$instances[ $name ] ) !== ltrim( $classes[ $name ], '\\' ) ) {
			self::$instances[ $name ] = new $classes[ $name ]();
		}

		return self::$instances[ $name ];
	}

	public static function exists( $name ) {
		return ! is_null( self::get( $name ) );
	}

	/**
	 * @return Action[]
	 */
	public static function get_all() {
		$actions = array();

		foreach ( array_keys( self::get_action_classes() ) as $name ) {
			if ( $action = self::get( $name ) ) {
				$actions[ $name ] = $action;
			}
		}

		return $actions;
	}
}
