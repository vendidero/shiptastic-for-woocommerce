<?php

namespace Vendidero\Shiptastic\BulkFulfillments;

defined( 'ABSPATH' ) || exit;

/**
 * Everything an action needs to execute a step.
 */
class StepContext {

	/**
	 * @var QueueItem
	 */
	public $queue_item;

	/**
	 * @var Workflow
	 */
	public $workflow;

	/**
	 * @var Step
	 */
	public $step;

	/**
	 * @var \Vendidero\Shiptastic\Order|null
	 */
	public $order_shipment;

	/**
	 * @var string interactive|background|scheduled
	 */
	public $source = 'interactive';

	/**
	 * @var string
	 */
	public $token = '';

	public function __construct( $queue_item, $workflow, $step, $source = 'interactive', $token = '' ) {
		$this->queue_item     = $queue_item;
		$this->workflow       = $workflow;
		$this->step           = $step;
		$this->source         = $source;
		$this->token          = $token;
		$this->order_shipment = $queue_item->get_shipment_order();
	}

	public function get_settings() {
		return (array) $this->step->settings;
	}

	public function get_setting( $name, $default_value = null ) {
		$settings = $this->get_settings();

		return array_key_exists( $name, $settings ) ? $settings[ $name ] : $default_value;
	}

	/**
	 * @return \Vendidero\Shiptastic\SimpleShipment|null The shipment for shipment scope steps.
	 */
	public function get_shipment() {
		if ( ! $this->step->shipment_id || ! $this->order_shipment ) {
			return null;
		}

		$shipment = $this->order_shipment->get_shipment( $this->step->shipment_id );

		return $shipment ? $shipment : null;
	}

	/**
	 * @return \Vendidero\Shiptastic\SimpleShipment[] Unshipped shipments of the queue item's package.
	 */
	public function get_package_shipments() {
		return Runner::get_package_shipments( $this->queue_item );
	}
}
