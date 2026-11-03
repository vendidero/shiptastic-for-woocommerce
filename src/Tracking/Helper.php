<?php

namespace Vendidero\Shiptastic\Tracking;

use Vendidero\Shiptastic\Package;
use Vendidero\Shiptastic\Shipment;
use Vendidero\Shiptastic\ShipmentQuery;

defined( 'ABSPATH' ) || exit;

class Helper {

	public static function init() {
		add_action( 'action_scheduler_ensure_recurring_actions', array( __CLASS__, 'setup_recurring_actions' ) );
		add_action( 'woocommerce_shiptastic_shipments_tracking', array( __CLASS__, 'init_batch_tracking' ) );
		add_action( 'woocommerce_shiptastic_shipments_tracking_single_run', array( __CLASS__, 'init_single_run' ) );
		add_action( 'woocommerce_shiptastic_shipments_tracking_track', array( __CLASS__, 'track' ) );

		add_action( 'rest_api_init', array( __CLASS__, 'register_tracking_event_endpoints' ) );
		add_action( 'woocommerce_shiptastic_shipment_created_label', array( __CLASS__, 'subscribe_to_remote_events' ), 20, 2 );
		add_action( 'woocommerce_shiptastic_return_shipment_created_label', array( __CLASS__, 'subscribe_to_remote_events' ), 20, 2 );
	}

	/**
	 * @param Shipment $shipment
	 *
	 * @return void
	 */
	public static function subscribe_to_remote_events( $shipment ) {
		if ( $provider = $shipment->get_shipping_provider_instance() ) {
			if ( is_a( $provider, 'Vendidero\Shiptastic\Interfaces\ShippingProviderAuto' ) ) {
				if ( $provider->supports_remote_shipment_status( 'push' ) && $provider->enable_remote_shipment_status_update( 'push' ) ) {
					$provider->subscribe_to_shipment_status_events( array( $shipment ) );
				}
			}
		}
	}

	public static function get_tracking_callback_url( $provider_name ) {
		return get_rest_url( null, "shiptastic/v1/{$provider_name}/track" );
	}

	public static function register_tracking_event_endpoints() {
		foreach ( \Vendidero\Shiptastic\ShippingProvider\Helper::instance()->get_available_shipping_providers() as $provider ) {
			if ( is_a( $provider, 'Vendidero\Shiptastic\Interfaces\ShippingProviderAuto' ) ) {
				if ( $provider->supports_remote_shipment_status( 'push' ) && $provider->enable_remote_shipment_status_update( 'push' ) ) {
					register_rest_route(
						'shiptastic/v1',
						"{$provider->get_name()}/track",
						array(
							'methods'             => \WP_REST_Server::EDITABLE,
							'callback'            => function ( $request ) use ( $provider ) {
								$result = $provider->handle_remote_shipment_status_update( $request );

								if ( is_a( $result, 'Vendidero\Shiptastic\Tracking\ShipmentStatus' ) ) {
									if ( $shipment = $result->get_shipment() ) {
										$shipment->update_remote_status( $result );
									}

									$result = new \WP_REST_Response( array( 'success' => true ) );
									$result->set_status( 200 );
								}

								return rest_ensure_response( $result );
							},
							'permission_callback' => '__return_true',
						)
					);
				}
			}
		}
	}

	public static function get_status_update_types() {
		return array(
			'pull' => _x( 'Pull', 'shipments', 'shiptastic-for-woocommerce' ),
			'push' => _x( 'Push', 'shipments', 'shiptastic-for-woocommerce' ),
		);
	}

