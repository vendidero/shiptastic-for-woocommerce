<?php

namespace Vendidero\Shiptastic\Fulfillments;

use Vendidero\Shiptastic\Emails;
use Vendidero\Shiptastic\SimpleShipment;

defined( 'ABSPATH' ) || exit;

class Helper {

	public static function init() {
		add_filter( 'woocommerce_rest_api_get_rest_namespaces', array( __CLASS__, 'override_controllers' ), 11 );
		add_filter( 'woocommerce_fulfillment_calculate_order_fulfillment_status', array( __CLASS__, 'override_order_fulfillment_status' ), 10, 2 );
		add_filter( 'woocommerce_fulfillment_fulfillment_statuses', array( __CLASS__, 'register_fulfillment_statuses' ), 10 );
		add_filter( 'woocommerce_fulfillment_order_fulfillment_statuses', array( __CLASS__, 'register_order_fulfillment_statuses' ), 10 );
		add_filter( 'woocommerce_fulfillment_shipping_providers', array( __CLASS__, 'register_shipping_providers' ), 10 );
		add_action( 'woocommerce_after_data_object_save', array( __CLASS__, 'sync_shipments' ), 99999, 2 );
		add_action( 'woocommerce_shiptastic_order_update_shipping_status', array( __CLASS__, 'sync_fulfillment_order_status' ), 10, 2 );
		add_filter( 'woocommerce_email_classes', array( __CLASS__, 'deregister_fulfillment_notifications' ), 99 );
		add_action( 'woocommerce_shiptastic_sync_fulfillments_callback', array( __CLASS__, 'sync_fulfillments' ), 10, 1 );
	}

	public static function has_fulfillments_feature_enabled() {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			if ( \Automattic\WooCommerce\Utilities\FeaturesUtil::feature_is_enabled( 'fulfillments' ) ) {
				return true;
			}
		}

