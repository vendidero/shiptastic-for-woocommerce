<?php

namespace Vendidero\Shiptastic\BulkFulfillments;

use Vendidero\Shiptastic\DataStores\FulfillmentWorkflow;

defined( 'ABSPATH' ) || exit;

/**
 * Keeps the fulfillment queue in sync with orders: one queue item per order package (location + shipping method)
 * that still needs fulfillment, i.e. contains items not yet in a shipment or shipments not yet shipped.
 *
 * Event-driven (order/shipment changes, deferred via Action Scheduler) plus a periodic reconcile based on the order
 * modification date. Only active while at least one active workflow exists.
 */
class QueueSync {

	const SYNC_HOOK = 'woocommerce_shiptastic_fulfillment_queue_sync_order';

	const RECONCILE_HOOK = 'woocommerce_shiptastic_fulfillment_queue_reconcile';

	const RECONCILE_INTERVAL = 300;

	const RECONCILE_BATCH_SIZE = 100;

	const CURSOR_OPTION = 'woocommerce_shiptastic_fulfillment_queue_cursor';

	const GROUP = 'woocommerce-shiptastic-fulfillment';

	/**
	 * Order ids to sync at the end of the request.
	 *
	 * @var int[]
	 */
	protected static $pending = array();

	public static function init() {
		add_action( 'woocommerce_order_status_changed', array( __CLASS__, 'queue_order' ), 20, 1 );
		add_action( 'woocommerce_update_order', array( __CLASS__, 'queue_order' ), 20, 1 );
		add_action( 'woocommerce_order_refunded', array( __CLASS__, 'queue_order' ), 20, 1 );

		add_action( 'woocommerce_shiptastic_new_shipment', array( __CLASS__, 'queue_shipment_order' ), 20, 2 );
		add_action( 'woocommerce_shiptastic_shipment_updated', array( __CLASS__, 'queue_shipment_order' ), 20, 2 );
		add_action( 'woocommerce_shiptastic_shipment_deleted', array( __CLASS__, 'queue_shipment_order' ), 20, 2 );

		add_action( 'shutdown', array( __CLASS__, 'schedule_pending' ), 20 );
		add_action( self::SYNC_HOOK, array( __CLASS__, 'sync_order' ), 10, 1 );
		add_action( self::RECONCILE_HOOK, array( __CLASS__, 'reconcile' ), 10, 0 );

		add_action( 'admin_init', array( __CLASS__, 'maybe_schedule_reconcile' ) );
		add_action( 'woocommerce_shiptastic_new_fulfillment_workflow', array( __CLASS__, 'maybe_schedule_reconcile' ) );
		add_action( 'woocommerce_shiptastic_fulfillment_workflow_updated', array( __CLASS__, 'maybe_schedule_reconcile' ) );
		add_action( 'woocommerce_shiptastic_fulfillment_workflow_deleted', array( __CLASS__, 'maybe_schedule_reconcile' ) );
	}

	/**
	 * The queue is only maintained while at least one active workflow exists.
	 *
	 * @return bool
	 */
	public static function is_enabled() {
		return (bool) apply_filters( 'woocommerce_shiptastic_fulfillment_queue_enabled', ! empty( FulfillmentWorkflow::get_active_workflow_ids() ) );
	}

	/**
	 * Order statuses which are ready for fulfillment.
	 *
	 * @return string[]
	 */
	public static function get_eligible_statuses() {
		return array_map(
			function ( $status ) {
				return 'wc-' === substr( $status, 0, 3 ) ? substr( $status, 3 ) : $status;
			},
			(array) apply_filters( 'woocommerce_shiptastic_fulfillment_queue_eligible_order_statuses', array( 'processing' ) )
		);
	}

	public static function queue_order( $order_id ) {
		if ( is_a( $order_id, 'WC_Order' ) ) {
			$order_id = $order_id->get_id();
		}

		$order_id = absint( $order_id );

		if ( $order_id && self::is_enabled() ) {
			self::$pending[ $order_id ] = $order_id;
		}
	}

	/**
	 * @param int $shipment_id
	 * @param \Vendidero\Shiptastic\Shipment $shipment
	 */
	public static function queue_shipment_order( $shipment_id, $shipment ) {
		if ( is_a( $shipment, '\Vendidero\Shiptastic\Shipment' ) && 'simple' === $shipment->get_type() ) {
			self::queue_order( $shipment->get_order_id() );
		}
	}

