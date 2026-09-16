<?php

namespace Vendidero\Shiptastic\BulkFulfillments;

use Vendidero\Germanized\Shipments\Order;
use WC_Data;
use WC_Data_Store;
use WC_Order;

defined( 'ABSPATH' ) || exit;

class BulkFulfillmentOrder extends WC_Data {
	/**
	 * This is the name of this object type.
	 *
	 * @var string
	 */
	protected $object_type = 'bulk_fulfillment_order';

	/**
	 * Contains a reference to the data store for this class.
	 *
	 * @var object
	 */
	protected $data_store_name = 'bulk-fulfillment-order';

	/**
	 * Stores meta in cache for future reads.
	 * A group must be set to to enable caching.
	 *
	 * @var string
	 */
	protected $cache_group = 'bulk_fulfillment_order';

	/**
	 * @var BulkFulfillment|null
	 */
	protected $fulfillment = null;

	/**
	 * @var Order|null
	 */
	protected $shipment_order = null;

	/**
	 * Stores fulfillment data.
	 *
	 * @var array
	 */
	protected $data = array(
		'order_id'            => 0,
		'fulfillment_id'      => 0,
		'date_locked'         => null,
		'locked_by'           => 0,
		'status'              => '',
		'current_shipment_id' => 0,
		'current_action_name' => '',
		'action_data'         => array(),
	);

	protected $action_loop = null;

	/**
	 * @param int|object|BulkFulfillmentOrder $fulfillment_order Fulfillment order to read.
	 */
	public function __construct( $data = 0 ) {
		parent::__construct( $data );

		$db_data = null;

		if ( $data instanceof BulkFulfillmentOrder ) {
			$this->set_id( absint( $data->get_id() ) );
		} elseif ( is_numeric( $data ) ) {
			$this->set_id( $data );
		} elseif ( ! empty( $data->fulfillment_order_id ) ) {
			$db_data = $data;
			$this->set_id( absint( $data->fulfillment_order_id ) );
		}

		$this->data_store = \WC_Data_Store::load( $this->data_store_name );

		// If we have an ID, load the user from the DB.
		if ( $this->get_id() ) {
			try {
				$this->data_store->read( $this, $db_data );
			} catch ( \Exception $e ) {
				$this->set_id( 0 );
				$this->set_object_read( true );
			}
		} else {
			$this->set_object_read( true );
		}
	}

	/**
	 * This method overwrites the base class's clone method to make it a no-op. In base class WC_Data, we are unsetting the meta_id to clone.
	 */
	public function __clone() {}

	public function get_date_locked( $context = 'view' ) {
		return $this->get_prop( 'date_locked', $context );
	}

	public function set_date_locked( $date ) {
		$this->set_date_prop( 'date_locked', $date );
	}

	public function get_action_data( $context = 'view' ) {
		return $this->get_prop( 'action_data', $context );
	}

	public function set_action_data( $data ) {
		$this->set_prop( 'action_data', array_filter( (array) $data ) );
	}

	public function get_current_shipment_id( $context = 'view' ) {
		return $this->get_prop( 'current_shipment_id', $context );
	}

	public function set_current_shipment_id( $current_shipment ) {
		if ( is_a( $current_shipment, '\Vendidero\Shiptastic\Shipment' ) ) {
			$current_shipment = $current_shipment->get_id();
		}

		$this->set_prop( 'current_shipment_id', absint( $current_shipment ) );
	}

	public function get_default_action_name( $loop_context = '' ) {
		if ( empty( $loop_context ) ) {
			if ( $this->get_current_shipment_id() > 0 ) {
				$loop_context = 'shipment';
			} else {
				$loop_context = 'order';
			}
		}

		if ( 'shipment' === $loop_context ) {
			$loop = $this->get_action_loop( 'shipment', $this->get_current_shipment_id() );
		} else {
			$loop = $this->get_action_loop( $loop_context );
		}

		return ! empty( $loop ) ? $loop[0]::get_name() : '';
	}

	public function is_default_action( $name, $loop_context = '' ) {
		return $name === $this->get_default_action_name( $loop_context );
	}

	public function get_current_action_name( $context = 'view' ) {
		$current_action_name = $this->get_prop( 'current_action_name', $context );

		if ( 'view' === $context && empty( $current_action_name ) ) {
			$current_action_name = $this->get_default_action_name();
		}

		return $current_action_name;
	}

