<?php

namespace Vendidero\Shiptastic\BulkFulfillments;

use WC_Data;

defined( 'ABSPATH' ) || exit;

class BulkFulfillment extends WC_Data {
	/**
	 * Stores data about status changes so relevant hooks can be fired.
	 *
	 * @var bool|array
	 */
	protected $status_transition = false;

	/**
	 * This is the name of this object type.
	 *
	 * @var string
	 */
	protected $object_type = 'bulk_fulfillment';

	/**
	 * Contains a reference to the data store for this class.
	 *
	 * @var object
	 */
	protected $data_store_name = 'bulk-fulfillment';

	/**
	 * Stores meta in cache for future reads.
	 * A group must be set to to enable caching.
	 *
	 * @var string
	 */
	protected $cache_group = 'bulk_fulfillment';

	protected $orders = null;

	/**
	 * Stores fulfillment data.
	 *
	 * @var array
	 */
	protected $data = array(
		'date_created'     => null,
		'date_modified'    => null,
		'date_start'       => null,
		'date_end'         => null,
		'status'           => '',
		'type'             => '',
		'filters'          => array(),
		'actions'          => array(),
		'current_order_id' => 0,
		'current_action'   => '',
		'progress'         => 0,
		'parent_id'        => 0,
		'is_initialized'   => false,
		'order_count'      => 0,
		'last_order_id'    => 0,
		'first_order_id'   => 0,
	);

