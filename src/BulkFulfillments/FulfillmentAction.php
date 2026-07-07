<?php

namespace Vendidero\Shiptastic\BulkFulfillments;

use Vendidero\Shiptastic\Shipment;
use Vendidero\Shiptastic\ShipmentError;

defined( 'ABSPATH' ) || exit;

abstract class FulfillmentAction {

	/**
	 * @var BulkFulfillmentOrder
	 */
	protected $order = null;

	/**
	 * @var Shipment|null
	 */
	protected $shipment = null;

	protected $settings = array();

	protected $data = array();

	public function __construct( $args = array() ) {
		$args = wp_parse_args(
			$args,
			array(
				'order'    => null,
				'shipment' => null,
				'settings' => array(),
				'data'     => array(),
			)
		);

		$this->set_order( $args['order'] );
		$this->set_shipment( $args['shipment'] );
		$this->set_settings( $args['settings'] );
		$this->set_data( $args['data'] );
	}

	abstract public static function get_title();

	abstract public static function get_name();

	abstract public static function get_description();

	public static function get_supported_types() {
		return array(
			'manual',
			'auto',
		);
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
	public static function get_must_run_before_actions() {
		return array(
			'create_shipments',
		);
	}

	/**
	 * The context of the fulfillment action, either order or shipment.
	 *
	 * @return string[]
	 */
	public static function get_supported_contexts() {
		return array( 'order' );
	}

	public static function get_setting_fields() {
		return array();
	}

	abstract public function render();

	/**
	 * @return ShipmentError|array
	 */
	public function save() {
		if ( $this->get_order() ) {
			$this->get_order()->update_action( $this );
		}
	}

	/**
	 * @return BulkFulfillmentOrder
	 */
	public function get_order() {
		return $this->order;
	}

	/**
	 * @param BulkFulfillmentOrder $order
	 *
	 * @return void
	 */
	public function set_order( $order ) {
		$this->order = $order;
	}

	/**
	 * @return Shipment|null
	 */
	public function get_shipment() {
		return $this->shipment;
	}

	/**
	 * @param Shipment $shipment
	 *
	 * @return void
	 */
	public function set_shipment( $shipment ) {
		$this->shipment = $shipment;
	}

	public function get_settings() {
		return $this->settings;
	}

	public function set_settings( $settings ) {
		$settings = wp_parse_args(
			$settings,
			array(
				'sort_order' => 999,
			)
		);

		$this->settings = $settings;
	}

	public function get_setting( $name, $default_value = null ) {
		if ( array_key_exists( $name, $this->settings ) ) {
			return $this->settings[ $name ];
		} else {
			return $default_value;
		}
	}

	public function get_data() {
		return $this->data;
	}

	public function set_data( $data ) {
		$data = wp_parse_args(
			$data,
			array(
				'status' => 'open',
			)
		);

		$this->data = $data;
	}

	public function get_data_entry( $name, $default_value = null ) {
		if ( array_key_exists( $name, $this->data ) ) {
			return $this->data[ $name ];
		} else {
			return $default_value;
		}
	}

	public function get_context() {
		$supported = self::get_supported_contexts();

		return $this->get_setting( 'context', array_values( $supported )[0] );
	}

	public function get_status() {
		return $this->get_data_entry( 'status', 'open' );
	}

	public function get_data_key() {
		$name = $this->get_name();

		if ( $shipment = $this->get_shipment() ) {
			$name .= "_{$shipment->get_id()}";
		}

		return $name;
	}

	public function update_data_entry( $key, $value ) {
		$data         = $this->get_data();
		$data[ $key ] = $value;

		$this->set_data( $data );
	}

	public function get_run_context() {
		$supported = self::get_supported_run_contexts();

		return $this->get_setting( 'run_context', array_values( $supported )[0] );
	}

	public function get_sort_order() {
		return $this->get_setting( 'sort_order', -1 );
	}
}
