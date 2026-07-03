<?php

namespace Vendidero\Shiptastic\BulkFulfillments;

use WC_Data;
use WC_Data_Store;

defined( 'ABSPATH' ) || exit;

class BulkFulfillmentOrder extends WC_Data {
	/**
	 * This is the name of this object type.
	 *
	 * @var string
	 */
	protected $object_type = 'bulk_fulfillment_order';

	/**
	 * Contains a reference to the data store for this class.
	 *
	 * @var object
	 */
	protected $data_store_name = 'bulk-fulfillment-order';

	/**
	 * Stores meta in cache for future reads.
	 * A group must be set to to enable caching.
	 *
	 * @var string
	 */
	protected $cache_group = 'bulk_fulfillment_order';

	protected $orders = null;

	/**
	 * Stores fulfillment data.
	 *
	 * @var array
	 */
	protected $data = array(
		'order_id'       => 0,
		'fulfillment_id' => 0,
		'date_locked'    => null,
		'locked_by'      => 0,
		'status'         => '',
	);

	/**
	 * @param int|object|BulkFulfillmentOrder $fulfillment_order Fulfillment order to read.
	 */
	public function __construct( $data = 0 ) {
		parent::__construct( $data );

		$db_data = null;

		if ( $data instanceof BulkFulfillmentOrder ) {
			$this->set_id( absint( $data->get_id() ) );
		} elseif ( is_numeric( $data ) ) {
			$this->set_id( $data );
		} elseif ( ! empty( $data->fulfillment_order_id ) ) {
			$db_data = $data;
			$this->set_id( absint( $data->fulfillment_order_id ) );
		}

		$this->data_store = \WC_Data_Store::load( $this->data_store_name );

		// If we have an ID, load the user from the DB.
		if ( $this->get_id() ) {
			try {
				$this->data_store->read( $this, $db_data );
			} catch ( \Exception $e ) {
				$this->set_id( 0 );
				$this->set_object_read( true );
			}
		} else {
			$this->set_object_read( true );
		}
	}

	/**
	 * This method overwrites the base class's clone method to make it a no-op. In base class WC_Data, we are unsetting the meta_id to clone.
	 */
	public function __clone() {}

	public function get_date_locked( $context = 'view' ) {
		return $this->get_prop( 'date_locked', $context );
	}

	public function set_date_locked( $date ) {
		$this->set_date_prop( 'date_locked', $date );
	}

	public function get_status( $context = 'view' ) {
		return $this->get_prop( 'status', $context );
	}

	public function set_status( $status ) {
		$this->set_prop( 'status', $status );
	}

	public function get_locked_by( $context = 'view' ) {
		return $this->get_prop( 'locked_by', $context );
	}

	public function set_locked_by( $user_id ) {
		$this->set_prop( 'locked_by', absint( $user_id ) );
	}

	public function get_order_id( $context = 'view' ) {
		return $this->get_prop( 'order_id', $context );
	}

	public function set_order_id( $order_id ) {
		$this->set_prop( 'order_id', absint( $order_id ) );
	}

	public function get_fulfillment_id( $context = 'view' ) {
		return $this->get_prop( 'fulfillment_id', $context );
	}

	public function set_fulfillment_id( $fulfillment_id ) {
		$this->set_prop( 'fulfillment_id', absint( $fulfillment_id ) );
	}

	/**
	 * Normalize a date input to a UTC 'Y-m-d H:i:s' string.
	 *
	 * Bare MySQL-format strings are interpreted as site-local time (matching
	 * the convention of current_time('mysql')). Strings that include an
	 * explicit timezone designator (Z, numeric offset, or named zone) are
	 * respected as-is.
	 *
	 * @param string|null $date Date input.
	 * @return string|null UTC datetime string, or null for empty/invalid input.
	 */
	private function normalize_date_to_utc( ?string $date ): ?string {
		$date = null === $date ? null : trim( $date );
		if ( null === $date || '' === $date ) {
			return null;
		}
		try {
			// The second DateTimeZone is used only when the string has no explicit zone.
			$datetime = new \DateTime( $date, wp_timezone() );
			// DateTime silently normalizes invalid calendar dates (e.g. Feb 30 -> Mar 2);
			// reject those so callers don't persist a different date than the user supplied.
			$parse_errors = \DateTime::getLastErrors();
			if ( false !== $parse_errors && ( $parse_errors['warning_count'] > 0 || $parse_errors['error_count'] > 0 ) ) {
				return null;
			}
			$datetime->setTimezone( new \DateTimeZone( 'UTC' ) );
			return $datetime->format( 'Y-m-d H:i:s' );
		} catch ( \Exception $e ) {
			return null;
		}
	}
}
