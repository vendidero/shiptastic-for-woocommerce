<?php

namespace Vendidero\Shiptastic\BulkFulfillments;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * The fulfillment queue: one first in, first out loop all workflows work on. Items are claimed (locked) atomically
 * per user/device session, locks expire unless extended via heartbeat.
 *
 * Statuses: pending (claimable), locked, error (exception inbox), done (shipped), removed (no longer eligible).
 */
class Queue {

	/**
	 * Lock lifetime for interactive sessions (extended via heartbeat, covers short offline periods).
	 */
	const LOCK_TTL_INTERACTIVE = 1800;

	/**
	 * Lock lifetime for background/scheduled runs (released after each chunk).
	 */
	const LOCK_TTL_BACKGROUND = 600;

	const CLAIM_LIMIT_INTERACTIVE = 10;

	const CLAIM_LIMIT_BACKGROUND = 25;

	public static function get_lock_sources() {
		return array( 'interactive', 'background', 'scheduled' );
	}

	/**
	 * @param string $source interactive|background|scheduled
	 *
	 * @return int Seconds
	 */
	public static function get_lock_ttl( $source = 'interactive' ) {
		$ttl = 'interactive' === $source ? self::LOCK_TTL_INTERACTIVE : self::LOCK_TTL_BACKGROUND;

		return absint( apply_filters( 'woocommerce_shiptastic_fulfillment_lock_ttl', $ttl, $source ) );
	}

	/**
	 * @param string $source interactive|background|scheduled
	 *
	 * @return int
	 */
	public static function get_claim_limit( $source = 'interactive' ) {
		$limit = 'interactive' === $source ? self::CLAIM_LIMIT_INTERACTIVE : self::CLAIM_LIMIT_BACKGROUND;

		return max( 1, absint( apply_filters( 'woocommerce_shiptastic_fulfillment_claim_limit', $limit, $source ) ) );
	}

	protected static function now( $offset = 0 ) {
		return gmdate( 'Y-m-d H:i:s', time() + $offset );
	}

	/**
	 * @param int $queue_id
	 *
	 * @return QueueItem|null
	 */
	public static function get( $queue_id ) {
		global $wpdb;

		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->stc_fulfillment_queue} WHERE queue_id = %d", absint( $queue_id ) ) );

