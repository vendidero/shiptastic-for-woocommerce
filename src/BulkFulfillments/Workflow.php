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
		'schedule'      => array(),
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

	public function get_schedule( $context = 'view' ) {
		return $this->get_prop( 'schedule', $context );
	}

	public function set_schedule( $schedule ) {
		$this->set_prop( 'schedule', (array) $schedule );
	}
}