	/**
	 * Defers syncing changed orders (e.g. during checkout) to Action Scheduler.
	 */
	public static function schedule_pending() {
		$pending       = self::$pending;
		self::$pending = array();

		if ( empty( $pending ) ) {
			return;
		}

		if ( ! function_exists( 'WC' ) || ! WC()->queue() ) {
			foreach ( $pending as $order_id ) {
				self::sync_order( $order_id );
			}

			return;
		}

		$queue = WC()->queue();

		foreach ( $pending as $order_id ) {
			$args = array( 'order_id' => $order_id );

			if ( ! $queue->get_next( self::SYNC_HOOK, $args, self::GROUP ) ) {
				$queue->schedule_single( time() + 5, self::SYNC_HOOK, $args, self::GROUP );
			}
		}
	}

	public static function maybe_schedule_reconcile() {
		if ( ! function_exists( 'WC' ) || ! WC()->queue() ) {
			return;
		}

		$queue     = WC()->queue();
		$scheduled = $queue->get_next( self::RECONCILE_HOOK, array(), self::GROUP );

		if ( self::is_enabled() && ! $scheduled ) {
			$queue->schedule_recurring( time() + 10, self::RECONCILE_INTERVAL, self::RECONCILE_HOOK, array(), self::GROUP );
		} elseif ( ! self::is_enabled() && $scheduled ) {
			$queue->cancel_all( self::RECONCILE_HOOK, array(), self::GROUP );
		}
	}

	/**
	 * @param string $location
	 * @param string $shipping_method
	 *
	 * @return string
	 */
	public static function get_package_key( $location, $shipping_method ) {
		return $location . '|' . $shipping_method;
	}

	/**
	 * Returns the packages of an order which still need fulfillment: items not yet part of a shipment
	 * (incl. backordered units) and shipments not yet shipped, grouped by location + shipping method.
	 *
	 * @param \Vendidero\Shiptastic\Order $order_shipment
	 *
	 * @return array package_key => location, shipping_method, shipping_provider, item_count, weight, has_backorders
	 */
	public static function get_order_packages( $order_shipment ) {
		$packages = array();
		$add      = function ( $location, $method, $provider, $item_count, $weight, $has_backorders ) use ( &$packages ) {
			$key = self::get_package_key( $location, $method );

			if ( ! isset( $packages[ $key ] ) ) {
				$packages[ $key ] = array(
					'location'          => $location,
					'shipping_method'   => $method,
					'shipping_provider' => '',
					'item_count'        => 0,
					'weight'            => 0.0,
					'has_backorders'    => false,
				);
			}

			if ( empty( $packages[ $key ]['shipping_provider'] ) ) {
				$packages[ $key ]['shipping_provider'] = (string) $provider;
			}

			$packages[ $key ]['item_count']    += absint( $item_count );
			$packages[ $key ]['weight']        += (float) $weight;
			$packages[ $key ]['has_backorders'] = $packages[ $key ]['has_backorders'] || $has_backorders;
		};

		foreach ( $order_shipment->get_packages() as $package ) {
			$provider = $order_shipment->get_shipping_provider( $package['method_id'] );

			$add(
				(string) $package['location'],
				(string) $order_shipment->get_shipping_method_id( $package['method_id'] ),
				$provider ? $provider->get_name() : '',
				array_sum( $package['item_map'] ),
				$package['weight'],
				! empty( $package['has_backorders'] )
			);
		}

		foreach ( $order_shipment->get_simple_shipments() as $shipment ) {
			if ( $shipment->is_shipped() ) {
				continue;
			}

			$add(
				(string) $shipment->get_location(),
				(string) $shipment->get_shipping_method(),
				$shipment->get_shipping_provider(),
				$shipment->get_item_count(),
				(float) wc_get_weight( $shipment->get_content_weight(), get_option( 'woocommerce_weight_unit', 'kg' ), $shipment->get_weight_unit() ),
				false
			);
		}

		/**
		 * Filter to adjust the packages of an order which need fulfillment.
		 *
		 * @param array $packages
		 * @param \Vendidero\Shiptastic\Order $order_shipment
		 *
		 * @package Vendidero/Shiptastic
		 */
		return apply_filters( 'woocommerce_shiptastic_fulfillment_queue_order_packages', $packages, $order_shipment );
	}

