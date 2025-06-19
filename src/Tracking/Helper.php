<?php

namespace Vendidero\Shiptastic\Tracking;

use Vendidero\Shiptastic\ShipmentQuery;

defined( 'ABSPATH' ) || exit;

class Helper {

	public static function init() {
		add_action( 'init', array( __CLASS__, 'setup_recurring_actions' ), 10 );
		add_action( 'woocommerce_shiptastic_shipments_tracking', array( __CLASS__, 'init_batch_tracking' ) );
		add_action( 'woocommerce_shiptastic_shipments_tracking_single_run', array( __CLASS__, 'track' ) );
	}

	public static function track( $shipments_query ) {
		$shipments_query = wp_parse_args(
			$shipments_query,
			array(
				'shipping_provider' => '',
			)
		);

		$query     = new ShipmentQuery( $shipments_query );
		$shipments = $query->get_shipments();

		if ( ! empty( $shipments ) ) {
			if ( $provider = wc_stc_get_shipping_provider( $shipments_query['shipping_provider'] ) ) {
				if ( is_a( $provider, 'Vendidero\Shiptastic\Interfaces\ShippingProviderAuto' ) ) {
					if ( $provider->supports_remote_shipment_status() ) {
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
		$supported_providers = array( 'dhl' => wc_stc_get_shipping_provider( 'dhl' ) );

		foreach ( \Vendidero\Shiptastic\ShippingProvider\Helper::instance()->get_available_shipping_providers() as $provider ) {
			if ( is_a( $provider, 'Vendidero\Shiptastic\Interfaces\ShippingProviderAuto' ) ) {
				if ( $provider->supports_remote_shipment_status() ) {
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
						'date_created'      => '>= ' . $cutoff_date->getTimestamp(),
						'paginate'          => true,
						'count_total'       => true,
						'offset'            => 0,
						'return'            => 'ids',
					)
				);

				$query = new ShipmentQuery( $shipments_query );
				$query->get_shipments();

				$total = $query->get_total();
				$pages = ceil( $total / $per_batch_run );

				for ( $i = 0; $i < $pages; $i++ ) {
					$single_query           = array_diff_key(
						$shipments_query,
						array(
							'count_total' => false,
							'paginate'    => false,
							'return'      => '',
						)
					);
					$single_query['offset'] = $i * $per_batch_run;

					self::get_queue()->schedule_single(
						time() + ( $i * 50 ),
						'woocommerce_shiptastic_shipments_tracking_single_run',
						$shipments_query,
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
