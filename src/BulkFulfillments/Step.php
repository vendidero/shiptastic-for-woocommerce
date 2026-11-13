<?php

namespace Vendidero\Shiptastic\BulkFulfillments;

defined( 'ABSPATH' ) || exit;

/**
 * A planned step for a queue item within a workflow: action + scope target (shipment) + mode/settings.
 */
class Step {

	public $action = '';

	public $shipment_id = 0;

	public $mode = 'interactive';

	public $settings = array();

	/**
	 * Whether the step was injected as a dependency (not part of the workflow's step list).
	 *
	 * @var bool
	 */
	public $injected = false;

	public $is_done = false;

	public function __construct( $args = array() ) {
		foreach ( wp_parse_args( $args, get_object_vars( $this ) ) as $key => $value ) {
			if ( property_exists( $this, $key ) ) {
				$this->$key = $value;
			}
		}

		$this->shipment_id = absint( $this->shipment_id );
	}

	/**
	 * A key identifying the step for a queue item, e.g. pack:123.
	 *
	 * @return string
	 */
	public function get_key() {
		return $this->action . ( $this->shipment_id ? ':' . $this->shipment_id : '' );
	}

	/**
	 * @return Action|null
	 */
	public function get_action() {
		return ActionRegistry::get( $this->action );
	}

	public function to_array() {
		$action = $this->get_action();

		return array(
			'key'         => $this->get_key(),
			'action'      => $this->action,
			'title'       => $action ? $action::get_title() : $this->action,
			'shipment_id' => $this->shipment_id,
			'mode'        => $this->mode,
			'injected'    => $this->injected,
			'is_done'     => $this->is_done,
		);
	}
}
