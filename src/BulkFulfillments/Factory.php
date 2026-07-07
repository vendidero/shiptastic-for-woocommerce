<?php
/**
 * BulkFulfillment Factory
 *
 * The factory creates the bulk fulfillment objects.
 *
 * @version 1.0.0
 */
namespace Vendidero\Shiptastic\BulkFulfillments;

use Vendidero\Shiptastic\Caches\Helper;
use Exception;

defined( 'ABSPATH' ) || exit;

/**
 * Label factory class
 */
class Factory {

	/**
	 * Get label.
	 *
	 * @param  mixed $bulk_fulfillment (default: false) Fulfillment id to get or empty if new.
	 * @return BulkFulfillment|bool
	 */
	public static function get_bulk_fulfillment( $fulfillment_id = false ) {
		$fulfillment_id = self::get_fulfillment_id( $fulfillment_id );

		if ( $fulfillment_id ) {
			if ( $cache = Helper::get_cache_object( 'bulk-fulfillment' ) ) {
				$fulfillment = $cache->get( $fulfillment_id );

				if ( ! is_null( $fulfillment ) ) {
					return $fulfillment;
				}
			}
		}

		$classname = '\Vendidero\Shiptastic\BulkFulfillments\BulkFulfillment';

		/**
		 * Filter that allows adjusting the default bulk fulfillment classname.
		 *
		 * @param string  $classname The classname to be used.
		 * @param integer $fulfillment_id The fulfillment id.
		 *
		 */
		$classname = apply_filters( 'woocommerce_shiptastic_bulk_fulfillment_class', $classname, $fulfillment_id );

		if ( ! class_exists( $classname ) ) {
			return false;
		}

		try {
			$fulfillment = new $classname( $fulfillment_id );

			if ( $fulfillment_id > 0 && empty( $fulfillment->get_id() ) ) {
				throw new Exception( 'Fulfillment does not exist' );
			}

			if ( $fulfillment && $fulfillment->get_id() > 0 && ( $cache = Helper::get_cache_object( 'bulk-fulfillments' ) ) ) {
				$cache->set( $fulfillment, $fulfillment->get_id() );
			}

			return $fulfillment;
		} catch ( Exception $e ) {
			wc_caught_exception( $e, __FUNCTION__, array( $fulfillment_id ) );
			return false;
		}
	}

	public static function get_fulfillment_id( $fulfillment ) {
		if ( is_numeric( $fulfillment ) ) {
			return $fulfillment;
		} elseif ( $fulfillment instanceof BulkFulfillment ) {
			return $fulfillment->get_id();
		} elseif ( ! empty( $fulfillment->bulk_fulfillment_id ) ) {
			return $fulfillment->bulk_fulfillment_id;
		} else {
			return false;
		}
	}
}
