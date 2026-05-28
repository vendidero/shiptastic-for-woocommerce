<?php
/**
 * Shipment Factory
 *
 * The shipment factory creates the right shipment objects.
 *
 * @version 1.0.0
 * @package Vendidero/Shiptastic
 */
namespace Vendidero\Shiptastic;

use Vendidero\Shiptastic\Caches\Helper;
use Vendidero\Shiptastic\Shipment;
use WC_Data_Store;
use Exception;

defined( 'ABSPATH' ) || exit;

/**
 * Shipment factory class
 */
class ShipmentFactory {

	/**
	 * Get shipment.
	 *
	 * @param  mixed $shipment_id (default: false) Shipment id to get or empty if new.
	 * @return SimpleShipment|ReturnShipment|bool
	 */
	public static function get_shipment( $shipment_id = false, $shipment_type = 'simple' ) {
		$shipment_id = self::get_shipment_id( $shipment_id );

		if ( $shipment_id ) {
			if ( $cache = Helper::get_cache_object( 'shipments' ) ) {
				$shipment = $cache->get( $shipment_id );

				if ( ! is_null( $shipment ) ) {
					return $shipment;
				}
			}

			$shipment_type = WC_Data_Store::load( 'shipment' )->get_shipment_type( $shipment_id );

			/**
			 * Shipment type cannot be found, seems to not exist.
			 */
			if ( empty( $shipment_type ) ) {
				return false;
			}

			$shipment_type_data = wc_stc_get_shipment_type_data( $shipment_type );
		} else {
			$shipment_type_data = wc_stc_get_shipment_type_data( $shipment_type );
		}

		if ( $shipment_type_data ) {
			$classname = $shipment_type_data['class_name'];
		} else {
			$classname = false;
		}

		/**
		 * Filter to adjust the classname used to construct a Shipment.
		 *
		 * @param string  $clasname The classname to be used.
		 * @param integer $shipment_id The shipment id.
		 * @param string  $shipment_type The shipment type.
		 *
		 * @package Vendidero/Shiptastic
		 */
		$classname = apply_filters( 'woocommerce_shiptastic_shipment_class', $classname, $shipment_id, $shipment_type );

		if ( ! class_exists( $classname ) ) {
			return false;
		}

		try {
			$shipment = new $classname( $shipment_id );

			if ( $shipment && $shipment_id > 0 && ( $cache = Helper::get_cache_object( 'shipments' ) ) ) {
				$cache->set( $shipment, $shipment_id );
			}

			return $shipment;
		} catch ( Exception $e ) {
			wc_caught_exception( $e, __FUNCTION__, array( $shipment_id, $shipment_type ) );
			return false;
		}
	}

	public static function get_shipment_id( $shipment ) {
		if ( is_numeric( $shipment ) ) {
			return $shipment;
		} elseif ( $shipment instanceof Shipment ) {
			return $shipment->get_id();
		} elseif ( ! empty( $shipment->shipment_id ) ) {
			return $shipment->shipment_id;
		} else {
			return false;
		}
	}

	/**
	 * @param int $attachment_id Attachment ID if availabe. Leave empty to create new attachment.
	 *
	 * @return bool|ShipmentAttachment
	 */
	public static function get_shipment_attachment( $attachment_id = 0, $attachment_type = 'packing_slip' ) {
		$attachment_id = self::get_document_attachment_id( $attachment_id );

		if ( is_numeric( $attachment_id ) && ! empty( $attachment_id ) ) {
			$attachment_type = self::get_document_attachment_type( $attachment_id );
		}

		$classname = apply_filters( 'shiptastic_shipment_attachment_classname', '\Vendidero\Shiptastic\ShipmentAttachment', $attachment_id, $attachment_type );

		if ( $classname && class_exists( $classname ) ) {
			try {
				$attachment = new $classname( $attachment_id );

				if ( ! is_a( $attachment, 'Vendidero\Shiptastic\Interfaces\Attachment' ) ) {
					throw new Exception( 'Invalid attachment type' );
				}

				$attachment->set_type( $attachment_type );

				return $attachment;
			} catch ( Exception $e ) {
				wc_caught_exception( $e, __FUNCTION__, array( $attachment_id ) );
				return false;
			}
		}

		return false;
	}

	/**
	 * Get the attachment id depending on what was passed.
	 *
	 * @since 1.0.0
	 * @param  mixed $attachment Attachment data to convert to an ID.
	 * @return int|bool false on failure
	 */
	public static function get_document_attachment_id( $attachment ) {
		if ( is_numeric( $attachment ) ) {
			return $attachment;
		} elseif ( $attachment instanceof ShipmentAttachment ) {
			return $attachment->get_id();
		} elseif ( ! empty( $attachment->attachment_id ) ) {
			return $attachment->attachment_id;
		} else {
			return false;
		}
	}

	public static function get_document_attachment_type( $attachment_id ) {
		global $wpdb;

		// Get from cache if available.
		$data = wp_cache_get( 'attachment-' . $attachment_id, 'shiptastic-shipment-attachments' );

		if ( false === $data ) {
			$data = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->stc_shipment_attachments} WHERE attachment_id = %d LIMIT 1;", $attachment_id ) );
			wp_cache_set( 'attachment-' . $attachment_id, $data, 'shiptastic-shipment-attachments' );
		}

		if ( ! $data ) {
			return false;
		}

		return $data->attachment_type;
	}
}
