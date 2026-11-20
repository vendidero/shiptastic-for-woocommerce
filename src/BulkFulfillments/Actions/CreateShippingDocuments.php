<?php

namespace Vendidero\Shiptastic\BulkFulfillments\Actions;

use Vendidero\Shiptastic\BulkFulfillments\Action;
use Vendidero\Shiptastic\BulkFulfillments\Documents;
use Vendidero\Shiptastic\BulkFulfillments\StepResult;

defined( 'ABSPATH' ) || exit;

/**
 * Shipping documents of a shipment (per shipment): packing slip and - for exports - the commercial invoice, grouped
 * with the export declaration (ABD, Ausfuhranmeldung) in case the goods value exceeds the threshold.
 *
 * Shiptastic: the documents may be uploaded within the app (shipment attachments), all of them are optional. Shiptastic
 * Pro replaces the action (same name) to create the packing slip/commercial invoice, the export declaration stays an
 * upload.
 */
class CreateShippingDocuments extends Action {

	public static function get_name() {
		return 'create_shipping_documents';
	}

	public static function get_title() {
		return _x( 'Shipping documents', 'shipments', 'shiptastic-for-woocommerce' );
	}

	public static function get_description() {
		return _x( 'Optionally upload the packing slip and, for exports, the commercial invoice and the export declaration.', 'shipments', 'shiptastic-for-woocommerce' );
	}

	public static function get_scopes() {
		return array( 'shipment' );
	}

	public static function requires_network() {
		return true;
	}

	public static function get_settings_schema() {
		return array(
			'documents'     => array(
				'type'        => 'multiselect',
				'label'       => _x( 'Documents', 'shipments', 'shiptastic-for-woocommerce' ),
				'default'     => array(),
				'options'     => array(
					'packing_slip'       => Documents::get_title( 'packing_slip' ),
					'commercial_invoice' => Documents::get_title( 'commercial_invoice' ),
				),
				'all_label'   => _x( 'All documents', 'shipments', 'shiptastic-for-woocommerce' ),
				'add_label'   => _x( '+ Add document…', 'shipments', 'shiptastic-for-woocommerce' ),
				'empty_label' => _x( 'Nothing selected yet - packing slips and, for exports, commercial invoices are included.', 'shipments', 'shiptastic-for-woocommerce' ),
			),
			'abd_threshold' => array(
				'type'    => 'number',
				/* translators: %s currency symbol */
				'label'   => sprintf( _x( 'Export declaration above a goods value of (%s)', 'shipments', 'shiptastic-for-woocommerce' ), get_woocommerce_currency_symbol() ),
				'default' => 1000,
			),
		);
	}

	/**
	 * @param \Vendidero\Shiptastic\BulkFulfillments\StepContext $context
	 *
	 * @return string[] Chosen document types (packing_slip, commercial_invoice).
	 */
	protected function get_document_types( $context ) {
		$types = array_values( array_intersect( array_map( 'strval', (array) $context->get_setting( 'documents', array() ) ), array( 'packing_slip', 'commercial_invoice' ) ) );

		return empty( $types ) ? array( 'packing_slip', 'commercial_invoice' ) : $types;
	}

	/**
	 * @param \Vendidero\Shiptastic\BulkFulfillments\StepContext $context
	 * @param \Vendidero\Shiptastic\Shipment $shipment
	 *
	 * @return array[] Document rows of the shipment (see Documents::get_row()).
	 */
	protected function get_rows( $context, $shipment ) {
		$rows      = array();
		$supported = $shipment->get_supported_attachment_types();

		foreach ( $this->get_document_types( $context ) as $type ) {
			// Commercial invoices for exports only.
			if ( array_key_exists( $type, $supported ) ) {
				$rows[] = Documents::get_row( $type, $shipment );
			}
		}

		$threshold = (float) wc_format_decimal( $context->get_setting( 'abd_threshold', 1000 ) );

		if ( Documents::needs_export_declaration( $shipment, $threshold ) ) {
			$rows[] = Documents::get_row(
				'abd',
				$shipment,
				array(
					'hint' => _x( 'Needed for this export. You may continue without it and upload it later within the shipment.', 'shipments', 'shiptastic-for-woocommerce' ),
				)
			);
		}

		return $rows;
	}

	public function get_view_data( $context ) {
		$shipment = $context->get_shipment();

		return array(
			'description' => static::get_description(),
			'shipmentId'  => $shipment ? $shipment->get_id() : 0,
			'documents'   => $shipment ? $this->get_rows( $context, $shipment ) : array(),
			'notice'      => $shipment ? '' : _x( 'There is no shipment, e.g. because all items are on backorder.', 'shipments', 'shiptastic-for-woocommerce' ),
		);
	}

	public function process( $context, $data ) {
		$shipment = $context->get_shipment();

		if ( ! $shipment ) {
			return StepResult::error( _x( 'There is no shipment, e.g. because all items are on backorder.', 'shipments', 'shiptastic-for-woocommerce' ), 'no_shipment' );
		}

		$rows = $this->get_rows( $context, $shipment );

		// Documents are optional: the uploaded ones are added to the run's files.
		return Documents::add_files( StepResult::done(), $shipment, wp_list_pluck( $rows, 'type' ) );
	}
}