		return $row ? new QueueItem( $row ) : null;
	}

	/**
	 * @param int $order_id
	 *
	 * @return QueueItem[] keyed by package key
	 */
	public static function get_by_order( $order_id ) {
		global $wpdb;

		$items = array();

		foreach ( $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->stc_fulfillment_queue} WHERE queue_order_id = %d ORDER BY queue_id ASC", absint( $order_id ) ) ) as $row ) {
			$item                              = new QueueItem( $row );
			$items[ $item->get_package_key() ] = $item;
		}

		return $items;
	}

	/**
	 * @param array $args status (string|array), location, order_id, limit, offset
	 *
	 * @return QueueItem[]
	 */
	public static function query( $args = array() ) {
		global $wpdb;

		$args  = wp_parse_args(
			$args,
			array(
				'status'   => array(),
				'location' => null,
				'order_id' => 0,
				'limit'    => 50,
				'offset'   => 0,
			)
		);
		$where = array( '1=1' );

		if ( ! empty( $args['status'] ) ) {
			$where[] = self::in_sql( 'queue_status', (array) $args['status'] );
		}

		if ( ! is_null( $args['location'] ) ) {
			$where[] = $wpdb->prepare( 'queue_location = %s', $args['location'] );
		}

		if ( ! empty( $args['order_id'] ) ) {
			$where[] = $wpdb->prepare( 'queue_order_id = %d', $args['order_id'] );
		}

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- Conditions are prepared fragments.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->stc_fulfillment_queue} WHERE " . implode( ' AND ', $where ) . ' ORDER BY queue_date_queued_gmt ASC, queue_id ASC LIMIT %d, %d', absint( $args['offset'] ), absint( $args['limit'] ) ) );
		// phpcs:enable

		return array_map(
			function ( $row ) {
				return new QueueItem( $row );
			},
			$rows
		);
	}

	/**
	 * @return array status => count
	 */
	public static function count_by_status() {
		global $wpdb;

		$counts = array_fill_keys( array( 'pending', 'locked', 'error', 'done', 'removed' ), 0 );

		foreach ( $wpdb->get_results( "SELECT queue_status AS status, COUNT(*) AS count FROM {$wpdb->stc_fulfillment_queue} GROUP BY queue_status" ) as $row ) {
			$counts[ $row->status ] = absint( $row->count );
		}

		return $counts;
	}

	protected static function in_sql( $column, $values, $format = '%s' ) {
		global $wpdb;

		$values = array_values( (array) $values );

		if ( empty( $values ) ) {
			return '1=0';
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		return $wpdb->prepare( "{$column} IN (" . implode( ',', array_fill( 0, count( $values ), $format ) ) . ')', $values );
	}

	/**
	 * Builds the SQL conditions for a workflow's filters.
	 *
	 * @param Workflow $workflow
	 *
	 * @return string[]
	 */
	public static function get_filter_conditions( $workflow ) {
		global $wpdb;

		$filters    = Workflow::sanitize_filters( $workflow->get_filters() );
		$conditions = array();

		if ( ! empty( $filters['locations'] ) ) {
			$conditions[] = self::in_sql( 'queue_location', $filters['locations'] );
		}

		if ( ! empty( $filters['shipping_providers'] ) ) {
			$conditions[] = self::in_sql( 'queue_shipping_provider', $filters['shipping_providers'] );
		}

		if ( ! empty( $filters['shipping_methods'] ) ) {
			$methods = array();

			foreach ( $filters['shipping_methods'] as $method ) {
				if ( strstr( $method, ':' ) ) {
					$methods[] = $wpdb->prepare( 'queue_shipping_method = %s', $method );
				} else {
					// Method without instance id: match all instances.
					$methods[] = $wpdb->prepare( '( queue_shipping_method = %s OR queue_shipping_method LIKE %s )', $method, $wpdb->esc_like( $method ) . ':%' );
				}
			}

			$conditions[] = '( ' . implode( ' OR ', $methods ) . ' )';
		}

		if ( ! empty( $filters['countries'] ) ) {
			$conditions[] = self::in_sql( 'queue_country', $filters['countries'] );
		}

		if ( '' !== $filters['min_items'] ) {
			$conditions[] = $wpdb->prepare( 'queue_item_count >= %d', $filters['min_items'] );
		}

		if ( '' !== $filters['max_items'] ) {
			$conditions[] = $wpdb->prepare( 'queue_item_count <= %d', $filters['max_items'] );
		}

		if ( '' !== $filters['min_weight'] ) {
			$conditions[] = $wpdb->prepare( 'queue_weight >= %f', $filters['min_weight'] );
		}

		if ( '' !== $filters['max_weight'] ) {
			$conditions[] = $wpdb->prepare( 'queue_weight <= %f', $filters['max_weight'] );
		}

		if ( 'exclude' === $filters['backorders'] ) {
			$conditions[] = 'queue_has_backorders = 0';
		} elseif ( 'only' === $filters['backorders'] ) {
			$conditions[] = 'queue_has_backorders = 1';
		}

		/**
		 * Filter to adjust the SQL conditions used to claim queue items for a workflow.
		 *
		 * @param string[] $conditions SQL conditions (prepared).
		 * @param Workflow $workflow The workflow.
		 *
		 * @package Vendidero/Shiptastic
		 */
		return apply_filters( 'woocommerce_shiptastic_fulfillment_queue_filter_conditions', $conditions, $workflow );
	}

	/**
	 * Claims (locks) the next queue items (first in, first out) matching a workflow's filters which still have open
	 * steps for the workflow. The claim is atomic: concurrent claims never return the same item.
	 *
	 * @param Workflow $workflow
	 * @param array $args limit, user_id, source (interactive|background|scheduled), ttl
	 *
	 * @return QueueItem[] The claimed items, all sharing the same lock token.
	 */
	public static function claim( $workflow, $args = array() ) {
		global $wpdb;

		$args = wp_parse_args(
			$args,
			array(
				'limit'   => null,
				'user_id' => get_current_user_id(),
				'source'  => 'interactive',
				'ttl'     => null,
			)
		);

		$source  = in_array( $args['source'], self::get_lock_sources(), true ) ? $args['source'] : 'interactive';
		$limit   = is_null( $args['limit'] ) ? self::get_claim_limit( $source ) : max( 1, absint( $args['limit'] ) );
		$ttl     = is_null( $args['ttl'] ) ? self::get_lock_ttl( $source ) : absint( $args['ttl'] );
		$actions = $workflow->get_step_actions();

		if ( ! $workflow->get_id() || empty( $actions ) ) {
			return array();
		}

		$table      = $wpdb->stc_fulfillment_queue;
		$token      = wp_generate_password( 32, false );
		$now        = self::now();
		$conditions = array_merge(
			array(
				$wpdb->prepare( "( queue_status = 'pending' OR ( queue_status = 'locked' AND queue_lock_expires_gmt < %s ) )", $now ),
				'queue_is_stale = 0',
				// The item must still have open (not done) steps for this workflow.
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
					"( SELECT COUNT( DISTINCT steps.step_action ) FROM {$wpdb->stc_fulfillment_steps} AS steps WHERE steps.step_queue_id = {$table}.queue_id AND steps.step_shipment_id = 0 AND steps.step_status = 'done' AND steps.step_action IN (" . implode( ',', array_fill( 0, count( $actions ), '%s' ) ) . ') ) < %d',
					array_merge( $actions, array( count( $actions ) ) )
				),
			),
			self::get_filter_conditions( $workflow )
		);

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- Conditions are prepared fragments.
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET queue_status = 'locked', queue_workflow_id = %d, queue_locked_by = %d, queue_lock_source = %s, queue_lock_token = %s, queue_lock_expires_gmt = %s, queue_version = queue_version + 1, queue_date_modified_gmt = %s
				WHERE " . implode( ' AND ', $conditions ) . '
				ORDER BY queue_date_queued_gmt ASC, queue_id ASC
				LIMIT %d',
				$workflow->get_id(),
				absint( $args['user_id'] ),
				$source,
				$token,
				self::now( $ttl ),
				$now,
				$limit
			)
		);
		// phpcs:enable

		return self::get_by_token( $token );
	}

	/**
	 * @param string $token
	 *
	 * @return QueueItem[]
	 */
	public static function get_by_token( $token ) {
		global $wpdb;

		if ( empty( $token ) ) {
			return array();
		}

		return array_map(
			function ( $row ) {
				return new QueueItem( $row );
			},
			$wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->stc_fulfillment_queue} WHERE queue_lock_token = %s AND queue_status = 'locked' ORDER BY queue_date_queued_gmt ASC, queue_id ASC", $token ) )
		);
	}

	/**
	 * Extends the locks of all items claimed with a token. Locks which expired but were not claimed by
	 * someone else in the meantime are renewed, too.
	 *
	 * @param string $token
	 * @param int|null $ttl
	 *
	 * @return int Number of items extended.
	 */
	public static function heartbeat( $token, $ttl = null ) {
		global $wpdb;

		if ( empty( $token ) ) {
			return 0;
		}

		$ttl = is_null( $ttl ) ? self::get_lock_ttl() : absint( $ttl );

		return absint(
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE {$wpdb->stc_fulfillment_queue} SET queue_lock_expires_gmt = %s WHERE queue_lock_token = %s AND queue_status = 'locked'",
					self::now( $ttl ),
					$token
				)
			)
		);
	}

	/**
	 * Verifies that a write to a queue item is allowed: the item must still be locked with the token and, in case
	 * a version is passed, must not have been changed in the meantime (optimistic concurrency, e.g. offline sync).
	 *
	 * @param int $queue_id
	 * @param string $token
	 * @param int|null $version
	 *
	 * @return true|WP_Error
	 */
	public static function verify_lock( $queue_id, $token, $version = null ) {
		$item = self::get( $queue_id );

		if ( ! $item ) {
			return new WP_Error( 'queue_item_not_found', _x( 'The queue item does not exist.', 'shipments', 'shiptastic-for-woocommerce' ), array( 'status' => 404 ) );
		}

		if ( 'locked' !== $item->get_status() || empty( $token ) || ! hash_equals( $item->get_lock_token(), (string) $token ) ) {
			return new WP_Error( 'queue_lock_lost', _x( 'The order is no longer reserved for you, e.g. because your lock expired and someone else continued fulfilling it.', 'shipments', 'shiptastic-for-woocommerce' ), array( 'status' => 409 ) );
		}

		if ( ! is_null( $version ) && absint( $version ) !== $item->get_version() ) {
			return new WP_Error( 'queue_version_conflict', _x( 'The order has been changed in the meantime. Please reload it.', 'shipments', 'shiptastic-for-woocommerce' ), array( 'status' => 409 ) );
		}

		return true;
	}

	/**
	 * Increments the version of a locked item after a successful write.
	 *
	 * @param int $queue_id
	 * @param string $token
	 *
	 * @return int|WP_Error The new version.
	 */
	public static function bump_version( $queue_id, $token ) {
		global $wpdb;

		$verified = self::verify_lock( $queue_id, $token );

		if ( is_wp_error( $verified ) ) {
			return $verified;
		}

		$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->stc_fulfillment_queue} SET queue_version = queue_version + 1, queue_date_modified_gmt = %s WHERE queue_id = %d AND queue_lock_token = %s", self::now(), $queue_id, $token ) );

		return self::get( $queue_id )->get_version();
	}

	/**
	 * Releases a locked item, e.g. after the workflow's last step finished or the item was skipped. The item is
	 * synced afterwards: it becomes pending again (other workflows may continue), done (shipped) or removed.
	 *
	 * @param int $queue_id
	 * @param string $token
	 *
	 * @return true|WP_Error
	 */
	public static function release( $queue_id, $token ) {
		global $wpdb;

		$item = self::get( $queue_id );

		if ( ! $item ) {
			return new WP_Error( 'queue_item_not_found', _x( 'The queue item does not exist.', 'shipments', 'shiptastic-for-woocommerce' ), array( 'status' => 404 ) );
		}

		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->stc_fulfillment_queue} SET queue_status = 'pending', queue_workflow_id = 0, queue_locked_by = 0, queue_lock_source = '', queue_lock_token = '', queue_lock_expires_gmt = NULL, queue_version = queue_version + 1, queue_date_modified_gmt = %s WHERE queue_id = %d AND queue_lock_token = %s AND queue_status = 'locked'",
				self::now(),
				$queue_id,
				$token
			)
		);

		if ( ! $updated ) {
			return new WP_Error( 'queue_lock_lost', _x( 'The order is no longer reserved for you.', 'shipments', 'shiptastic-for-woocommerce' ), array( 'status' => 409 ) );
		}

		QueueSync::sync_order( $item->get_order_id() );

		return true;
	}

	/**
	 * Releases all items locked with a token.
	 *
	 * @param string $token
	 *
	 * @return int Number of released items.
	 */
	public static function release_all( $token ) {
		$count = 0;

		foreach ( self::get_by_token( $token ) as $item ) {
			if ( true === self::release( $item->get_id(), $token ) ) {
				++$count;
			}
		}

		return $count;
	}

	/**
	 * Moves a locked item to the exception inbox (status error) and releases its lock.
	 *
	 * @param int $queue_id
	 * @param string $token
	 * @param string $message
	 *
	 * @return true|WP_Error
	 */
	public static function mark_error( $queue_id, $token, $message ) {
		global $wpdb;

		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->stc_fulfillment_queue} SET queue_status = 'error', queue_last_error = %s, queue_locked_by = 0, queue_lock_source = '', queue_lock_token = '', queue_lock_expires_gmt = NULL, queue_version = queue_version + 1, queue_date_modified_gmt = %s WHERE queue_id = %d AND queue_lock_token = %s AND queue_status = 'locked'",
				wp_strip_all_tags( (string) $message ),
				self::now(),
				$queue_id,
				$token
			)
		);

		return $updated ? true : new WP_Error( 'queue_lock_lost', _x( 'The order is no longer reserved for you.', 'shipments', 'shiptastic-for-woocommerce' ), array( 'status' => 409 ) );
	}

	/**
	 * Resets an item (e.g. from the exception inbox or a stuck lock) to pending, regardless of its lock.
	 *
	 * @param int $queue_id
	 *
	 * @return bool
	 */
	public static function reset( $queue_id ) {
		global $wpdb;

		$item = self::get( $queue_id );

		if ( ! $item ) {
			return false;
		}

		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->stc_fulfillment_queue} SET queue_status = 'pending', queue_last_error = NULL, queue_workflow_id = 0, queue_locked_by = 0, queue_lock_source = '', queue_lock_token = '', queue_lock_expires_gmt = NULL, queue_version = queue_version + 1, queue_date_modified_gmt = %s WHERE queue_id = %d",
				self::now(),
				$queue_id
			)
		);

		QueueSync::sync_order( $item->get_order_id() );

		return true;
	}

	/**
	 * Sets expired locks back to pending (keeps overviews accurate, claims treat expired locks as pending anyway).
	 *
	 * @return int Number of items reset.
	 */
	public static function reset_expired_locks() {
		global $wpdb;

		return absint(
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE {$wpdb->stc_fulfillment_queue} SET queue_status = 'pending', queue_workflow_id = 0, queue_locked_by = 0, queue_lock_source = '', queue_lock_token = '', queue_lock_expires_gmt = NULL, queue_version = queue_version + 1, queue_date_modified_gmt = %s WHERE queue_status = 'locked' AND queue_lock_expires_gmt < %s",
					self::now(),
					self::now()
				)
			)
		);
	}
}
