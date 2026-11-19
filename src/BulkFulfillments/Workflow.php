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
		// Position within the workflow list (higher first), changed via the arrows of the overview.
		'priority'      => 0,
		'mode'          => 'interactive',
		'filters'       => array(),
		'steps'         => array(),
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

	/**
	 * Workflow modes: interactive (staff works through the orders in the fulfillment app). Extensions may add modes,
	 * e.g. workflows running in the background.
	 *
	 * @return array mode => title
	 */
	public static function get_modes() {
		/**
		 * Filter to adjust the available fulfillment workflow modes.
		 *
		 * @param array $modes mode => title
		 *
		 * @package Vendidero/Shiptastic
		 */
		$modes = (array) apply_filters(
			'woocommerce_shiptastic_fulfillment_workflow_modes',
			array(
				'interactive' => _x( 'Interactive', 'shipments-workflow-mode', 'shiptastic-for-woocommerce' ),
			)
		);

		return array_merge( array( 'interactive' => _x( 'Interactive', 'shipments-workflow-mode', 'shiptastic-for-woocommerce' ) ), $modes );
	}

	/**
	 * The modes a step of an action may run in within workflows. Actions supporting user interaction run
	 * interactively, actions without user interaction (e.g. creating shipments) run automatically. Extensions may
	 * allow choosing the mode per step.
	 *
	 * @param string $action_name
	 *
	 * @return string[] The first mode is the default.
	 */
	public static function get_action_modes( $action_name ) {
		$action = ActionRegistry::get( $action_name );

		if ( ! $action ) {
			return array( 'interactive' );
		}

		$modes = $action::supports_mode( 'interactive' ) ? array( 'interactive' ) : array_values( $action::get_modes() );

		/**
		 * Filter to adjust the modes a step of a fulfillment action may run in within workflows.
		 *
		 * @param string[] $modes The modes, the first one is the default.
		 * @param string   $action_name
		 *
		 * @package Vendidero/Shiptastic
		 */
		$modes = array_values( array_filter( (array) apply_filters( 'woocommerce_shiptastic_fulfillment_action_modes', $modes, $action_name ), array( $action, 'supports_mode' ) ) );

		return empty( $modes ) ? array( $action::get_default_mode() ) : $modes;
	}

	/**
	 * Supported filters, applied in SQL when claiming queue items.
	 *
	 * - locations: Location names (warehouse group, ungrouped warehouse, '' = default location)
	 * - shipping_providers: Shipping provider names
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
				'countries'          => array(),
				'min_items'          => '',
				'max_items'          => '',
				'min_weight'         => '',
				'max_weight'         => '',
				'backorders'         => 'any',
			)
		);

		$sanitized = array();

		foreach ( array( 'locations', 'shipping_providers', 'countries' ) as $list ) {
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
		$instances = array();

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

			$settings = (array) $step['settings'];

			/*
			 * Per shipment steps run shipment by shipment anyway: steps saved with the after_shipment phase (no longer
			 * offered) become per shipment steps (item phase, shipment scope), placed after the other per shipment steps.
			 */
			if ( isset( $settings['phase'] ) && RunPhases::AFTER_SHIPMENT === $settings['phase'] && ( $instance = ActionRegistry::get( $action ) ) && $instance::supports_phase( RunPhases::ITEM ) && $instance::supports_scope( 'shipment' ) ) { // phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition.Found, Squiz.PHP.DisallowMultipleAssignments.FoundInControlStructure
				$settings['phase'] = RunPhases::ITEM;
				$settings['scope'] = 'shipment';
			}

			/*
			 * Several steps of an action (actions allowing it): further steps are told apart by their instance (kept
			 * once assigned, the step's results are logged with it), duplicates of other actions are dropped.
			 */
			$instance = isset( $step['instance'] ) ? sanitize_key( (string) $step['instance'] ) : '';
			$instance = str_replace( '-', '', $instance );

			if ( isset( $instances[ $action ] ) ) {
				$handler = ActionRegistry::get( $action );

				if ( $handler && ! $handler::allows_multiple() ) {
					continue;
				}

				while ( '' === $instance || in_array( $instance, $instances[ $action ], true ) ) {
					$instance = strtolower( wp_generate_password( 4, false ) );
				}
			} elseif ( '' !== $instance ) {
				// Keep the instance of a step whose first step of the action was removed (its results are logged with it).
				$instances[ $action ] = array( '' );
			}

			$instances[ $action ][] = $instance;

			$entry = array(
				'action'   => $action,
				'settings' => $settings,
			);

			if ( '' !== $instance ) {
				$entry['instance'] = $instance;
			}

			$sanitized[] = $entry;
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
		$this->set_prop( 'steps', self::sort_steps( self::sanitize_steps( $steps ) ) );
	}

	/**
	 * Actions (transitively) required by an action.
	 *
	 * @param string $action_name
	 *
	 * @return string[]
	 */
	public static function get_required_actions( $action_name ) {
		$required = array();
		$pending  = array( $action_name );

		while ( ! empty( $pending ) ) {
			$action = ActionRegistry::get( array_shift( $pending ) );

			if ( ! $action ) {
				continue;
			}

			foreach ( $action::get_requires() as $name ) {
				if ( ! in_array( $name, $required, true ) && $name !== $action_name ) {
					$required[] = $name;
					$pending[]  = $name;
				}
			}
		}

		return $required;
	}

	/**
	 * Returns the phase a step of an action runs in: the step's phase (setting) in case the action supports it,
	 * the action's default phase otherwise.
	 *
	 * @param string $action_name
	 * @param array $settings
	 *
	 * @return string
	 */
	public static function get_step_phase( $action_name, $settings = array() ) {
		$action = ActionRegistry::get( $action_name );

		if ( ! $action ) {
			return RunPhases::ITEM;
		}

		$phase = isset( $settings['phase'] ) ? (string) $settings['phase'] : '';

		return $action::supports_phase( $phase ) ? $phase : $action::get_default_phase();
	}

	/**
	 * @param string|string[] $phases
	 *
	 * @return array Steps (action, settings) of the given phase(s), in workflow order.
	 */
	public function get_phase_steps( $phases ) {
		$phases = (array) $phases;

		return array_values(
			array_filter(
				$this->get_steps(),
				function ( $step ) use ( $phases ) {
					return in_array( self::get_step_phase( $step['action'], $step['settings'] ), $phases, true );
				}
			)
		);
	}

	/**
	 * @return string[] Log keys (actions, incl. the instance of further steps of an action, see Step::get_log_key()) of
	 *                  steps running per order (all phases except the run level ones).
	 */
	public function get_item_actions() {
		return array_values( array_unique( array_map( array( __CLASS__, 'get_step_log_key' ), $this->get_phase_steps( array( RunPhases::ITEM, RunPhases::AFTER_SHIPMENT, RunPhases::AFTER_ITEM ) ) ) ) );
	}

	/**
	 * @param array $step A step of the workflow (action, settings, instance).
	 *
	 * @return string See Step::get_log_key().
	 */
	public static function get_step_log_key( $step ) {
		return Step::build_log_key( $step['action'], isset( $step['instance'] ) ? $step['instance'] : '' );
	}

	/**
	 * Enforces the phase order (start of batch, per order, after each shipment, after each order, end of run) and,
	 * within a phase, the dependency order: steps required by other steps (e.g. create_shipments) come before them.
	 * The chosen order is kept otherwise (stable).
	 *
	 * @param array $steps Sanitized steps.
	 *
	 * @return array
	 */
	public static function sort_steps( $steps ) {
		$by_phase = array();

		foreach ( array_values( $steps ) as $step ) {
			$by_phase[ RunPhases::get_rank( self::get_step_phase( $step['action'], $step['settings'] ) ) ][] = $step;
		}

		ksort( $by_phase );

		$sorted = array();

		foreach ( $by_phase as $phase_steps ) {
			$sorted = array_merge( $sorted, self::sort_by_dependencies( $phase_steps ) );
		}

		return $sorted;
	}

	/**
	 * @param array $steps
	 *
	 * @return array
	 */
	protected static function sort_by_dependencies( $steps ) {
		$sorted  = array();
		$pending = array_values( $steps );

		while ( ! empty( $pending ) ) {
			$listed = wp_list_pluck( $pending, 'action' );
			$next   = 0;

			foreach ( $pending as $index => $step ) {
				$blocked = array_intersect( self::get_required_actions( $step['action'] ), $listed );

				// The first step whose listed requirements are already sorted (cycles: keep the order).
				if ( empty( array_diff( $blocked, array( $step['action'] ) ) ) ) {
					$next = $index;
					break;
				}
			}

			$sorted[] = $pending[ $next ];
			array_splice( $pending, $next, 1 );
		}

		return $sorted;
	}

	/**
	 * @return string[] The (unique) action names of the workflow's steps.
	 */
	public function get_step_actions() {
		return array_values( array_unique( wp_list_pluck( $this->get_steps(), 'action' ) ) );
	}

	/**
	 * @return string[] Log keys of the workflow's steps in order (see Step::get_log_key()).
	 */
	public function get_step_log_keys() {
		return array_values( array_map( array( __CLASS__, 'get_step_log_key' ), $this->get_steps() ) );
	}

	/**
	 * Returns the mode (interactive|background) a step of this workflow runs in: one of the modes allowed for the
	 * action (see get_action_modes()), the step's preferred mode in case it is allowed.
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

		$modes     = self::get_action_modes( $action_name );
		$preferred = isset( $settings['mode'] ) ? $settings['mode'] : '';
		$mode      = in_array( $preferred, $modes, true ) ? $preferred : $modes[0];

		/**
		 * Filter to adjust the mode a step runs in within a workflow.
		 *
		 * @param string   $mode
		 * @param string   $action_name
		 * @param array    $settings The step's settings.
		 * @param bool     $injected Whether the step is injected as a dependency.
		 * @param Workflow $workflow
		 *
		 * @package Vendidero/Shiptastic
		 */
		$mode = apply_filters( 'woocommerce_shiptastic_fulfillment_workflow_step_mode', $mode, $action_name, $settings, $injected, $this );

		return $action::supports_mode( $mode ) ? $mode : $action::get_default_mode();
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
		}

		foreach ( $this->get_steps() as $step ) {
			$action = ActionRegistry::get( $step['action'] );

			if ( ! $action ) {
				continue;
			}

			$phase = isset( $step['settings']['phase'] ) ? (string) $step['settings']['phase'] : $action::get_default_phase();

			if ( ! $action::supports_phase( $phase ) ) {
				/* translators: 1: action title 2: phase title */
				$errors->add( 'workflow_invalid_phase', sprintf( _x( '%1$s cannot run in the phase "%2$s".', 'shipments', 'shiptastic-for-woocommerce' ), $action::get_title(), RunPhases::get_title( $phase ) ) );
			} elseif ( RunPhases::is_run_level( $phase ) && ! $action::supports_mode( 'background' ) ) {
				/* translators: 1: action title 2: phase title */
				$errors->add( 'workflow_invalid_phase', sprintf( _x( '%1$s requires user interaction and cannot run in the phase "%2$s".', 'shipments', 'shiptastic-for-woocommerce' ), $action::get_title(), RunPhases::get_title( $phase ) ) );
			} elseif ( RunPhases::AFTER_SHIPMENT === $phase && ! $action::supports_scope( 'shipment' ) ) {
				/* translators: %s action title */
				$errors->add( 'workflow_invalid_phase', sprintf( _x( '%s cannot run per shipment.', 'shipments', 'shiptastic-for-woocommerce' ), $action::get_title() ) );
			} elseif ( RunPhases::AFTER_ITEM === $phase && ! $action::supports_scope( 'order' ) ) {
				/* translators: %s action title */
				$errors->add( 'workflow_invalid_phase', sprintf( _x( '%s cannot run per order.', 'shipments', 'shiptastic-for-woocommerce' ), $action::get_title() ) );
			}
		}

		if ( ! empty( $this->get_steps() ) && empty( $this->get_item_actions() ) ) {
			$errors->add( 'workflow_no_item_steps', _x( 'Please add at least one step running per order.', 'shipments', 'shiptastic-for-woocommerce' ) );
		}

		$filters = self::sanitize_filters( $this->get_filters() );

		if ( '' !== $filters['min_items'] && '' !== $filters['max_items'] && $filters['min_items'] > $filters['max_items'] ) {
			$errors->add( 'workflow_invalid_item_range', _x( 'The minimum number of items must not exceed the maximum.', 'shipments', 'shiptastic-for-woocommerce' ) );
		}

		if ( '' !== $filters['min_weight'] && '' !== $filters['max_weight'] && $filters['min_weight'] > $filters['max_weight'] ) {
			$errors->add( 'workflow_invalid_weight_range', _x( 'The minimum weight must not exceed the maximum.', 'shipments', 'shiptastic-for-woocommerce' ) );
		}

		/**
		 * Action to validate a fulfillment workflow, e.g. settings added by extensions. Add errors to the WP_Error.
		 *
		 * @param \WP_Error $errors
		 * @param Workflow  $workflow
		 *
		 * @package Vendidero/Shiptastic
		 */
		do_action( 'woocommerce_shiptastic_fulfillment_validate_workflow', $errors, $this );

		return $errors->has_errors() ? $errors : true;
	}
}
