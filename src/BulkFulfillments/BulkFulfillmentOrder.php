<?php

namespace Vendidero\Shiptastic\BulkFulfillments;

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

	protected $orders = null;

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

	public function get_current_action_name( $context = 'view' ) {
		$current_action_name = $this->get_prop( 'current_action_name', $context );

		if ( 'view' === $context && empty( $current_action_name ) ) {
			$loop                = $this->get_action_loop( 'order' );
			$current_action_name = ! empty( $loop ) ? $loop[0]::get_name() : '';
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
		$loop = $this->get_action_loop();

		if ( array_key_exists( $name, $loop['map'] ) ) {
			$map_entry = $loop['map'][ $name ];

			if ( 'shipment' === $map_entry['context'] ) {
				if ( ! empty( $shipment_id ) ) {
					return $loop[ $map_entry['context'] ][ $shipment_id ][ $map_entry['index'] ];
				} else {
					return array_values( $loop[ $map_entry['context'] ] )[0][ $map_entry['index'] ];
				}
			} else {
				return $loop[ $map_entry['context'] ][ $map_entry['index'] ];
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

	public function get_shipments() {
		if ( $shipment_order = wc_stc_get_shipment_order( $this->get_order_id() ) ) {
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
	public function get_action_loop( $type = '' ) {
		if ( is_null( $this->action_loop ) ) {
			$this->action_loop = array(
				'order'    => array(),
				'shipment' => array(),
				'map'      => array(),
			);

			foreach ( $this->get_shipments() as $shipment ) {
				$this->action_loop['shipment'][ $shipment->get_id() ] = array();
			}

			if ( $fulfillment = $this->get_fulfillment() ) {
				$actions = $fulfillment->get_actions();

				foreach ( $actions as $action ) {
					if ( $instance = $this->get_action_instance( $action ) ) {
						$actions_before = $instance::get_must_run_before_actions();

						if ( ! empty( $actions_before ) ) {
							$has_all_dependent_actions = true;

							foreach ( $actions_before as $action_name ) {
								if ( ! array_key_exists( $action_name, $this->action_loop['map'] ) ) {
									if ( $before_instance = $this->get_action_instance( $action ) ) {
										$index = -1;

										if ( 'shipment' === $before_instance->get_context() ) {
											foreach ( array_keys( $this->action_loop['shipment'] ) as $shipment_id ) {
												$this->action_loop[ $before_instance->get_context() ][ $shipment_id ][] = $before_instance;
												$index = count( $this->action_loop[ $before_instance->get_context() ][ $shipment_id ] ) - 1;
											}
										} else {
											$this->action_loop[ $before_instance->get_context() ][] = $before_instance;
											$index = count( $this->action_loop[ $before_instance->get_context() ] );
										}

										if ( -1 !== $index ) {
											$this->action_loop['map'][ $action_name ] = array(
												'index'   => $index,
												'context' => $before_instance->get_context(),
											);
										} else {
											$has_all_dependent_actions = false;
										}
									} else {
										$has_all_dependent_actions = false;
									}
								}
							}

							if ( ! $has_all_dependent_actions ) {
								continue;
							}
						}

						$index = -1;

						if ( 'shipment' === $instance->get_context() ) {
							foreach ( array_keys( $this->action_loop['shipment'] ) as $shipment_id ) {
								$new_instance                                    = $this->get_action_instance( $action, $shipment_id );
								$this->action_loop['shipment'][ $shipment_id ][] = $new_instance;
								$index = count( $this->action_loop['shipment'][ $shipment_id ] ) - 1;
							}
						} else {
							$this->action_loop[ $instance->get_context() ][] = $instance;
							$index = count( $this->action_loop[ $instance->get_context() ] ) - 1;
						}

						if ( -1 !== $index ) {
							$this->action_loop['map'][ $instance::get_name() ] = array(
								'index'   => $index,
								'context' => $instance->get_context(),
							);
						}
					}
				}
			}
		}

		if ( empty( $type ) ) {
			return $this->action_loop;
		} else {
			return array_key_exists( $type, $this->action_loop ) ? $this->action_loop[ $type ] : array();
		}
	}
}