	public function set_current_action_name( $current_action ) {
		$this->set_prop( 'current_action_name', $current_action );
	}

	public function get_current_action() {
		return $this->get_action( $this->get_current_action_name(), $this->get_current_shipment_id() );
	}

	public function get_action( $name, $shipment_id = 0 ) {
		$context = empty( $shipment_id ) ? 'order' : 'shipment';
		$loop    = $this->get_action_loop();

		if ( array_key_exists( $name, $loop[ "{$context}_map" ] ) ) {
			$map_entry = $loop[ "{$context}_map" ][ $name ];

			if ( 'shipment' === $context ) {
				if ( isset( $map_entry['index'], $loop['shipment'][ $shipment_id ] ) ) {
					return $loop['shipment'][ $shipment_id ][ $map_entry['index'] ];
				}
			} elseif ( isset( $map_entry['index'], $loop[ $context ] ) ) {
				return $loop[ $context ][ $map_entry['index'] ];
			}
		}

		return null;
	}

	public function get_current_context() {
		$context = 'order';

		if ( $current_action = $this->get_current_action() ) {
			$context = $current_action->get_context();
		}

		return $context;
	}

	public function get_status( $context = 'view' ) {
		$status = $this->get_prop( 'status', $context );

		if ( 'view' === $context && empty( $status ) ) {
			$status = 'unfulfilled';
		}

		return $status;
	}

	public function set_status( $status ) {
		$this->set_prop( 'status', $status );
	}

	public function get_locked_by( $context = 'view' ) {
		return $this->get_prop( 'locked_by', $context );
	}

	public function set_locked_by( $user_id ) {
		$this->set_prop( 'locked_by', absint( $user_id ) );
	}

	public function get_order_id( $context = 'view' ) {
		return $this->get_prop( 'order_id', $context );
	}

	public function set_order_id( $order_id ) {
		$this->set_prop( 'order_id', absint( $order_id ) );

		$this->shipment_order = null;
	}

	public function get_fulfillment_id( $context = 'view' ) {
		return $this->get_prop( 'fulfillment_id', $context );
	}

	public function set_fulfillment_id( $fulfillment_id ) {
		$this->set_prop( 'fulfillment_id', absint( $fulfillment_id ) );

		$this->fulfillment = null;
		$this->action_loop = null;
	}

	/**
	 * @return BulkFulfillment|null
	 */
	public function get_fulfillment() {
		if ( is_null( $this->fulfillment ) && ! empty( $this->get_fulfillment_id() ) ) {
			$this->fulfillment = Factory::get_bulk_fulfillment( $this->get_fulfillment_id() );
		}

		return $this->fulfillment ? $this->fulfillment : null;
	}

	/**
	 * @param BulkFulfillment $fulfillment
	 *
	 * @return void
	 */
	public function set_fulfillment( $fulfillment ) {
		$this->set_fulfillment_id( $fulfillment->get_id() );
		$this->fulfillment = $fulfillment;
	}

	/**
	 * @param FulfillmentAction $action
	 *
	 * @return int
	 */
	public function update_action( $action ) {
		$action_data                            = $this->get_action_data();
		$action_data[ $action->get_data_key() ] = $action->get_data();

		return $this->save();
	}

	protected function get_action_instance( $args, $shipment = null ) {
		$args = wp_parse_args(
			$args,
			array(
				'name'     => '',
				'settings' => array(),
			)
		);

		$action_data = $this->get_action_data();

		if ( is_numeric( $shipment ) ) {
			$shipment = $this->get_shipment( $shipment );
		}

		$action = Factory::get_fulfillment_action( $args['name'], $args );
		$action->set_order( $this );
		$action->set_shipment( $shipment );

		if ( array_key_exists( $action->get_data_key(), $action_data ) ) {
			$action->set_data( $action_data[ $action->get_data_key() ] );
		}

		return $action;
	}

	public function get_shipment_order() {
		if ( is_null( $this->shipment_order ) ) {
			$this->shipment_order = wc_stc_get_shipment_order( $this->get_order_id() );
		}

		return $this->shipment_order;
	}

	public function get_shipments() {
		if ( $shipment_order = $this->get_shipment_order() ) {
			return $shipment_order->get_simple_shipments();
		}

		return array();
	}

