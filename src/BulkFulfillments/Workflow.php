<?php

namespace Vendidero\Shiptastic\BulkFulfillments;

use WC_Data;
use WC_Data_Store;
use Exception;

defined( 'ABSPATH' ) || exit;

/**
 * A fulfillment workflow: filters (which queue items) + steps (which actions) + execution settings.
 */
class Workflow extends WC_Data {

	protected $object_type = 'fulfillment_workflow';

	protected $data_store_name = 'fulfillment-workflow';

	protected $cache_group = 'fulfillment_workflow';

	protected $data = array(
		'date_created'  => null,
		'date_modified' => null,
		'title'         => '',
		'status'        => 'active',
		'priority'      => 0,
		'mode'          => 'interactive',
		'filters'       => array(),
		'steps'         => array(),
		'on_error'      => 'continue',
		'run_as'        => 0,
		'schedule'      => array(
			'type'    => 'none',
			'minutes' => 15,
			'times'   => array(),
		),
		'paused'        => false,
		'paused_reason' => '',
	);

	/**
	 * @param int|object|Workflow $data
	 */
	public function __construct( $data = 0 ) {
		parent::__construct( $data );

		if ( $data instanceof Workflow ) {
			$this->set_id( absint( $data->get_id() ) );
		} elseif ( is_numeric( $data ) ) {
			$this->set_id( $data );
		}

		$this->data_store = WC_Data_Store::load( $this->data_store_name );

		if ( $this->get_id() ) {
			try {
				$this->data_store->read( $this );
			} catch ( Exception $e ) {
				$this->set_id( 0 );
				$this->set_object_read( true );
			}
		} else {
			$this->set_object_read( true );
		}
	}

	protected function get_hook_prefix() {
		return 'woocommerce_shiptastic_fulfillment_workflow_get_';
	}

	public static function get_statuses() {
		return array(
			'active'   => _x( 'Active', 'shipments-workflow-status', 'shiptastic-for-woocommerce' ),
			'inactive' => _x( 'Inactive', 'shipments-workflow-status', 'shiptastic-for-woocommerce' ),
		);
	}

	public static function get_modes() {
		return array(
			'interactive' => _x( 'Interactive', 'shipments-workflow-mode', 'shiptastic-for-woocommerce' ),
			'background'  => _x( 'Background', 'shipments-workflow-mode', 'shiptastic-for-woocommerce' ),
		);
	}

	/**
	 * Supported filters, applied in SQL when claiming queue items.
	 *
	 * - locations: Location names (warehouse group, ungrouped warehouse, '' = default location)
	 * - shipping_providers: Shipping provider names
	 * - shipping_methods: Shipping method ids, either with instance (flat_rate:1) or without (flat_rate)
	 * - countries: Shipping countries
	 * - min_items/max_items: Number of items to ship
	 * - min_weight/max_weight: Weight to ship (store weight unit)
	 * - backorders: any|exclude|only
	 *
	 * @param array $filters
	 *
	 * @return array
	 */
	public static function sanitize_filters( $filters ) {
		$filters = wp_parse_args(
			(array) $filters,
			array(
				'locations'          => array(),
				'shipping_providers' => array(),
				'shipping_methods'   => array(),
				'countries'          => array(),
				'min_items'          => '',
				'max_items'          => '',
				'min_weight'         => '',
				'max_weight'         => '',
				'backorders'         => 'any',
			)
		);

		$sanitized = array();

		foreach ( array( 'locations', 'shipping_providers', 'shipping_methods', 'countries' ) as $list ) {
			$values = array_map( 'wc_clean', array_map( 'strval', (array) $filters[ $list ] ) );

			if ( 'countries' === $list ) {
				$values = array_map( 'strtoupper', $values );
			}

			// The default location is an empty string - keep it for locations only.
			$values = 'locations' === $list ? $values : array_filter( $values );

			$sanitized[ $list ] = array_values( array_unique( $values ) );
		}

		foreach ( array( 'min_items', 'max_items' ) as $int_filter ) {
			$sanitized[ $int_filter ] = '' === $filters[ $int_filter ] || null === $filters[ $int_filter ] ? '' : absint( $filters[ $int_filter ] );
		}

		foreach ( array( 'min_weight', 'max_weight' ) as $float_filter ) {
			$sanitized[ $float_filter ] = '' === $filters[ $float_filter ] || null === $filters[ $float_filter ] ? '' : (float) wc_format_decimal( $filters[ $float_filter ] );
		}

		$sanitized['backorders'] = in_array( $filters['backorders'], array( 'any', 'exclude', 'only' ), true ) ? $filters['backorders'] : 'any';

		return $sanitized;
	}

