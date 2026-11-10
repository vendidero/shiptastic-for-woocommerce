<?php
/**
 * Label Factory
 *
 * The label factory creates the right label objects.
 *
 * @version 1.0.0
 */
namespace Vendidero\Shiptastic\Labels;

use Vendidero\Shiptastic\Caches\Helper;
use Vendidero\Shiptastic\Interfaces\ShipmentLabel;
use WC_Data_Store;
use Exception;

defined( 'ABSPATH' ) || exit;

/**
 * Label factory class
 */
class Factory {

	/**
	 * Request-local map of (latest) labels per shipment and label type, e.g. primed for list views.
	 *
	 * @var array
	 */
	protected static $shipment_labels = array();

	/**
	 * Load labels for multiple shipments at once to prevent querying labels for each shipment separately.
	 *
	 * @param int[] $shipment_ids
	 */
	public static function prime_shipment_labels( $shipment_ids ) {
		$shipment_ids = array_values(
			array_filter(
				array_unique( array_map( 'absint', (array) $shipment_ids ) ),
				function ( $shipment_id ) {
					return $shipment_id > 0 && ! array_key_exists( $shipment_id, self::$shipment_labels );
				}
			)
		);

		if ( empty( $shipment_ids ) ) {
			return;
		}

		$labels = wc_stc_get_shipment_labels(
			array(
				'shipment_id' => $shipment_ids,
				'limit'       => -1,
				'order'       => 'DESC',
				'orderby'     => 'date_created',
			)
		);

		foreach ( $shipment_ids as $shipment_id ) {
			self::$shipment_labels[ $shipment_id ] = array();
		}

		foreach ( $labels as $label ) {
			if ( ! is_a( $label, '\Vendidero\Shiptastic\Interfaces\ShipmentLabel' ) ) {
				continue;
			}

			$shipment_id = absint( $label->get_shipment_id() );
			$label_type  = $label->get_type();

			if ( ! array_key_exists( $shipment_id, self::$shipment_labels ) ) {
				continue;
			}

			// Labels are sorted by date created DESC - keep the first (latest) label only.
			if ( ! array_key_exists( '_all', self::$shipment_labels[ $shipment_id ] ) ) {
				self::$shipment_labels[ $shipment_id ]['_all'] = $label;
			}

			if ( ! array_key_exists( $label_type, self::$shipment_labels[ $shipment_id ] ) ) {
				self::$shipment_labels[ $shipment_id ][ $label_type ] = $label;
			}
		}
	}

	/**
	 * Returns the primed (latest) label for a shipment and label type. Returns null in case
	 * labels have not been primed for the shipment.
	 *
	 * @param int $shipment_id
	 * @param string $label_type
	 *
	 * @return null|false|ShipmentLabel
	 */
	public static function get_primed_shipment_label( $shipment_id, $label_type = '' ) {
		$shipment_id = absint( $shipment_id );

		if ( ! array_key_exists( $shipment_id, self::$shipment_labels ) ) {
			return null;
		}

		$label_type = empty( $label_type ) ? '_all' : $label_type;

		return array_key_exists( $label_type, self::$shipment_labels[ $shipment_id ] ) ? self::$shipment_labels[ $shipment_id ][ $label_type ] : false;
	}

	/**
	 * Remove primed labels for a certain shipment, e.g. after a label has been created/deleted.
	 *
	 * @param int $shipment_id
	 */
	public static function clear_primed_shipment_labels( $shipment_id ) {
		unset( self::$shipment_labels[ absint( $shipment_id ) ] );
	}

	/**
	 * Get label.
	 *
	 * @param  mixed $label_id (default: false) Label id to get or empty if new.
	 * @return ShipmentLabel|bool
	 */
	public static function get_label( $label_id = false, $shipping_provider_name = '', $label_type = 'simple' ) {
		$label_id = self::get_label_id( $label_id );

		if ( $label_id ) {
			if ( $cache = Helper::get_cache_object( 'shipment-labels' ) ) {
				$label = $cache->get( $label_id );

				if ( ! is_null( $label ) ) {
					return $label;
				}
			}

			$label_data = WC_Data_Store::load( 'shipment-label' )->get_label_data( $label_id );

			if ( $label_data ) {
				$label_type             = $label_data->type;
				$shipping_provider_name = $label_data->shipping_provider;
			}
		}

		$shipping_provider_name = apply_filters( 'woocommerce_shiptastic_shipment_label_shipping_provider_name', $shipping_provider_name, $label_id, $label_type );

		if ( ! $shipping_provider = wc_stc_get_shipping_provider( $shipping_provider_name ) ) {
			return false;
		}

		$classname = '\Vendidero\Shiptastic\Labels\Label';

		if ( 'return' === $label_type ) {
			$classname = '\Vendidero\Shiptastic\Labels\ReturnLabel';
		}

		if ( is_a( $shipping_provider, '\Vendidero\Shiptastic\Interfaces\ShippingProviderAuto' ) ) {
			$classname = $shipping_provider->get_label_classname( $label_type );
		}

		/**
		 * Filter that allows adjusting the default label classname.
		 *
		 * @param string  $classname The classname to be used.
		 * @param integer $label_id The label id.
		 * @param string  $label_type The label type.
		 *
		 */
		$classname = apply_filters( 'woocommerce_shiptastic_shipment_label_class', $classname, $label_id, $label_type, $shipping_provider );

		if ( ! class_exists( $classname ) ) {
			return false;
		}

		try {
			$label = new $classname( $label_id );

			if ( $label && $label_id > 0 && ( $cache = Helper::get_cache_object( 'shipment-labels' ) ) ) {
				$cache->set( $label, $label_id );
			}

			return $label;
		} catch ( Exception $e ) {
			wc_caught_exception( $e, __FUNCTION__, array( $label_id, $shipping_provider_name, $label_type ) );
			return false;
		}
	}

	public static function get_label_id( $label ) {
		if ( is_numeric( $label ) ) {
			return $label;
		} elseif ( $label instanceof Label ) {
			return $label->get_id();
		} elseif ( ! empty( $label->label_id ) ) {
			return $label->label_id;
		} else {
			return false;
		}
	}
}