	public static function init_single_run( $shipments_query ) {
		$shipments_query = wp_parse_args(
			$shipments_query,
			array(
				'shipping_provider' => '',
				'time_offset'       => time(),
				'offset'            => 0,
				'limit'             => 20,
			)
		);

		$time_offset     = absint( $shipments_query['time_offset'] );
		$shipments_query = array_diff_key(
			$shipments_query,
			array(
				'time_offset' => 0,
			)
		);

		$query     = new ShipmentQuery( $shipments_query );
		$shipments = $query->get_shipments();
		$provider  = wc_stc_get_shipping_provider( $shipments_query['shipping_provider'] );

		if ( ! empty( $shipments ) && $provider && is_a( $provider, 'Vendidero\Shiptastic\Interfaces\ShippingProviderAuto' ) ) {
			$single_query = array_diff_key(
				$shipments_query,
				array(
					'has_tracking' => true,
					'offset'       => 0,
					'return'       => 'ids',
				)
			);

			$number_of_shipments = $provider->get_number_of_shipments_per_status_check( 'pull' );

			if ( count( $shipments ) > $number_of_shipments ) {
				$chunks = array_chunk( $shipments, $number_of_shipments, true );
			} else {
				$chunks = array( $shipments );
			}

			foreach ( $chunks as $i => $inner_shipments ) {
				$single_query['include'] = $inner_shipments;

				Package::log( sprintf( 'Queueing %s shipment status refresh for:', $provider->get_title() ), 'info', 'tracking' );
				Package::log( wc_print_r( $inner_shipments, true ), 'info', 'tracking' );

				$cur_page = ceil( $shipments_query['offset'] / $shipments_query['limit'] );

				self::get_queue()->schedule_single(
					$time_offset + 150 + ( $cur_page * 60 ) + ( $i * 5 ),
					'woocommerce_shiptastic_shipments_tracking_track',
					array( 'query' => $single_query ),
					'woocommerce_shiptastic_tracking'
				);
			}
		}
	}

	public static function track( $shipments_query ) {
		/**
		 * If there are woocommerce_shiptastic_shipments_tracking_single_run actions left we did not finish
		 * building the query yet - postpone the event.
		 */
		if ( self::get_queue()->get_next( 'woocommerce_shiptastic_shipments_tracking_single_run', null, 'woocommerce_shiptastic_tracking' ) ) {
			self::get_queue()->schedule_single(
				time() + 150,
				'woocommerce_shiptastic_shipments_tracking_track',
				array( 'query' => $shipments_query ),
				'woocommerce_shiptastic_tracking'
			);

			return;
		}

		$shipments_query = wp_parse_args(
			$shipments_query,
			array(
				'shipping_provider' => '',
			)
		);

		if ( $provider = wc_stc_get_shipping_provider( $shipments_query['shipping_provider'] ) ) {
			if ( is_a( $provider, 'Vendidero\Shiptastic\Interfaces\ShippingProviderAuto' ) ) {
				if ( $provider->supports_remote_shipment_status( 'pull' ) && $provider->enable_remote_shipment_status_update( 'pull' ) ) {
					$query     = new ShipmentQuery( $shipments_query );
					$shipments = $query->get_shipments();

					Package::log( sprintf( 'Retrieving %s remote shipment status for %d shipments', $provider->get_title(), count( $shipments ) ), 'info', 'tracking' );

					if ( ! empty( $shipments ) ) {
						$statuses = $provider->get_remote_status_for_shipments( $shipments );
						$updated  = array();

						Package::log( sprintf( 'Retrieved %d %s remote statuses:', count( $statuses ), $provider->get_title() ), 'info', 'tracking' );

						if ( is_array( $statuses ) ) {
							foreach ( $statuses as $status ) {
								if ( $shipment = $status->get_shipment() ) {
									Package::log( wc_print_r( $status->get_data(), true ), 'info', 'tracking' );

									if ( $shipment->update_remote_status( $status ) ) {
										$updated[] = $shipment->get_id();
									}
								}
							}
						}

						/**
						 * Schedule the next check for shipments without changes (or without a remote status).
						 * Updated shipments already received their next tracking date while saving the new remote status.
						 */
						foreach ( $shipments as $shipment ) {
							if ( ! in_array( $shipment->get_id(), $updated, true ) ) {
								$shipment->get_data_store()->update_next_tracking_date( $shipment, self::get_next_tracking_date( $shipment ) );
							}
						}
					}
				}
			}
		}
	}

