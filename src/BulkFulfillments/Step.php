<?php

namespace Vendidero\Shiptastic\BulkFulfillments;

defined( 'ABSPATH' ) || exit;

/**
 * A planned step for a queue item within a workflow: action + scope target (shipment) + mode/settings.
 */
class Step {

	public $action = '';

	/**
	 * Distinguishes several steps of the same action within a workflow (e.g. printing documents at different times),
	 * empty for the first one.
	 *
	 * @var string
	 */
	public $instance = '';

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

	/**
	 * Whether the step is not shown, e.g. because it does not apply (see Action::is_applicable()).
	 *
	 * @var bool
	 */
	public $hidden = false;

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
		return $this->get_log_key() . ( $this->shipment_id ? ':' . $this->shipment_id : '' );
	}

	/**
	 * @return string The key the step's results are logged with (StepLog, RunSteps): the action, e.g. print_documents,
	 *                followed by the instance for further steps of the same action, e.g. print_documents--k3x9.
	 */
	public function get_log_key() {
		return self::build_log_key( $this->action, $this->instance );
	}

	/**
	 * @param string $action
	 * @param string $instance
	 *
	 * @return string
	 */
	public static function build_log_key( $action, $instance = '' ) {
		return $action . ( '' !== (string) $instance ? '--' . $instance : '' );
	}

	/**
	 * @param string $log_key
	 *
	 * @return string The action of a log key (see get_log_key()).
	 */
	public static function get_log_key_action( $log_key ) {
		$parts = explode( '--', (string) $log_key, 2 );

		return $parts[0];
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
			'instance'    => $this->instance,
			'title'       => $action ? $action::get_title() : $this->action,
			'description' => $action ? $action::get_description() : '',
			'modes'       => $action ? array_values( $action::get_modes() ) : array(),
			'shipment_id' => $this->shipment_id,
			'mode'        => $this->mode,
			'phase'       => $this->phase,
			'phase_title' => RunPhases::ITEM === $this->phase && $this->shipment_id ? RunPhases::get_item_shipment_texts()['title'] : RunPhases::get_title( $this->phase ),
			'injected'    => $this->injected,
			'is_done'     => $this->is_done,
			'hidden'      => $this->hidden,
			// Interactive steps of actions not requiring a connection can be done offline (prefetched view data).
			'offline'     => $this->is_offline_capable(),
			// Used to update the steps in case the shipments are edited offline (see ShipmentLayout::reopen_steps()).
			'scope'       => isset( $this->settings['scope'] ) ? $this->settings['scope'] : ( $action ? $action::get_default_scope() : 'order' ),
			'resets'      => $action ? (bool) $action::resets_on_shipment_changes() : false,
		);
	}
}
