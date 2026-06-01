<?php

namespace Vendidero\Shiptastic\Admin;

use Exception;
use Vendidero\Shiptastic\Package;
use Vendidero\Shiptastic\PDFMerger;

defined( 'ABSPATH' ) || exit;

/**
 * Shipment Order
 *
 * @class       WC_STC_Shipment_Order
 * @version     1.0.0
 * @author      Vendidero
 */
class BulkLabel extends BulkDownload {

	public function get_action() {
		return 'labels';
	}

	public function get_limit() {
		return 1;
	}

	protected function get_file_type() {
		return 'pdf';
	}

	protected function get_download_name( $plural = false ) {
		return $plural ? _x( 'Labels', 'shipments-label-type-plural', 'shiptastic-for-woocommerce' ) : _x( 'Label', 'shipments-label-type', 'shiptastic-for-woocommerce' );
	}

	public function handle() {
		$current = $this->get_current_ids();

		if ( ! empty( $current ) ) {
			foreach ( $current as $shipment_id ) {
				$label = false;

				if ( $shipment = wc_stc_get_shipment( $shipment_id ) ) {
					if ( $shipment->supports_label() ) {
						if ( $shipment->needs_label() ) {
							$result = $shipment->create_label();

							if ( is_wp_error( $result ) ) {
								$result = wc_stc_get_shipment_error( $result );
							}

							if ( is_wp_error( $result ) ) {
								foreach ( $result->get_error_messages_by_type() as $type => $messages ) {
									foreach ( $messages as $message ) {
										if ( 'soft' === $type ) {
											$this->add_notice( sprintf( _x( 'Notice while creating label for %1$s: %2$s', 'shipments', 'shiptastic-for-woocommerce' ), '<a href="' . esc_url( $shipment->get_edit_shipment_url() ) . '" target="_blank">' . sprintf( _x( 'shipment #%d', 'shipments', 'shiptastic-for-woocommerce' ), $shipment_id ) . '</a>', $message ), 'info' );
										} else {
											$this->add_notice( sprintf( _x( 'Error while creating label for %1$s: %2$s', 'shipments', 'shiptastic-for-woocommerce' ), '<a href="' . esc_url( $shipment->get_edit_shipment_url() ) . '" target="_blank">' . sprintf( _x( 'shipment #%d', 'shipments', 'shiptastic-for-woocommerce' ), $shipment_id ) . '</a>', $message ), 'error' );
										}
									}
								}
							}

							if ( $shipment->has_label() ) {
								$label = $shipment->get_label();
							}
						} else {
							$label = $shipment->get_label();
						}
					}
				}

				if ( $label ) {
					$this->add_file( $label->get_file() );
				}
			}
		}

		if ( $this->is_last_step() ) {
			$this->create_bulk();
		}

		$this->update_notices();
	}
}
