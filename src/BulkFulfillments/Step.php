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
	 * @var string See RunPhases.
	 */
	public $phase = 'item';

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

	/**
	 * @return bool Whether the step can be done offline: interactive steps of actions not requiring a connection.
	 */
	public function is_offline_capable() {
		$action = $this->get_action();

		return $action && 'interactive' === $this->mode && ! $action::requires_network();
	}

	public function to_array() {
		$action = $this->get_action();

		return array(
			'key'         => $this->get_key(),
			'action'      => $this->action,
			'title'       => $action ? $action::get_title() : $this->action,
			'description' => $action ? $action::get_description() : '',
			'modes'       => $action ? array_values( $action::get_modes() ) : array(),
			'shipment_id' => $this->shipment_id,
			'mode'        => $this->mode,
			'phase'       => $this->phase,
			'phase_title' => RunPhases::ITEM === $this->phase && $this->shipment_id ? RunPhases::get_item_shipment_texts()['title'] : RunPhases::get_title( $this->phase ),
			'injected'    => $this->injected,
			'is_done'     => $this->is_done,
			// Interactive steps of actions not requiring a connection can be done offline (prefetched view data).
			'offline'     => $this->is_offline_capable(),
			// Used to update the steps in case the shipments are edited offline (see ShipmentLayout::reopen_steps()).
			'scope'       => isset( $this->settings['scope'] ) ? $this->settings['scope'] : ( $action ? $action::get_default_scope() : 'order' ),
			'resets'      => $action ? (bool) $action::resets_on_shipment_changes() : false,
		);
	}
}
