<?php

namespace Vendidero\Shiptastic\BulkFulfillments;

use Vendidero\Shiptastic\Package;

defined( 'ABSPATH' ) || exit;

/**
 * A single fulfillment queue entry: one package (location + shipping method) of an order.
 * Read-only value object, changes are made via `Queue` and `QueueSync`.
 */
class QueueItem {

	protected $data = array();

	/**
	 * @param object|array $row Database row.
	 */
	public function __construct( $row ) {
		$this->data = (array) $row;
	}

	protected function get( $key, $default_value = '' ) {
		return array_key_exists( 'queue_' . $key, $this->data ) ? $this->data[ 'queue_' . $key ] : $default_value;
	}

	protected function get_date( $key ) {
		$date = $this->get( $key, null );

		if ( ! $date || ! Package::is_valid_mysql_date( $date ) ) {
			return null;
		}

		return new \WC_DateTime( $date, new \DateTimeZone( 'UTC' ) );
	}

	public function get_id() {
		return absint( $this->get( 'id', 0 ) );
	}

	public function get_order_id() {
		return absint( $this->get( 'order_id', 0 ) );
	}

	/**
	 * @return \WC_Order|false
	 */
	public function get_order() {
		return wc_get_order( $this->get_order_id() );
	}

	/**
	 * @return \Vendidero\Shiptastic\Order|false
	 */
	public function get_shipment_order() {
		return wc_stc_get_shipment_order( $this->get_order_id() );
	}

	public function get_package_key() {
		return (string) $this->get( 'package_key' );
	}

	public function get_location() {
		return (string) $this->get( 'location' );
	}

	public function get_shipping_method() {
		return (string) $this->get( 'shipping_method' );
	}

	public function get_shipping_provider() {
		return (string) $this->get( 'shipping_provider' );
	}

	public function get_country() {
		return (string) $this->get( 'country' );
	}

	public function get_item_count() {
		return absint( $this->get( 'item_count', 0 ) );
	}

	public function get_weight() {
		return (float) $this->get( 'weight', 0 );
	}

	public function has_backorders() {
		return (bool) absint( $this->get( 'has_backorders', 0 ) );
	}

	/**
	 * @return string pending|locked|error|done|removed
	 */
	public function get_status() {
		return (string) $this->get( 'status', 'pending' );
	}

	/**
	 * Whether the item lost its eligibility while being locked (e.g. order cancelled during fulfillment).
	 */
	public function is_stale() {
		return (bool) absint( $this->get( 'is_stale', 0 ) );
	}

	public function get_workflow_id() {
		return absint( $this->get( 'workflow_id', 0 ) );
	}

	public function get_locked_by() {
		return absint( $this->get( 'locked_by', 0 ) );
	}

	/**
	 * @return string interactive|background|scheduled
	 */
	public function get_lock_source() {
		return (string) $this->get( 'lock_source' );
	}

	public function get_lock_token() {
		return (string) $this->get( 'lock_token' );
	}

	/**
	 * @return \WC_DateTime|null
	 */
	public function get_lock_expires() {
		return $this->get_date( 'lock_expires_gmt' );
	}

	/**
	 * Whether the item is currently locked (lock not expired).
	 */
	public function is_locked() {
		$expires = $this->get_lock_expires();

		return 'locked' === $this->get_status() && $expires && $expires->getTimestamp() > time();
	}

	public function get_version() {
		return absint( $this->get( 'version', 0 ) );
	}

	public function get_last_error() {
		return (string) $this->get( 'last_error' );
	}

	/**
	 * @return \WC_DateTime|null
	 */
	public function get_date_queued() {
		return $this->get_date( 'date_queued_gmt' );
	}

	/**
	 * @return \WC_DateTime|null
	 */
	public function get_date_modified() {
		return $this->get_date( 'date_modified_gmt' );
	}

	/**
	 * @return array The raw database row (prefixed column names).
	 */
	public function get_data_raw() {
		return $this->data;
	}

	public function get_data() {
		$data = array();

		foreach ( $this->data as $key => $value ) {
			$data[ substr( $key, 6 ) ] = $value;
		}

		return $data;
	}
}
