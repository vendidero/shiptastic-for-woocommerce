<?php

namespace Vendidero\Shiptastic\Tracking;

use Vendidero\Shiptastic\Package;
use Vendidero\Shiptastic\ShipmentQuery;

defined( 'ABSPATH' ) || exit;

class Helper {

	public static function init() {
		add_action( 'init', array( __CLASS__, 'setup_recurring_actions' ), 10 );
		add_action( 'woocommerce_shiptastic_shipments_tracking', array( __CLASS__, 'init_batch_tracking' ) );
		add_action( 'woocommerce_shiptastic_shipments_tracking_single_run', array( __CLASS__, 'init_single_run' ) );
		add_action( 'woocommerce_shiptastic_shipments_tracking_track', array( __CLASS__, 'track' ) );
	}

	public static function init_single_run( $shipments_query ) {
		$shipments_query = wp_parse_args(
			$shipments_query,
			array(
				'shipping_provider' => '',
				'time_offset'       => time(),
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

		if ( ! empty( $shipments ) ) {
			$single_query = array_diff_key(
				$shipments_query,
				array(
					'has_tracking' => true,
					'offset'       => 0,
					'return'       => 'ids',
				)
			);

			$single_query['include'] = $shipments;

			self::get_queue()->schedule_single(
				$time_offset + 150,
				'woocommerce_shiptastic_shipments_tracking_track',
				array( 'query' => $single_query ),
				'woocommerce_shiptastic_tracking'
			);
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
				if ( $provider->supports_remote_shipment_status() && $provider->enable_remote_shipment_status_update() ) {
					$query     = new ShipmentQuery( $shipments_query );
					$shipments = $query->get_shipments();

					if ( ! empty( $shipments ) ) {
						$statuses = $provider->get_remote_status_for_shipments( $shipments );

						foreach ( $statuses as $status ) {
							if ( $shipment = $status->get_shipment() ) {
								$shipment->update_remote_status( $status );
							}
						}
					}
				}
			}
		}
	}

	public static function init_batch_tracking() {
		foreach ( \Vendidero\Shiptastic\ShippingProvider\Helper::instance()->get_available_shipping_providers() as $provider ) {
			if ( is_a( $provider, 'Vendidero\Shiptastic\Interfaces\ShippingProviderAuto' ) ) {
				if ( $provider->supports_remote_shipment_status() && $provider->enable_remote_shipment_status_update() ) {
					$supported_providers[ $provider->get_name() ] = $provider;
				}
			}
		}

		if ( ! empty( $supported_providers ) ) {
			foreach ( $supported_providers as $provider ) {
				$cutoff_date = new \WC_DateTime( 'now' );
				$cutoff_date->modify( '-4 weeks' );

				$per_batch_run   = 20;
				$shipments_query = apply_filters(
					'woocommerce_shiptastic_shipment_tracking_query',
					array(
						'shipping_provider' => $provider->get_name(),
						'has_tracking'      => true,
						'type'              => 'simple',
						'limit'             => $per_batch_run,
						'orderby'           => 'date_created',
						'order'             => 'ASC',
						'status'            => array( 'shipped', 'ready-to-ship' ),
						'date_created'      => '>=' . $cutoff_date->getTimestamp(),
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
				$cur_time             = time();
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

	protected static function get_queue() {
		return function_exists( 'WC' ) ? WC()->queue() : false;
	}

	public static function setup_recurring_actions() {
		if ( $queue = self::get_queue() ) {
			if ( null === $queue->get_next( 'woocommerce_shiptastic_shipments_tracking', array(), 'woocommerce_shiptastic_tracking' ) ) {
				$timestamp = strtotime( 'now' );

				$queue->cancel_all( 'woocommerce_shiptastic_shipments_tracking', array(), 'woocommerce_shiptastic' );

				$tracking_hour   = apply_filters( 'woocommerce_shiptastic_shipments_tracking_cron_hour', '20' );
				$tracking_minute = apply_filters( 'woocommerce_shiptastic_shipments_tracking_cron_minute', '0' );

				$queue->schedule_cron( $timestamp, "{$tracking_minute} {$tracking_hour} * * *", 'woocommerce_shiptastic_shipments_tracking', array(), 'woocommerce_shiptastic_tracking' );
			}
		}
	}
}