	/**
	 * @param array $steps List of steps: [ action => name, settings => [] ]
	 *
	 * @return array
	 */
	public static function sanitize_steps( $steps ) {
		$sanitized = array();

		foreach ( array_values( (array) $steps ) as $step ) {
			$step = wp_parse_args(
				is_string( $step ) ? array( 'action' => $step ) : (array) $step,
				array(
					'action'   => '',
					'settings' => array(),
				)
			);

			$action = sanitize_key( $step['action'] );

			if ( '' === $action ) {
				continue;
			}

			$sanitized[] = array(
				'action'   => $action,
				'settings' => (array) $step['settings'],
			);
		}

		return $sanitized;
	}

	public function get_date_created( $context = 'view' ) {
		return $this->get_prop( 'date_created', $context );
	}

	public function set_date_created( $date ) {
		$this->set_date_prop( 'date_created', $date );
	}

	public function get_date_modified( $context = 'view' ) {
		return $this->get_prop( 'date_modified', $context );
	}

	public function set_date_modified( $date ) {
		$this->set_date_prop( 'date_modified', $date );
	}

	public function get_title( $context = 'view' ) {
		return $this->get_prop( 'title', $context );
	}

	public function set_title( $title ) {
		$this->set_prop( 'title', wc_clean( (string) $title ) );
	}

	public function get_status( $context = 'view' ) {
		return $this->get_prop( 'status', $context );
	}

	public function set_status( $status ) {
		$this->set_prop( 'status', array_key_exists( $status, self::get_statuses() ) ? $status : 'inactive' );
	}

	public function is_active() {
		return 'active' === $this->get_status();
	}

	public function get_priority( $context = 'view' ) {
		return $this->get_prop( 'priority', $context );
	}

	public function set_priority( $priority ) {
		$this->set_prop( 'priority', (int) $priority );
	}

	public function get_mode( $context = 'view' ) {
		return $this->get_prop( 'mode', $context );
	}

	public function set_mode( $mode ) {
		$this->set_prop( 'mode', array_key_exists( $mode, self::get_modes() ) ? $mode : 'interactive' );
	}

	public function get_filters( $context = 'view' ) {
		return $this->get_prop( 'filters', $context );
	}

	public function set_filters( $filters ) {
		$this->set_prop( 'filters', self::sanitize_filters( $filters ) );
	}

	public function get_filter( $name ) {
		$filters = self::sanitize_filters( $this->get_filters() );

		return isset( $filters[ $name ] ) ? $filters[ $name ] : null;
	}

	public function get_steps( $context = 'view' ) {
		return $this->get_prop( 'steps', $context );
	}

	public function set_steps( $steps ) {
		$this->set_prop( 'steps', self::sanitize_steps( $steps ) );
	}

	/**
	 * @return string[] The (unique) action names of the workflow's steps.
	 */
	public function get_step_actions() {
		return array_values( array_unique( wp_list_pluck( $this->get_steps(), 'action' ) ) );
	}

	/**
	 * Error handling for scheduled runs: continue (skip the item, inbox) or stop (pause the schedule).
	 */
	public function get_on_error( $context = 'view' ) {
		return $this->get_prop( 'on_error', $context );
	}

	public function set_on_error( $on_error ) {
		$this->set_prop( 'on_error', 'stop' === $on_error ? 'stop' : 'continue' );
	}

	/**
	 * The user scheduled runs are executed as.
	 */
	public function get_run_as( $context = 'view' ) {
		return $this->get_prop( 'run_as', $context );
	}

	public function set_run_as( $user_id ) {
		$this->set_prop( 'run_as', absint( $user_id ) );
	}

	/**
	 * Schedule for background workflows:
	 * - type none: not scheduled
	 * - type interval: minutes (e.g. every 15 minutes)
	 * - type daily: times (e.g. 13:30 before carrier pickup, site timezone)
	 *
	 * @param string $context
	 *
	 * @return array
	 */
	public function get_schedule( $context = 'view' ) {
		return $this->get_prop( 'schedule', $context );
	}

	public function set_schedule( $schedule ) {
		$schedule = wp_parse_args(
			(array) $schedule,
			array(
				'type'    => 'none',
				'minutes' => 15,
				'times'   => array(),
			)
		);

		$times = array();

		foreach ( (array) $schedule['times'] as $time ) {
			if ( preg_match( '/^([01]?\d|2[0-3]):([0-5]\d)$/', trim( (string) $time ), $matches ) ) {
				$times[] = sprintf( '%02d:%02d', $matches[1], $matches[2] );
			}
		}

		$this->set_prop(
			'schedule',
			array(
				'type'    => in_array( $schedule['type'], array( 'none', 'interval', 'daily' ), true ) ? $schedule['type'] : 'none',
				'minutes' => max( 5, absint( $schedule['minutes'] ) ),
				'times'   => array_values( array_unique( $times ) ),
			)
		);
	}

