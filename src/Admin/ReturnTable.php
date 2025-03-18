<?php

namespace Vendidero\Shiptastic\Admin;

use Vendidero\Shiptastic\Package;
use Vendidero\Shiptastic\Shipment;
use Vendidero\Shiptastic\ReturnShipment;
use WP_List_Table;
use Vendidero\Shiptastic\ShipmentQuery;

defined( 'ABSPATH' ) || exit;

/**
 * Class Table
 * @package Vendidero/Shiptastic\Admin
 */
class ReturnTable extends Table {

	protected function get_custom_columns() {
		$columns = array();

		$columns['cb']         = '<input type="checkbox" />';
		$columns['title']      = _x( 'Title', 'shipments', 'shiptastic-for-woocommerce' );
		$columns['date']       = _x( 'Date', 'shipments', 'shiptastic-for-woocommerce' );
		$columns['status']     = _x( 'Status', 'shipments', 'shiptastic-for-woocommerce' );
		$columns['items']      = _x( 'Items', 'shipments', 'shiptastic-for-woocommerce' );
		$columns['sender']     = _x( 'Sender', 'shipments', 'shiptastic-for-woocommerce' );
		$columns['weight']     = _x( 'Weight', 'shipments', 'shiptastic-for-woocommerce' );
		$columns['dimensions'] = _x( 'Dimensions', 'shipments', 'shiptastic-for-woocommerce' );
		$columns['order']      = _x( 'Order', 'shipments', 'shiptastic-for-woocommerce' );
		$columns['actions']    = _x( 'Actions', 'shipments', 'shiptastic-for-woocommerce' );

		return $columns;
	}

	public function get_page_option() {
		return 'woocommerce_page_wc_stc_return_shipments_per_page';
	}

	/**
	 * @param ReturnShipment $shipment
	 * @param $actions
	 *
	 * @return mixed
	 */
	protected function get_custom_actions( $shipment, $actions ) {
		if ( isset( $actions['shipped'] ) ) {
			unset( $actions['shipped'] );
		}

		if ( ! $shipment->has_status( 'delivered' ) && ! $shipment->has_status( 'requested' ) ) {
			$actions['received'] = array(
				'url'    => wp_nonce_url( admin_url( 'admin-ajax.php?action=woocommerce_stc_update_shipment_status&status=delivered&shipment_id=' . $shipment->get_id() ), 'update-shipment-status' ),
				'name'   => _x( 'Delivered', 'shipments', 'shiptastic-for-woocommerce' ),
				'action' => 'delivered',
			);
		}

		if ( $shipment->has_status( 'processing' ) ) {
			$actions['email_notification'] = array(
				'url'    => wp_nonce_url( admin_url( 'admin-ajax.php?action=woocommerce_stc_send_return_shipment_notification_email&shipment_id=' . $shipment->get_id() ), 'send-return-shipment-notification' ),
				'name'   => _x( 'Send notification to customer', 'shipments', 'shiptastic-for-woocommerce' ),
				'action' => 'send-return-notification email',
			);
		}

		if ( $shipment->is_customer_requested() && $shipment->has_status( 'requested' ) ) {
			$actions['confirm'] = array(
				'url'    => wp_nonce_url( admin_url( 'admin-ajax.php?action=woocommerce_stc_confirm_return_request&shipment_id=' . $shipment->get_id() ), 'confirm-return-request' ),
				'name'   => _x( 'Confirm return request', 'shipments', 'shiptastic-for-woocommerce' ),
				'action' => 'confirm',
			);
		}

		return $actions;
	}

	public function get_main_page() {
		return 'admin.php?page=wc-stc-return-shipments';
	}

	protected function get_custom_bulk_actions( $actions ) {
		$actions['confirm_requests'] = _x( 'Confirm open return requests', 'shipments', 'shiptastic-for-woocommerce' );

		return $actions;
	}

	/**
	 * Handles the post author column output.
	 *
	 *
	 * @param ReturnShipment $shipment The current shipment object.
	 */
	public function column_sender( $shipment ) {
		$address = $shipment->get_formatted_sender_address();

		if ( $address ) {
			echo '<a target="_blank" href="' . esc_url( $shipment->get_address_map_url( $shipment->get_sender_address() ) ) . '">' . esc_html( preg_replace( '#<br\s*/?>#i', ', ', $address ) ) . '</a>';
		} else {
			echo '&ndash;';
		}
	}
}
