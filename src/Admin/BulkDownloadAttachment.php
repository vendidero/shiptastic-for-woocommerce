<?php

namespace Vendidero\Shiptastic\Admin;

use Exception;
use Vendidero\Shiptastic\Package;
use Vendidero\Shiptastic\PDFMerger;

defined( 'ABSPATH' ) || exit;

/**
 * Shipment Order
 *
 * @version     1.0.0
 * @author      Vendidero
 */
class BulkDownloadAttachment extends BulkDownload {

	protected $path = '';

	protected $attachment_type = '';

	public function get_action() {
		return "download_attachments_{$this->attachment_type}";
	}

	public function set_attachment_type( $attachment_type ) {
		$this->attachment_type = $attachment_type;
	}

	public function get_attachment_type() {
		return $this->attachment_type;
	}

	public function get_limit() {
		return 5;
	}

	public function handle() {
		$current = $this->get_current_ids();

		if ( ! empty( $current ) ) {
			foreach ( $current as $shipment_id ) {
				if ( $shipment = wc_stc_get_shipment( $shipment_id ) ) {
					$supported = $shipment->get_supported_attachment_types();

					if ( in_array( $this->get_attachment_type(), array_keys( $supported ), true ) ) {
						$type_data = $supported[ $this->get_attachment_type() ];

						if ( wc_stc_shipment_attachment_type_supports( $type_data, 'create' ) ) {
							$result = wc_stc_create_or_update_shipment_attachment( $shipment, $this->get_attachment_type() );

							if ( is_wp_error( $result ) ) {
								foreach ( $result->get_error_messages_by_type() as $type => $messages ) {
									foreach ( $messages as $message ) {
										if ( 'soft' === $type ) {
											$this->add_notice( sprintf( _x( 'Notice while creating %1$s for %2$s: %3$s', 'shipments', 'shiptastic-for-woocommerce' ), esc_html( $this->get_download_name() ), '<a href="' . esc_url( $shipment->get_edit_shipment_url() ) . '" target="_blank">' . sprintf( _x( 'shipment #%d', 'shipments', 'shiptastic-for-woocommerce' ), $shipment_id ) . '</a>', $message ), 'info' );
										} else {
											$this->add_notice( sprintf( _x( 'Error while creating %1$s for %2$s: %3$s', 'shipments', 'shiptastic-for-woocommerce' ), esc_html( $this->get_download_name() ), '<a href="' . esc_url( $shipment->get_edit_shipment_url() ) . '" target="_blank">' . sprintf( _x( 'shipment #%d', 'shipments', 'shiptastic-for-woocommerce' ), $shipment_id ) . '</a>', $message ), 'error' );
										}
									}
								}
							}
						}

						if ( $attachment = $shipment->get_attachment( $this->get_attachment_type() ) ) {
							if ( $attachment->has_file() ) {
								$this->add_file( $attachment->get_path() );
							}
						}
					}
				}
			}
		}

		if ( $this->is_last_step() ) {
			$this->create_bulk();
		}

		$this->update_notices();
	}

	protected function get_download_name( $plural = false ) {
		return wc_stc_get_shipment_attachment_type_name( $this->get_attachment_type(), $this->get_shipment_type(), $plural );
	}
}