	public function is_scheduled() {
		$schedule = $this->get_schedule();

		return $this->is_active() && 'background' === $this->get_mode() && ! $this->is_paused() && ! empty( $schedule['type'] ) && 'none' !== $schedule['type'];
	}

	/**
	 * Whether the schedule is paused (scheduled run stopped on an error).
	 */
	public function get_paused( $context = 'view' ) {
		return $this->get_prop( 'paused', $context );
	}

	public function set_paused( $paused ) {
		$this->set_prop( 'paused', wc_string_to_bool( $paused ) );
	}

	public function is_paused() {
		return true === $this->get_paused();
	}

	public function get_paused_reason( $context = 'view' ) {
		return $this->get_prop( 'paused_reason', $context );
	}

	public function set_paused_reason( $reason ) {
		$this->set_prop( 'paused_reason', wp_strip_all_tags( (string) $reason ) );
	}

	/**
	 * Returns the mode (interactive|background) a step of this workflow runs in.
	 *
	 * @param string $action_name
	 * @param array $settings Step settings (may contain a preferred mode).
	 * @param bool $injected Whether the step is injected as a dependency.
	 *
	 * @return string
	 */
	public function get_step_mode( $action_name, $settings = array(), $injected = false ) {
		$action = ActionRegistry::get( $action_name );

		if ( ! $action ) {
			return 'interactive';
		}

		$preferred = isset( $settings['mode'] ) ? $settings['mode'] : '';

		if ( 'background' === $this->get_mode() ) {
			$preferred = 'background';
		} elseif ( $injected && empty( $preferred ) ) {
			$preferred = 'background';
		}

		if ( $preferred && $action::supports_mode( $preferred ) ) {
			return $preferred;
		}

		$modes = $action::get_modes();

		return in_array( 'interactive', $modes, true ) ? 'interactive' : $modes[0];
	}

	/**
	 * @param string $action_name
	 *
	 * @return array|null The workflow's settings for an action in case it is part of the step list.
	 */
	public function get_step_settings( $action_name ) {
		foreach ( $this->get_steps() as $step ) {
			if ( $step['action'] === $action_name ) {
				return $step['settings'];
			}
		}

		return null;
	}

	/**
	 * Validates the workflow configuration.
	 *
	 * @return true|\WP_Error
	 */
	public function validate() {
		$errors = new \WP_Error();

		if ( empty( $this->get_steps() ) ) {
			$errors->add( 'workflow_no_steps', _x( 'Please add at least one step.', 'shipments', 'shiptastic-for-woocommerce' ) );
		}

		$actions = array();

		foreach ( $this->get_steps() as $step ) {
			$actions[] = $step['action'];

			foreach ( ActionRegistry::exists( $step['action'] ) ? ActionRegistry::get( $step['action'] )::get_requires() : array() as $required ) {
				$actions[] = $required;
			}
		}

		foreach ( array_unique( $actions ) as $action_name ) {
			$action = ActionRegistry::get( $action_name );

			if ( ! $action ) {
				/* translators: %s action name */
				$errors->add( 'workflow_unknown_action', sprintf( _x( 'The action %s does not exist.', 'shipments', 'shiptastic-for-woocommerce' ), $action_name ) );
				continue;
			}

			if ( 'background' === $this->get_mode() && ! $action::supports_mode( 'background' ) ) {
				/* translators: %s action title */
				$errors->add( 'workflow_action_requires_interaction', sprintf( _x( '%s cannot run in the background. Background workflows may only contain steps which support the background.', 'shipments', 'shiptastic-for-woocommerce' ), $action::get_title() ) );
			}
		}

		$schedule = $this->get_schedule();

		if ( 'none' !== $schedule['type'] && 'background' !== $this->get_mode() ) {
			$errors->add( 'workflow_schedule_requires_background', _x( 'Only background workflows can run on a schedule.', 'shipments', 'shiptastic-for-woocommerce' ) );
		}

		if ( 'daily' === $schedule['type'] && empty( $schedule['times'] ) ) {
			$errors->add( 'workflow_schedule_no_times', _x( 'Please add at least one time for the daily schedule.', 'shipments', 'shiptastic-for-woocommerce' ) );
		}

		return $errors->has_errors() ? $errors : true;
	}
}