		return false;
	}

	public static function get_fulfillments( $limit = 50, $offset = 0 ) {
		global $wpdb;
		$entity_type = \WC_Order::class;

		$wpdb->hide_errors();
		$existing_fulfillments = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}wc_order_fulfillments WHERE entity_type = %s AND date_deleted IS NULL ORDER BY fulfillment_id DESC LIMIT %d OFFSET %d",
				$entity_type,
				$limit,
				$offset
			),
			ARRAY_A
		);

		if ( is_wp_error( $existing_fulfillments ) ) {
			$existing_fulfillments = array();
		}

		return $existing_fulfillments;
	}

	public static function sync_fulfillments( $offset = 0 ) {
		$fulfillments = self::get_fulfillments( 50, $offset );

		if ( ! empty( $fulfillments ) && class_exists( '\Automattic\WooCommerce\Internal\Fulfillments\Fulfillment' ) ) {
			Emails::prevent_notifications();
			add_filter( 'woocommerce_shiptastic_shipment_order_mark_as_completed', '__return_false', 9999 );
			add_filter( 'woocommerce_shiptastic_shipment_needs_label', '__return_false', 9999 );
			add_filter( 'woocommerce_shiptastic_shipment_order_update_shipping_status', '__return_false', 9999 );

			foreach ( $fulfillments as $fulfillment ) {
				$fulfillment = new \Automattic\WooCommerce\Internal\Fulfillments\Fulfillment( $fulfillment['fulfillment_id'] );

				if ( $fulfillment->get_id() > 0 ) {
					if ( ! self::get_shipment_by_fulfillment( $fulfillment ) ) {
						self::sync_shipment_by_fulfillment( $fulfillment );
					}
				}
			}

			Emails::reset_notifications();
			remove_filter( 'woocommerce_shiptastic_shipment_order_mark_as_completed', '__return_false', 9999 );
			remove_filter( 'woocommerce_shiptastic_shipment_needs_label', '__return_false', 9999 );
			remove_filter( 'woocommerce_shiptastic_shipment_order_update_shipping_status', '__return_false', 9999 );
		}
	}

	public static function deregister_fulfillment_notifications( $emails ) {
		if ( isset( $emails['WC_Email_Customer_Fulfillment_Created'] ) ) {
			unset( $emails['WC_Email_Customer_Fulfillment_Created'] );
		}

		if ( isset( $emails['WC_Email_Customer_Fulfillment_Updated'] ) ) {
			unset( $emails['WC_Email_Customer_Fulfillment_Updated'] );
		}

		if ( isset( $emails['WC_Email_Customer_Fulfillment_Deleted'] ) ) {
			unset( $emails['WC_Email_Customer_Fulfillment_Deleted'] );
		}

		return $emails;
	}

	/**
	 * @param \WC_Order $order
	 * @param string $status
	 *
	 * @return void
	 */
	public static function sync_fulfillment_order_status( $order, $status ) {
		$order->update_meta_data( '_fulfillment_status', $status );
	}

	/**
	 * @param \WC_Data $data
	 * @param $data_store
	 *
	 * @return void
	 */
	public static function sync_shipments( $data, $data_store ) {
		if ( ! is_a( $data, 'Automattic\WooCommerce\Internal\Fulfillments\Fulfillment' ) || $data->get_id() <= 0 ) {
			return;
		}

		self::sync_shipment_by_fulfillment( $data );
	}

	/**
	 * @param \Automattic\WooCommerce\Internal\Fulfillments\Fulfillment $fulfillment
	 *
	 * @return SimpleShipment|false
	 */
	public static function get_shipment_by_fulfillment( $fulfillment ) {
		$shipment  = false;
		$shipments = wc_stc_get_shipments(
			array(
				'order_id'   => $fulfillment->get_entity_id(),
				'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'   => '_fulfillment_id',
						'value' => $fulfillment->get_id(),
					),
				),
				'limit'      => 1,
			)
		);

		if ( ! empty( $shipments ) ) {
			$shipment = $shipments[0];
		}

		return $shipment;
	}

	/**
	 * @param \Automattic\WooCommerce\Internal\Fulfillments\Fulfillment $fulfillment
	 *
	 * @return SimpleShipment|false
	 */
	public static function sync_shipment_by_fulfillment( $fulfillment ) {
		$shipment = self::get_shipment_by_fulfillment( $fulfillment );

		if ( ! $shipment ) {
			$shipment = new SimpleShipment();
		}

		$shipment->set_order_id( $fulfillment->get_entity_id() );

		$s_fulfillment = new Fulfillment( $shipment );
		$s_fulfillment->set_entity_id( $fulfillment->get_entity_id() );
		$s_fulfillment->sync();
		$s_fulfillment->set_props( $fulfillment->get_raw_data() );
		$s_fulfillment->set_meta_data( $fulfillment->get_raw_meta_data() );

		if ( ! empty( $s_fulfillment->get_items() ) ) {
			$items_to_sync = array();

			foreach ( $s_fulfillment->get_items() as $item ) {
				$item = wp_parse_args(
					$item,
					array(
						'item_id' => 0,
						'qty'     => 1,
					)
				);

				$items_to_sync[ $item['item_id'] ] = $item['qty'];
			}

			$s_fulfillment->sync_items( $items_to_sync );
			$s_fulfillment->update_meta_data( '_fulfillment_id', $fulfillment->get_id() );

			do_action( 'woocommerce_shiptastic_sync_shipment_by_fulfillment', $fulfillment, $s_fulfillment->get_shipment(), $s_fulfillment );

			if ( ! empty( $s_fulfillment->get_items() ) ) {
				$s_fulfillment->save();

				do_action( 'woocommerce_shiptastic_after_sync_shipment_by_fulfillment', $fulfillment, $s_fulfillment->get_shipment(), $s_fulfillment );

				return $s_fulfillment->get_shipment();
			}
		}

		return false;
	}

	public static function register_shipping_providers( $providers ) {
		foreach ( wc_stc_get_available_shipping_providers() as $provider ) {
			$provider_name = str_replace( '_', '-', $provider->get_name() );

			$providers[ $provider_name ] = new ShippingProvider( $provider );
		}

		return $providers;
	}

	public static function register_order_fulfillment_statuses( $statuses ) {
		$sent_statuses = wc_stc_get_shipment_order_shipping_sent_statuses();

		foreach ( wc_stc_get_shipment_order_shipping_statuses() as $status_name => $title ) {
			$default_color    = '#9a8210';
			$default_bg_color = '#fbf5e5';

			if ( 'no-shipping-needed' === $status_name ) {
				$default_color    = '#2F2F2F';
				$default_bg_color = '#F0F0F0';
			} if ( 'partially-shipped' === $status_name ) {
				$default_color    = '#003D66';
				$default_bg_color = '#C8D7E1';
			} elseif ( in_array( $status_name, $sent_statuses, true ) ) {
				$default_color    = '#13550F';
				$default_bg_color = '#C6E1C6';
			}

			$statuses[ $status_name ] = array(
				'label'            => $title,
				'background_color' => $default_bg_color,
				'text_color'       => $default_color,
			);
		}

		return $statuses;
	}

	public static function register_fulfillment_statuses( $statuses ) {
		$sent_statuses = wc_stc_get_shipment_sent_statuses();

		foreach ( wc_stc_get_shipment_statuses() as $status_name => $title ) {
			if ( 'requested' === $status_name ) {
				continue;
			}

			$is_fulfilled     = in_array( $status_name, $sent_statuses, true );
			$default_color    = '#9a8210';
			$default_bg_color = '#fbf5e5';

			if ( 'draft' === $status_name ) {
				$default_color    = '#CC1818';
				$default_bg_color = '#FBE5E5';
			} elseif ( $is_fulfilled ) {
				$default_color    = '#13550F';
				$default_bg_color = '#C6E1C6';
			}

			$statuses[ $status_name ] = array(
				'label'            => $title,
				'is_fulfilled'     => $is_fulfilled,
				'background_color' => $default_bg_color,
				'text_color'       => $default_color,
			);
		}

		return $statuses;
	}

	public static function override_controllers( $controller ) {
		$controller['wc/v3']['order_fulfillments'] = FulfillmentsRestController::class;

		return $controller;
	}

	/**
	 * @param string $status
	 * @param \WC_Order $order
	 *
	 * @return string
	 */
	public static function override_order_fulfillment_status( $status, $order ) {
		$shipment_order  = wc_stc_get_shipment_order( $order );
		$shipping_status = $shipment_order->get_shipping_status();

		return $shipping_status;
	}
}
