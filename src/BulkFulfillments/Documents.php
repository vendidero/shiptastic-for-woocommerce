<?php

namespace Vendidero\Shiptastic\BulkFulfillments;

use Vendidero\Shiptastic\Package;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Documents of the shipping documents step: shipment attachments (packing slip, commercial invoice, export
 * declaration/ABD), optionally uploaded within the fulfillment app. Shiptastic Pro replaces the step with the actual
 * document creation (see ActionRegistry) and adds further document steps (e.g. invoices).
 */
class Documents {

	/**
	 * @return array type => title of the document types used by the document steps.
	 */
	public static function get_types() {
		/**
		 * Filter the document types which may be uploaded within the fulfillment app (shipment attachment types).
		 *
		 * @param array $types type => title
		 *
		 * @package Vendidero/Shiptastic
		 */
		return (array) apply_filters(
			'woocommerce_shiptastic_fulfillment_document_types',
			array(
				'packing_slip'       => _x( 'Packing slip', 'shipments-document', 'shiptastic-for-woocommerce' ),
				'commercial_invoice' => _x( 'Commercial invoice', 'shipments-document', 'shiptastic-for-woocommerce' ),
				'abd'                => _x( 'Export declaration (ABD)', 'shipments-document', 'shiptastic-for-woocommerce' ),
			)
		);
	}

	public static function get_title( $type ) {
		$types = self::get_types();

		return isset( $types[ $type ] ) ? $types[ $type ] : $type;
	}

	/**
	 * Whether the shipment needs an export declaration (Ausfuhranmeldung/ABD): exports (outside the EU customs area,
	 * from a base country supporting ABD documents) with a goods value above the threshold.
	 *
	 * @param \Vendidero\Shiptastic\Shipment $shipment
	 * @param float $threshold Goods value (shop currency) above which an export declaration is needed.
	 *
	 * @return bool
	 */
	public static function needs_export_declaration( $shipment, $threshold = 1000.0 ) {
		$needs = Package::base_country_supports_abd_document() && $shipment->is_shipping_international() && array_key_exists( 'abd', $shipment->get_supported_attachment_types() ) && (float) $shipment->get_total() > (float) $threshold;

		/**
		 * Filter whether a shipment needs an export declaration (ABD) within the fulfillment process.
		 *
		 * @param bool $needs
		 * @param \Vendidero\Shiptastic\Shipment $shipment
		 * @param float $threshold
		 *
		 * @package Vendidero/Shiptastic
		 */
		return (bool) apply_filters( 'woocommerce_shiptastic_fulfillment_needs_export_declaration', $needs, $shipment, $threshold );
	}

	/**
	 * @param \Vendidero\Shiptastic\Shipment $shipment
	 * @param string $type
	 *
	 * @return array|null name, url of the shipment's document (attachment) in case it exists.
	 */
	public static function get_file( $shipment, $type ) {
		$attachment = $shipment->get_attachment( $type );

		// Only methods of the Attachment interface: extensions provide their own attachments, e.g. StoreaBill packing slips.
		if ( ! $attachment || ! $attachment->has_file() ) {
			return null;
		}

		$path = (string) $attachment->get_path();

		return array(
			'name' => wp_basename( $path ),
			'url'  => $attachment->get_download_url(),
			'path' => $path,
		);
	}

	/**
	 * A document row of a step view.
	 *
	 * @param string $type
	 * @param \Vendidero\Shiptastic\Shipment|null $shipment The shipment holding the document (0: all package shipments).
	 * @param array $args required, hint, shipment_id, editable (settings of the document can be edited within the app,
	 *                    see get_form_html()), regenerable (the document can be (re-)created within the app, see
	 *                    run_action())
	 *
	 * @return array type, title, required, hint, shipmentId, editable, regenerable, file (name, url)|null
	 */
	public static function get_row( $type, $shipment, $args = array() ) {
		$args = wp_parse_args(
			$args,
			array(
				'required'    => false,
				'hint'        => '',
				'editable'    => false,
				'regenerable' => false,
				'shipment_id' => $shipment ? $shipment->get_id() : 0,
			)
		);
		$file = $shipment ? self::get_file( $shipment, $type ) : null;

		return array(
			'type'        => $type,
			'title'       => self::get_title( $type ),
			'required'    => (bool) $args['required'],
			'hint'        => (string) $args['hint'],
			'shipmentId'  => absint( $args['shipment_id'] ),
			'editable'    => (bool) $args['editable'],
			'regenerable' => (bool) $args['regenerable'],
			'file'        => $file ? array(
				'name' => $file['name'],
				'url'  => $file['url'],
			) : null,
		);
	}

