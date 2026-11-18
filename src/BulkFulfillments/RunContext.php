<?php

namespace Vendidero\Shiptastic\BulkFulfillments;

defined( 'ABSPATH' ) || exit;

/**
 * Everything an action needs to execute a step of a run level phase (see RunPhases): the run, the phase and the
 * orders (queue items) the step covers - the orders of the batch (before_queue) or the orders completed within the
 * run (after_queue).
 */
class RunContext {

	/**
	 * @var Run
	 */
	public $run;

	/**
	 * @var Workflow
	 */
	public $workflow;

	/**
	 * @var Step
	 */
	public $step;

	/**
	 * @var string before_queue|after_queue
	 */
	public $phase = '';

	/**
	 * @var int The batch (before_queue), 0 for the end of the run.
	 */
	public $batch = 0;

	/**
	 * @var int[]
	 */
	public $queue_ids = array();

	/**
	 * @var string interactive|background|scheduled
	 */
	public $source = 'background';

	/**
	 * @var QueueItem[]|null
	 */
	protected $queue_items = null;

	public function __construct( $run, $workflow, $step, $phase, $batch, $queue_ids, $source = 'background' ) {
		$this->run       = $run;
		$this->workflow  = $workflow;
		$this->step      = $step;
		$this->phase     = $phase;
		$this->batch     = absint( $batch );
		$this->queue_ids = array_values( array_map( 'absint', (array) $queue_ids ) );
		$this->source    = $source;
	}

	public function get_run() {
		return $this->run;
	}

	public function get_settings() {
		return (array) $this->step->settings;
	}

	public function get_setting( $name, $default_value = null ) {
		$settings = $this->get_settings();

		return array_key_exists( $name, $settings ) ? $settings[ $name ] : $default_value;
	}

	/**
	 * @return QueueItem[] The orders (queue items) covered by the step, keyed by queue id.
	 */
	public function get_queue_items() {
		if ( is_null( $this->queue_items ) ) {
			$this->queue_items = array();

			foreach ( $this->queue_ids as $queue_id ) {
				if ( $item = Queue::get( $queue_id ) ) { // phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition.Found, Squiz.PHP.DisallowMultipleAssignments.FoundInControlStructure
					$this->queue_items[ $queue_id ] = $item;
				}
			}
		}

		return $this->queue_items;
	}

	/**
	 * @return \Vendidero\Shiptastic\Order[] Shipment orders of the covered queue items, keyed by order id.
	 */
	public function get_order_shipments() {
		$orders = array();

		foreach ( $this->get_queue_items() as $item ) {
			if ( ! isset( $orders[ $item->get_order_id() ] ) && ( $order_shipment = $item->get_shipment_order() ) ) { // phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition.Found, Squiz.PHP.DisallowMultipleAssignments.FoundInControlStructure
				$orders[ $item->get_order_id() ] = $order_shipment;
			}
		}

		return $orders;
	}

	/**
	 * Shipments of the covered queue items' packages (incl. shipped ones, e.g. at the end of the run).
	 *
	 * @param array $args provider (string|string[]), status (string|string[]), has_label (bool|null)
	 *
	 * @return \Vendidero\Shiptastic\SimpleShipment[] keyed by shipment id
	 */
	public function get_shipments( $args = array() ) {
		$args      = wp_parse_args(
			$args,
			array(
				'provider'  => array(),
				'status'    => array(),
				'has_label' => null,
			)
		);
		$shipments = array();

		foreach ( $this->get_queue_items() as $item ) {
			if ( ! $order_shipment = $item->get_shipment_order() ) { // phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition.Found, Squiz.PHP.DisallowMultipleAssignments.FoundInControlStructure
				continue;
			}

			foreach ( $order_shipment->get_simple_shipments() as $shipment ) {
				if ( QueueSync::get_package_key( $shipment->get_location(), $shipment->get_shipping_method() ) !== $item->get_package_key() ) {
					continue;
				}

				if ( ! empty( $args['provider'] ) && ! in_array( $shipment->get_shipping_provider(), (array) $args['provider'], true ) ) {
					continue;
				}

				if ( ! empty( $args['status'] ) && ! in_array( $shipment->get_status(), (array) $args['status'], true ) ) {
					continue;
				}

				if ( ! is_null( $args['has_label'] ) && (bool) $args['has_label'] !== (bool) $shipment->has_label() ) {
					continue;
				}

				$shipments[ $shipment->get_id() ] = $shipment;
			}
		}

		return $shipments;
	}
}