	/**
	 * @param int|object|BulkFulfillment $fulfillment Fulfillment to read.
	 */
	public function __construct( $data = 0 ) {
		parent::__construct( $data );

		if ( $data instanceof BulkFulfillment ) {
			$this->set_id( absint( $data->get_id() ) );
		} elseif ( is_numeric( $data ) ) {
			$this->set_id( $data );
		}

		$this->data_store = \WC_Data_Store::load( $this->data_store_name );

		// If we have an ID, load the user from the DB.
		if ( $this->get_id() ) {
			try {
				$this->data_store->read( $this );
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

	public function get_date_start( $context = 'view' ) {
		return $this->get_prop( 'date_start', $context );
	}

	public function set_date_start( $date ) {
		$this->set_date_prop( 'date_start', $date );
	}

	public function get_date_end( $context = 'view' ) {
		return $this->get_prop( 'date_end', $context );
	}

	public function set_date_end( $date ) {
		$this->set_date_prop( 'date_end', $date );
	}

	public function get_status( $context = 'view' ) {
		$status = $this->get_prop( 'status', $context );

		if ( 'view' === $context && empty( $status ) ) {
			$status = 'open';
		}

		return $status;
	}

	public function set_status( $status ) {
		$this->set_prop( 'status', $status );
	}

	public function get_type( $context = 'view' ) {
		return $this->get_prop( 'type', $context );
	}

	public function set_type( $type ) {
		$this->set_prop( 'type', $type );
	}

	public function get_filters( $context = 'view' ) {
		$filters = $this->get_prop( 'filters', $context );

		if ( 'view' === $context ) {
			$filters = wp_parse_args(
				$filters,
				array(
					'shipping_status' => array( 'not-shipped', 'partially-shipped', 'partially-delivered', 'ready-for-shipping' ),
				)
			);
		}

		return $filters;
	}

	public function set_filters( $filters ) {
		$this->set_prop( 'filters', array_filter( (array) $filters ) );
	}

	public function get_filter( $name, $default_value = null ) {
		$filters = $this->get_filters();

		if ( array_key_exists( $name, $filters ) ) {
			return $filters[ $name ];
		}

		return $default_value;
	}

	public function get_actions( $context = 'view' ) {
		return $this->get_prop( 'actions', $context );
	}

	public function add_action( $name, $args = array() ) {
		$args = wp_parse_args(
			$args,
			array(
				'settings' => array(),
			)
		);

		$actions   = $this->get_actions();
		$actions[] = array(
			'name'     => $name,
			'settings' => $args['settings'],
		);

		$this->set_actions( $actions );
	}

	public function set_actions( $actions ) {
		$this->set_prop( 'actions', array_filter( (array) $actions ) );
	}

	public function get_current_order_id( $context = 'view' ) {
		return $this->get_prop( 'current_order_id', $context );
	}

	/**
	 * @return BulkFulfillmentOrder|null
	 */
	public function get_current_order() {
		return $this->get_orders()['current'];
	}

	public function set_current_order_id( $current_order ) {
		if ( is_a( $current_order, '\Vendidero\Shiptastic\BulkFulfillments\BulkFulfillment' ) ) {
			$current_order = $current_order->get_id();
		}

		$this->set_prop( 'current_order_id', absint( $current_order ) );

		$this->orders = null;
	}

	public function get_current_action( $context = 'view' ) {
		return $this->get_prop( 'current_action', $context );
	}

	public function set_current_action( $current_action ) {
		$this->set_prop( 'current_action', $current_action );
	}

	public function get_progress( $context = 'view' ) {
		return $this->get_prop( 'progress', $context );
	}

	public function set_progress( $progress ) {
		$this->set_prop( 'progress', min( 100, absint( $progress ) ) );
	}

	public function get_parent_id( $context = 'view' ) {
		return $this->get_prop( 'parent_id', $context );
	}

	public function set_parent_id( $parent_id ) {
		$this->set_prop( 'parent_id', absint( $parent_id ) );
	}

	public function get_is_initialized( $context = 'view' ) {
		return $this->get_prop( 'is_initialized', $context );
	}

	public function set_is_initialized( $initialized ) {
		$this->set_prop( 'is_initialized', wc_string_to_bool( $initialized ) );
	}

	public function is_initialized() {
		return $this->get_is_initialized();
	}

	public function get_order_count( $context = 'view' ) {
		return $this->get_prop( 'order_count', $context );
	}

	public function set_order_count( $count ) {
		$this->set_prop( 'order_count', absint( $count ) );
	}

	public function get_first_order_id( $context = 'view' ) {
		return $this->get_prop( 'first_order_id', $context );
	}

	public function set_first_order_id( $order_id ) {
		$this->set_prop( 'first_order_id', absint( $order_id ) );
	}

	public function get_last_order_id( $context = 'view' ) {
		return $this->get_prop( 'last_order_id', $context );
	}

	public function set_last_order_id( $order_id ) {
		$this->set_prop( 'last_order_id', absint( $order_id ) );
	}

	/**
	 * @return BulkFulfillmentOrder[]
	 */
	public function get_orders() {
		if ( is_null( $this->orders ) ) {
			$this->orders = array(
				'next'    => null,
				'prev'    => null,
				'current' => null,
			);

			$results = $this->get_data_store()->get_prev_next_current_order( $this->get_id(), $this->get_current_order_id() );

			foreach ( $results as $result ) {
				if ( $order = $this->get_order_instance( $result ) ) {
					if ( $order->get_id() <= 0 ) {
						continue;
					}

					$order->set_fulfillment( $this );

					if ( $order->get_id() > $this->get_current_order_id() ) {
						$this->orders['next'] = $order;
					} elseif ( $order->get_id() < $this->get_current_order_id() ) {
						$this->orders['prev'] = $order;
					} else {
						$this->orders['current'] = $order;
					}
				}
			}
		}

		return (array) $this->orders;
	}

	protected function get_order_instance( $order = 0 ) {
		try {
			$the_order = new BulkFulfillmentOrder( $order );
			$the_order->set_fulfillment_id( $this->get_id() );

			return $the_order;
		} catch ( \Exception $e ) {
			return null;
		}
	}

	/**
	 * @param BulkFulfillmentOrder|integer $order
	 *
	 * @return BulkFulfillmentOrder|null
	 */
	public function go_to_order( $order ) {
		if ( $the_order = $this->get_go_to_order( $order ) ) {
			$this->set_current_order_id( $the_order->get_id() );
			$this->save();

			return $the_order;
		}

		return null;
	}

	/**
	 * @param BulkFulfillmentOrder|integer $order
	 *
	 * @return BulkFulfillmentOrder|null
	 */
	public function get_go_to_order( $order ) {
		$current_order = $this->get_current_order_id();
		$this->set_current_order_id( $order );
		$go_to_order = $this->get_orders()['current'];

		$this->set_current_order_id( $current_order );

		return $go_to_order;
	}

	/**
	 * @return BulkFulfillmentOrder|null
	 */
	public function next_order() {
		if ( $next = $this->get_next_order() ) {
			$this->set_current_order_id( $next->get_id() );
			$this->save();

			return $next;
		}

		return null;
	}

	/**
	 * @return BulkFulfillmentOrder|null
	 */
	public function prev_order() {
		if ( $prev = $this->get_prev_order() ) {
			$this->set_current_order_id( $prev->get_id() );
			$this->save();

			return $prev;
		}

		return null;
	}

	/**
	 * @return BulkFulfillmentOrder|null
	 */
	public function get_next_order() {
		return $this->get_orders()['next'];
	}

	/**
	 * @return BulkFulfillmentOrder|null
	 */
	public function get_prev_order() {
		return $this->get_orders()['prev'];
	}

	public function initialize( $args = array() ) {
		$args = wp_parse_args(
			$args,
			array(
				'limit'                  => 50,
				'offset'                 => 0,
				'is_manual'              => true,
				'order'                  => 'ASC',
				'remove_existing_orders' => false,
			)
		);

		if ( $this->is_initialized() ) {
			return false;
		}

		if ( $args['remove_existing_orders'] ) {
			$this->get_data_store()->delete_orders( $this->get_id() );
			$this->orders = null;
		}

		$query_args = array(
			'limit'           => $args['limit'],
			'offset'          => $args['offset'],
			'order'           => $args['order'],
			'date_start'      => $this->get_date_start(),
			'date_end'        => $this->get_date_end(),
			'shipping_status' => $this->get_filter( 'shipping_status', array() ),
		);

		$orders = $this->get_data_store()->fetch_orders( $query_args );

		if ( ! empty( $orders ) ) {
			foreach ( $orders as $order_result ) {
				if ( $order = wc_get_order( $order_result->order_id ) ) {
					if ( $this->include_order( $order ) ) {
						if ( $fulfillment_order = $this->get_order_instance() ) {
							$fulfillment_order->set_order_id( $order->get_id() );
							$fulfillment_order->save();
						}
					}
				}
			}
		}

		if ( empty( $orders ) || count( $orders ) < $args['limit'] ) {
			$this->set_progress( 0 );
			$this->set_order_count( $this->get_data_store()->get_order_count( $this->get_id() ) );
			$this->set_first_order_id( $this->get_data_store()->get_first_order_id( $this->get_id() ) );
			$this->set_last_order_id( $this->get_data_store()->get_last_order_id( $this->get_id() ) );
			$this->set_current_order_id( $this->get_first_order_id() );

			$this->set_is_initialized( true );
			$this->save();

			return false;
		} else {
			return true;
		}
	}

	public function get_url( $order_id, $action = '' ) {
		if ( is_a( $order_id, '\Vendidero\Shiptastic\BulkFulfillments\BulkFulfillmentOrder' ) ) {
			$order_id = $order_id->get_id();
		}

		return add_query_arg(
			array(
				'id'       => $this->get_id(),
				'order_id' => $order_id,
				'action'   => $action,
			),
			admin_url( 'admin.php?page=wc-shiptastic-fulfillment' )
		);
	}

	/**
	 * @param \WC_Order $order
	 *
	 * @return boolean
	 */
	protected function include_order( $order ) {
		return true;
	}
}