	public static function init_batch_tracking() {
		$supported_providers = array();

		foreach ( \Vendidero\Shiptastic\ShippingProvider\Helper::instance()->get_available_shipping_providers() as $provider ) {
			if ( is_a( $provider, 'Vendidero\Shiptastic\Interfaces\ShippingProviderAuto' ) ) {
				if ( $provider->supports_remote_shipment_status( 'pull' ) && $provider->enable_remote_shipment_status_update( 'pull' ) ) {
					$supported_providers[ $provider->get_name() ] = $provider;
				}
			}
		}

		if ( ! empty( $supported_providers ) ) {
			/**
			 * Skip the run (e.g. during the night) in case none of the tracking hours
			 * has passed since the last run. A missing last run leads to the run being executed.
			 */
			$cur_time = time();
			$last_run = absint( get_option( 'woocommerce_shiptastic_shipments_tracking_last_run', 0 ) );

			update_option( 'woocommerce_shiptastic_shipments_tracking_last_run', $cur_time, false );

			if ( $last_run > 0 && $last_run < $cur_time ) {
				$all_hours = array();

				foreach ( self::get_tracking_hours() as $hours ) {
					$all_hours = array_merge( $all_hours, (array) $hours );
				}

				if ( ! self::has_tracking_hour_between( $all_hours, max( $last_run, $cur_time - DAY_IN_SECONDS ), $cur_time ) ) {
					return;
				}
			}

			foreach ( $supported_providers as $provider ) {
				$cutoff_date = new \WC_DateTime( 'now' );
				$cutoff_date->modify( '-4 weeks' );

				$per_batch_run   = 20;
				$shipments_query = apply_filters(
					'woocommerce_shiptastic_shipment_tracking_query',
					array(
						'shipping_provider' => $provider->get_name(),
						'has_tracking'      => true,
						'type'              => array( 'simple', 'return' ),
						'limit'             => $per_batch_run,
						'orderby'           => 'date_created',
						'order'             => 'ASC',
						'status'            => array( 'shipped', 'ready-for-shipping' ),
						'date_created'      => '>=' . $cutoff_date->getTimestamp(),
						'tracking_due'      => $cur_time,
						'paginate'          => true,
						'count_total'       => true,
						'offset'            => 0,
						'return'            => 'ids',
					)
				);

				$query = new ShipmentQuery( $shipments_query );
				$query->get_shipments();

				$total                = $query->get_total();
				$pages                = ceil( $total / $per_batch_run );
				$timeout_between_runs = 50;
				$max_exec_time        = $cur_time + ( ( $pages - 1 ) * $timeout_between_runs );

				Package::log( sprintf( 'Refreshing remote status for %d %s shipments', $total, $provider->get_title() ), 'info', 'tracking' );

				/**
				 * Loop all shipment pages and create an action which actually queries
				 * the shipments but does not yet refresh statuses to prevent the pagination
				 * from being disrupted by status updates. Instead, query shipment ids first and
				 * then schedule another action, after the last loop query, to actually refresh the status.
				 *
				 * Need to pass the args as associative array as the action scheduler extract the args.
				 */
				for ( $i = 0; $i < $pages; $i++ ) {
					$single_query                = array_diff_key(
						$shipments_query,
						array(
							'count_total' => false,
							'paginate'    => false,
						)
					);
					$single_query['offset']      = $i * $per_batch_run;
					$single_query['time_offset'] = $max_exec_time;

					self::get_queue()->schedule_single(
						$cur_time + ( $i * $timeout_between_runs ),
						'woocommerce_shiptastic_shipments_tracking_single_run',
						array( 'query' => $single_query ),
						'woocommerce_shiptastic_tracking'
					);
				}
			}
		}
	}

	/**
	 * Returns the tracking hours (site time) per tracking tier.
	 *
	 * @return int[][]
	 */
	public static function get_tracking_hours() {
		return apply_filters(
			'woocommerce_shiptastic_shipments_tracking_hours',
			array(
				'expected_today'  => range( 6, 21 ),
				'in_transit'      => array( 6, 10, 14, 18, 22 ),
				'awaiting_pickup' => array( 6, 18 ),
				'stale'           => array( 6 ),
			)
		);
	}

	/**
	 * Determines the tracking tier of a shipment based on its last remote status.
	 *
	 * @param Shipment $shipment
	 *
	 * @return string
	 */
	public static function get_tracking_tier( $shipment ) {
		$status      = $shipment->get_last_remote_status();
		$stale_after = absint( apply_filters( 'woocommerce_shiptastic_shipments_tracking_stale_after_in_seconds', WEEK_IN_SECONDS ) );
		$last_change = $status && $status->get_last_updated() ? $status->get_last_updated() : $shipment->get_date_created();

		if ( $status && $status->is_expected_today() ) {
			$tier = 'expected_today';
		} elseif ( $last_change && ( time() - $last_change->getTimestamp() ) > $stale_after ) {
			$tier = 'stale';
		} elseif ( ! $status || ! $status->is_in_transit() ) {
			$tier = 'awaiting_pickup';
		} else {
			$tier = 'in_transit';
		}

		return apply_filters( 'woocommerce_shiptastic_shipment_tracking_tier', $tier, $shipment );
	}