	public function get_shipment( $shipment_id ) {
		$shipments = $this->get_shipments();

		foreach ( $shipments as $shipment ) {
			if ( $shipment->get_id() === $shipment_id ) {
				return $shipment;
			}
		}

		return null;
	}

	/**
	 * @param $type
	 *
	 * @return FulfillmentAction[]
	 */
	public function get_action_loop( $type = '', $shipment_id = 0 ) {
		if ( is_null( $this->action_loop ) ) {
			$this->action_loop = array(
				'order'        => array(),
				'order_map'    => array(),
				'shipment'     => array(),
				'shipment_map' => array(),
			);

			foreach ( $this->get_shipments() as $shipment ) {
				$this->action_loop['shipment'][ $shipment->get_id() ] = array();
			}

			if ( $fulfillment = $this->get_fulfillment() ) {
				$actions                   = $fulfillment->get_actions();
				$context_aware_actions     = array();
				$context_aware_actions_map = array();

				foreach ( $actions as $action ) {
					if ( $instance = $this->get_action_instance( $action ) ) {
						if ( ! array_key_exists( $instance->get_context(), $context_aware_actions ) ) {
							$context_aware_actions[ $instance->get_context() ]     = array();
							$context_aware_actions_map[ $instance->get_context() ] = array();
						}

						if ( array_key_exists( $instance::get_name(), $context_aware_actions_map[ $instance->get_context() ] ) ) {
							continue;
						}

						$actions_before = $instance::get_must_run_before_actions();

						if ( ! empty( $actions_before ) ) {
							$has_all_dependent_actions = true;

							foreach ( $actions_before as $action_name_before ) {
								if ( $before_instance = $this->get_action_instance( array( 'name' => $action_name_before ) ) ) {
									$new_before_action_contexts = array();

									foreach ( $before_instance::get_supported_contexts() as $supported_context ) {
										if ( ! array_key_exists( $supported_context, $context_aware_actions_map ) ) {
											$context_aware_actions_map[ $supported_context ] = array();
										}

										if ( ! array_key_exists( $before_instance::get_name(), $context_aware_actions_map[ $supported_context ] ) ) {
											$new_before_action_contexts[] = $supported_context;
										}
									}

									foreach ( $new_before_action_contexts as $new_before_action_context ) {
										if ( ! array_key_exists( $new_before_action_context, $context_aware_actions ) ) {
											$context_aware_actions[ $new_before_action_context ] = array();
										}

										$context_aware_actions[ $new_before_action_context ][] = array(
											'name'     => $action_name_before,
											'settings' => array(
												'context' => $new_before_action_context,
											),
										);

										$context_aware_actions_map[ $new_before_action_context ][ $action_name_before ] = array();
									}
								} else {
									$has_all_dependent_actions = false;
								}
							}

							if ( ! $has_all_dependent_actions ) {
								continue;
							}
						}

						$context_aware_actions_map[ $instance->get_context() ][ $instance::get_name() ] = array();
						$context_aware_actions[ $instance->get_context() ][]                            = $action;
					}
				}

				foreach ( $context_aware_actions as $context => $actions ) {
					foreach ( $actions as $action ) {
						$index = -1;

						if ( 'shipment' === $context ) {
							foreach ( array_keys( $this->action_loop['shipment'] ) as $shipment_id ) {
								$new_instance                                    = $this->get_action_instance( $action, $shipment_id );
								$this->action_loop['shipment'][ $shipment_id ][] = $new_instance;
								$index = count( $this->action_loop['shipment'][ $shipment_id ] ) - 1;
							}
						} else {
							$this->action_loop[ $context ][] = $this->get_action_instance( $action );

							$index = count( $this->action_loop[ $context ] ) - 1;
						}

						if ( -1 !== $index ) {
							$this->action_loop[ "{$context}_map" ][ $action['name'] ] = array(
								'index' => $index,
							);
						}
					}
				}
			}
		}

		if ( empty( $type ) ) {
			return $this->action_loop;
		} else {
			$action_loop = array_key_exists( $type, $this->action_loop ) ? $this->action_loop[ $type ] : array();

			if ( 'shipment' === $type && ! empty( $shipment_id ) ) {
				$action_loop = isset( $action_loop[ $shipment_id ] ) ? $action_loop[ $shipment_id ] : array();
			}

			return $action_loop;
		}
	}
}