	/**
	 * Whether all shipments of a package have been shipped (used to tell done from removed).
	 *
	 * @param \Vendidero\Shiptastic\Order $order_shipment
	 * @param string $package_key
	 *
	 * @return bool
	 */
	protected static function package_has_shipped_shipments( $order_shipment, $package_key ) {
		foreach ( $order_shipment->get_simple_shipments() as $shipment ) {
			if ( $shipment->is_shipped() && self::get_package_key( $shipment->get_location(), $shipment->get_shipping_method() ) === $package_key ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Syncs the queue items of an order.
	 *
	 * - Eligible packages are inserted (pending) or updated; done/removed items are reopened in case they need
	 *   fulfillment again (e.g. items added).
	 * - Items whose package needs no fulfillment anymore become done (shipped) or removed (e.g. order cancelled).
	 * - Locked items keep their status (and version) - in case they lost their eligibility they are flagged as stale.
	 *
	 * @param int $order_id
	 *
	 * @return QueueItem[] The order's queue items after sync, keyed by package key.
	 */
	public static function sync_order( $order_id ) {
		global $wpdb;

		$order_id = absint( $order_id );
		$order    = wc_get_order( $order_id );
		$existing = Queue::get_by_order( $order_id );
		$now      = gmdate( 'Y-m-d H:i:s' );
		$packages = array();
		$is_done  = false;

		if ( $order && is_a( $order, 'WC_Order' ) && 'shop_order_refund' !== $order->get_type() ) {
			$order_shipment = wc_stc_get_shipment_order( $order );

			if ( $order_shipment && $order->has_status( self::get_eligible_statuses() ) ) {
				$packages = self::get_order_packages( $order_shipment );
			}

			$is_done = $order_shipment && in_array( $order_shipment->get_shipping_status(), array( 'shipped', 'delivered' ), true );
		} else {
			$order_shipment = null;
		}

		foreach ( $packages as $key => $package ) {
			$columns = array(
				'queue_location'          => $package['location'],
				'queue_shipping_method'   => $package['shipping_method'],
				'queue_shipping_provider' => $package['shipping_provider'],
				'queue_country'           => substr( strtoupper( $order->has_shipping_address() ? $order->get_shipping_country() : $order->get_billing_country() ), 0, 2 ),
				'queue_item_count'        => $package['item_count'],
				'queue_weight'            => wc_format_decimal( $package['weight'], 4 ),
				'queue_has_backorders'    => $package['has_backorders'] ? 1 : 0,
			);

			if ( ! isset( $existing[ $key ] ) ) {
				$date_queued = $order->get_date_paid() ? $order->get_date_paid() : $order->get_date_created();

				$wpdb->insert(
					$wpdb->stc_fulfillment_queue,
					array_merge(
						$columns,
						array(
							'queue_order_id'          => $order_id,
							'queue_package_key'       => $key,
							'queue_status'            => 'pending',
							'queue_date_queued_gmt'   => $date_queued ? gmdate( 'Y-m-d H:i:s', $date_queued->getTimestamp() ) : $now,
							'queue_date_modified_gmt' => $now,
						)
					)
				);

				continue;
			}

			$item    = $existing[ $key ];
			$changed = array_diff_assoc( array_map( 'strval', $columns ), array_map( 'strval', array_intersect_key( $item->get_data_raw(), $columns ) ) );

			if ( $item->is_locked() ) {
				/**
				 * Do not change the status/version of locked items, the lock holder works on them.
				 */
				$update = array_merge( $columns, array( 'queue_is_stale' => 0 ) );
			} else {
				$update = $columns;

				if ( in_array( $item->get_status(), array( 'done', 'removed' ), true ) || ( 'locked' === $item->get_status() ) ) {
					// Reopen or reset an expired lock.
					$update  = array_merge(
						$update,
						array(
							'queue_status'           => 'pending',
							'queue_workflow_id'      => 0,
							'queue_locked_by'        => 0,
							'queue_lock_source'      => '',
							'queue_lock_token'       => '',
							'queue_lock_expires_gmt' => null,
						)
					);
					$changed = true;
				}

				$update['queue_is_stale'] = 0;

				if ( ! empty( $changed ) || $item->is_stale() ) {
					$update['queue_version'] = $item->get_version() + 1;
				}
			}

			if ( ! empty( $changed ) || $item->is_stale() ) {
				$update['queue_date_modified_gmt'] = $now;
				$wpdb->update( $wpdb->stc_fulfillment_queue, $update, array( 'queue_id' => $item->get_id() ) );
			}
		}

		/**
		 * Items without a package needing fulfillment.
		 */
		foreach ( $existing as $key => $item ) {
			if ( isset( $packages[ $key ] ) || in_array( $item->get_status(), array( 'done', 'removed' ), true ) ) {
				continue;
			}

			if ( $item->is_locked() ) {
				if ( ! $item->is_stale() ) {
					$wpdb->update(
						$wpdb->stc_fulfillment_queue,
						array(
							'queue_is_stale'          => 1,
							'queue_date_modified_gmt' => $now,
						),
						array( 'queue_id' => $item->get_id() )
					);
				}

				continue;
			}

			$status = ( $is_done || ( $order_shipment && self::package_has_shipped_shipments( $order_shipment, $key ) ) ) ? 'done' : 'removed';

			$wpdb->update(
				$wpdb->stc_fulfillment_queue,
				array(
					'queue_status'            => $status,
					'queue_is_stale'          => 0,
					'queue_workflow_id'       => 0,
					'queue_locked_by'         => 0,
					'queue_lock_source'       => '',
					'queue_lock_token'        => '',
					'queue_lock_expires_gmt'  => null,
					'queue_version'           => $item->get_version() + 1,
					'queue_date_modified_gmt' => $now,
				),
				array( 'queue_id' => $item->get_id() )
			);
		}

		$items = Queue::get_by_order( $order_id );

		/**
		 * Action that fires after the queue items of an order have been synced.
		 *
		 * @param int         $order_id The order id.
		 * @param QueueItem[] $items The queue items.
		 *
		 * @package Vendidero/Shiptastic
		 */
		do_action( 'woocommerce_shiptastic_fulfillment_queue_order_synced', $order_id, $items );

		return $items;
	}

	/**
	 * Periodic safety net: resets expired locks, re-syncs stale items and syncs orders modified since the last run.
	 * The initial run only considers orders with an eligible status.
	 *
	 * @return int Number of orders synced.
	 */
	public static function reconcile() {
		global $wpdb;

		Queue::reset_expired_locks();

		// Stale items whose lock expired/was released.
		foreach ( $wpdb->get_col( "SELECT DISTINCT queue_order_id FROM {$wpdb->stc_fulfillment_queue} WHERE queue_is_stale = 1 AND queue_status != 'locked'" ) as $order_id ) {
			self::sync_order( $order_id );
		}

		$cursor = absint( get_option( self::CURSOR_OPTION, 0 ) );
		$args   = array(
			'limit'   => self::RECONCILE_BATCH_SIZE,
			'orderby' => 'modified',
			'order'   => 'ASC',
			'return'  => 'objects',
			'type'    => 'shop_order',
		);

		if ( $cursor > 0 ) {
			$args['date_modified'] = '>' . $cursor;
		} else {
			$args['status'] = array_map(
				function ( $status ) {
					return 'wc-' . $status;
				},
				self::get_eligible_statuses()
			);
		}

		$orders = wc_get_orders( $args );

		foreach ( $orders as $order ) {
			self::sync_order( $order->get_id() );

			if ( $order->get_date_modified() ) {
				$cursor = max( $cursor, $order->get_date_modified()->getTimestamp() );
			}
		}

		if ( empty( $orders ) && 0 === $cursor ) {
			$cursor = time();
		}

		update_option( self::CURSOR_OPTION, $cursor, false );

		// Continue with the next batch right away.
		if ( count( $orders ) >= self::RECONCILE_BATCH_SIZE && function_exists( 'WC' ) && WC()->queue() ) {
			WC()->queue()->schedule_single( time(), self::RECONCILE_HOOK, array(), self::GROUP );
		}

		return count( $orders );
	}
}