	/**
	 * Determines the next date (next tracking hour of the shipment's tracking tier) the remote status should be checked.
	 *
	 * @param Shipment $shipment
	 * @param int|null $after Timestamp, defaults to now.
	 *
	 * @return \WC_DateTime
	 */
	public static function get_next_tracking_date( $shipment, $after = null ) {
		$after          = is_null( $after ) ? time() : absint( $after );
		$tracking_hours = self::get_tracking_hours();
		$tier           = self::get_tracking_tier( $shipment );
		$fallback       = array_key_exists( 'in_transit', $tracking_hours ) ? (array) $tracking_hours['in_transit'] : array();
		$hours          = array_key_exists( $tier, $tracking_hours ) ? (array) $tracking_hours[ $tier ] : $fallback;
		$next           = null;

		if ( ! empty( $hours ) ) {
			$day = new \DateTime( "@{$after}" );
			$day->setTimezone( wp_timezone() );
			$day->setTime( 0, 0 );

			// Today and tomorrow are sufficient as every hour exists at least once within that range.
			for ( $i = 0; $i < 2; $i++ ) {
				foreach ( $hours as $hour ) {
					$slot = clone $day;
					$slot->setTime( absint( $hour ), 0 );

					if ( $slot->getTimestamp() > $after && ( is_null( $next ) || $slot->getTimestamp() < $next ) ) {
						$next = $slot->getTimestamp();
					}
				}

				$day->modify( '+1 day' );
			}
		}

		/**
		 * Re-evaluate the shipment daily in case no tracking hours are configured for its tier.
		 */
		if ( is_null( $next ) ) {
			$next = $after + DAY_IN_SECONDS;
		}

		$next_date = new \WC_DateTime( "@{$next}" );
		$next_date->setTimezone( new \DateTimeZone( 'UTC' ) );

		return apply_filters( 'woocommerce_shiptastic_shipment_next_tracking_date', $next_date, $shipment, $after );
	}

	/**
	 * @param int[] $hours Hours (0-23) in site time
	 * @param int $start Timestamp (exclusive)
	 * @param int $end Timestamp (inclusive)
	 *
	 * @return bool
	 */
	protected static function has_tracking_hour_between( $hours, $start, $end ) {
		$timezone = wp_timezone();
		$day      = new \DateTime( "@{$start}" );
		$last_day = new \DateTime( "@{$end}" );

		$day->setTimezone( $timezone );
		$day->setTime( 0, 0 );
		$last_day->setTimezone( $timezone );

		while ( $day <= $last_day ) {
			foreach ( $hours as $hour ) {
				$slot = clone $day;
				$slot->setTime( absint( $hour ), 0 );

				if ( $slot->getTimestamp() > $start && $slot->getTimestamp() <= $end ) {
					return true;
				}
			}

			$day->modify( '+1 day' );
		}

		return false;
	}

	protected static function get_queue() {
		return function_exists( 'WC' ) ? WC()->queue() : false;
	}

	public static function setup_recurring_actions() {
		// Check if Action Scheduler is available.
		if ( ! function_exists( 'as_schedule_recurring_action' ) || ! function_exists( 'as_schedule_single_action' ) ) {
			return;
		}

		/**
		 * The interval in which due shipments are determined. Which shipments are actually tracked
		 * during a run depends on the shipment's tracking hours, see Helper::get_tracking_hours().
		 */
		$tracking_interval         = max( 1, absint( apply_filters( 'woocommerce_shiptastic_shipments_tracking_interval_in_seconds', HOUR_IN_SECONDS ) ) );
		$current_tracking_interval = absint( get_option( 'woocommerce_shiptastic_shipments_tracking_interval', HOUR_IN_SECONDS ) );

		if ( $current_tracking_interval !== $tracking_interval ) {
			as_unschedule_all_actions( 'woocommerce_shiptastic_shipments_tracking' );
			update_option( 'woocommerce_shiptastic_shipments_tracking_interval', $tracking_interval, false );
		}

		if ( ! as_has_scheduled_action( 'woocommerce_shiptastic_shipments_tracking' ) ) {
			// Start at the next full hour (plus some random minutes to spread API requests) to run shortly after the tracking hours.
			$next_full_hour = ( (int) floor( time() / HOUR_IN_SECONDS ) + 1 ) * HOUR_IN_SECONDS;

			as_schedule_recurring_action( $next_full_hour + wp_rand( 2, 15 ) * MINUTE_IN_SECONDS, $tracking_interval, 'woocommerce_shiptastic_shipments_tracking', array(), 'woocommerce_shiptastic_tracking', true );
		}
	}
}