	/**
	 * The settings form of a document (e.g. a commercial invoice's customs data) edited within the app (sheet), provided
	 * by extensions creating documents (rows marked as editable). Its values are saved via run_action( 'save' ) (field
	 * names parsed like PHP does).
	 *
	 * @param string $type
	 * @param \Vendidero\Shiptastic\Shipment $shipment
	 *
	 * @return string
	 */
	public static function get_form_html( $type, $shipment ) {
		// Forms are rendered via WooCommerce's admin field functions which are not loaded within REST requests.
		if ( ! function_exists( 'woocommerce_wp_select' ) && function_exists( 'WC' ) ) {
			include_once WC()->plugin_path() . '/includes/admin/wc-meta-box-functions.php';
		}

		/**
		 * Filter the settings form (HTML, admin field markup) of a document edited within the fulfillment app.
		 *
		 * @param string $html
		 * @param string $type Document type, e.g. commercial_invoice.
		 * @param \Vendidero\Shiptastic\Shipment $shipment
		 *
		 * @package Vendidero/Shiptastic
		 */
		return (string) apply_filters( 'woocommerce_shiptastic_fulfillment_document_form_html', '', $type, $shipment );
	}

	/**
	 * Runs a document action of an extension creating documents: `regenerate` (re-create the document, e.g. a packing
	 * slip) or `save` (create/update the document with the values of its settings form).
	 *
	 * @param string $action regenerate|save
	 * @param string $type
	 * @param \Vendidero\Shiptastic\Shipment $shipment
	 * @param array $data Form values (save).
	 *
	 * @return true|WP_Error
	 */
	public static function run_action( $action, $type, $shipment, $data = array() ) {
		/**
		 * Filter to run a document action within the fulfillment app.
		 *
		 * @param true|WP_Error|null $result null in case the action is not supported.
		 * @param string $action regenerate|save
		 * @param string $type Document type, e.g. packing_slip.
		 * @param \Vendidero\Shiptastic\Shipment $shipment
		 * @param array $data Form values (save).
		 *
		 * @package Vendidero/Shiptastic
		 */
		$result = apply_filters( 'woocommerce_shiptastic_fulfillment_document_action', null, $action, $type, $shipment, (array) $data );

		if ( is_null( $result ) ) {
			return new WP_Error( 'document_action_unsupported', _x( 'This document cannot be created here.', 'shipments', 'shiptastic-for-woocommerce' ), array( 'status' => 400 ) );
		} elseif ( false === $result ) {
			/* translators: %s document title */
			return new WP_Error( 'document_action_failed', sprintf( _x( 'The %s could not be created.', 'shipments', 'shiptastic-for-woocommerce' ), self::get_title( $type ) ), array( 'status' => 400 ) );
		}

		return $result;
	}

	/**
	 * Stores an uploaded document (PDF) as attachment of the shipments.
	 *
	 * @param \Vendidero\Shiptastic\Shipment[] $shipments
	 * @param string $type Attachment type.
	 * @param array $file Uploaded file ($_FILES entry).
	 *
	 * @return true|WP_Error
	 */
	public static function store_upload( $shipments, $type, $file ) {
		if ( empty( $file ) || ! empty( $file['error'] ) || empty( $file['tmp_name'] ) || ! is_uploaded_file( $file['tmp_name'] ) ) {
			return new WP_Error( 'document_upload_failed', _x( 'The file could not be uploaded.', 'shipments', 'shiptastic-for-woocommerce' ), array( 'status' => 400 ) );
		}

		$check = wp_check_filetype_and_ext( $file['tmp_name'], $file['name'], array( 'pdf' => 'application/pdf' ) );

		if ( 'application/pdf' !== $check['type'] ) {
			return new WP_Error( 'document_invalid_type', _x( 'Please upload a PDF file.', 'shipments', 'shiptastic-for-woocommerce' ), array( 'status' => 400 ) );
		}

		$bits = file_get_contents( $file['tmp_name'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

		foreach ( $shipments as $shipment ) {
			if ( ! array_key_exists( $type, $shipment->get_supported_attachment_types() ) ) {
				continue;
			}

			$attachment = wc_stc_create_shipment_attachment( $type );

			if ( is_wp_error( $attachment ) ) {
				return $attachment;
			}

			$name = sanitize_file_name( sprintf( '%1$s-%2$s-%3$s.pdf', $type, $shipment->get_id(), wp_generate_password( 8, false ) ) );
			$path = wc_shiptastic_upload_data( $name, $bits, true, false );

			if ( is_wp_error( $path ) ) {
				return $path;
			}

			$attachment->set_relative_path( $path );
			$attachment->set_extension( 'application/pdf' );
			$shipment->add_attachment( $attachment );
			$shipment->save();
		}

		return true;
	}

	/**
	 * Adds the documents (rows with a file) to the step result, e.g. for the run's files.
	 *
	 * @param StepResult $result
	 * @param \Vendidero\Shiptastic\Shipment $shipment
	 * @param string[] $types
	 *
	 * @return StepResult
	 */
	public static function add_files( $result, $shipment, $types ) {
		foreach ( $types as $type ) {
			$file = self::get_file( $shipment, $type );

			if ( $file && file_exists( $file['path'] ) ) {
				$result->add_file( $file['path'], $type . '.pdf' );
			}
		}

		return $result;
	}
}
