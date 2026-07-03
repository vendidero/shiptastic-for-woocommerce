<?php

namespace Vendidero\Shiptastic\BulkFulfillments;

use Vendidero\Shiptastic\ShipmentError;

defined( 'ABSPATH' ) || exit;

abstract class FulfillmentAction {

	/**
	 * @var BulkFulfillment
	 */
	protected $fulfillment = null;

	/**
	 * @var BulkFulfillmentOrder
	 */
	protected $order = null;

	/**
	 * @param BulkFulfillment $fulfillment
	 * @param BulkFulfillmentOrder $order
	 */
	public function __construct( $fulfillment, $order ) {
		$this->fulfillment = $fulfillment;
		$this->order       = $order;
	}

	abstract public static function get_title();

	abstract public static function get_description();

	public static function get_supported_types() {
		return array(
			'manual',
			'auto',
		);
	}

	public function get_name() {
		return sanitize_key( static::get_title() );
	}

	/**
	 * Allow an action to run before/after or within the queue.
	 * E.g. picking action may run before queue allowing to pick all orders at once before starting the queue.
	 *
	 * @return string[]
	 */
	public static function get_supported_run_contexts() {
		return array(
			'queue',
		);
	}

	/**
	 * Actions that must run before this action can run.
	 *
	 * @return array
	 */
	public static function get_depending_actions() {
		return array(
			'create_shipments',
		);
	}

	/**
	 * The context of the fulfillment action, either order or shipment.
	 *
	 * @return string
	 */
	public static function get_context() {
		return 'order';
	}

	public static function get_settings() {
		return array();
	}

	abstract public function render();

	/**
	 * @return ShipmentError|array
	 */
	abstract public function save();

	/**
	 * @return BulkFulfillment
	 */
	public function get_fulfillment() {
		return $this->fulfillment;
	}

	/**
	 * @return BulkFulfillmentOrder
	 */
	public function get_order() {
		return $this->order;
	}

	public function get_setting( $name, $default_value = null ) {
	}
}
