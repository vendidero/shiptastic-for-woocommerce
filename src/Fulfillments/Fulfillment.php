<?php

namespace Vendidero\Shiptastic\Fulfillments;

use Automattic\WooCommerce\Internal\Fulfillments\FulfillmentUtils;
use Vendidero\Shiptastic\Shipment;
use Vendidero\Shiptastic\SimpleShipment;

class Fulfillment extends \Automattic\WooCommerce\Internal\Fulfillments\Fulfillment {

	/**
	 * @var \Vendidero\Shiptastic\ReturnShipment|\Vendidero\Shiptastic\Shipment|SimpleShipment
	 */
	protected $shipment = null;

	public function __construct( $data = '' ) {
		$shipment = wc_stc_get_shipment( $data );

		if ( ! $shipment ) {
			$shipment = new SimpleShipment( $data );
		}

		$this->shipment   = $shipment;
		$this->data_store = $shipment->get_data_store();

		$this->set_object_read( true );
	}

	public function sync( $args = array() ) {
		$this->shipment->sync( $args );
	}

	public function sync_items( $items = array() ) {
		$this->shipment->sync_items( array( 'items' => $items ) );
		$this->data['items'] = array();

		foreach ( $this->shipment->get_items() as $item ) {
			$this->data['items'][] = array(
				'item_id' => $item->get_order_item_id(),
				'qty'     => $item->get_quantity(),
			);
		}
	}

	public function get_id(): int {
		return $this->shipment->get_id();
	}

	/**
	 * @return Shipment
	 */
	public function get_shipment() {
		return $this->shipment;
	}

	public function set_id( $id ): void {}

	public function get_entity_id(): ?string {
		return $this->shipment->get_order_id();
	}

	public function set_entity_id( ?string $entity_id ): void {
		$this->shipment->set_order_id( $entity_id );
	}

	public function set_entity_type( ?string $entity_type ): void {}

	public function get_shipment_type(): string {
		return $this->shipment->get_type();
	}

	public function get_entity_type(): ?string {
		return \WC_Order::class;
	}

	public function set_date_deleted( ?string $date_deleted ): void {}

	public function get_date_deleted(): ?string {
		return '';
	}

	public function get_date_updated(): ?string {
		return $this->shipment->get_date_modified() ? $this->shipment->get_date_modified()->date_i18n( 'Y-m-d H:i:s' ) : '';
	}

	public function set_date_updated( ?string $date_updated ): void {
		if ( '' !== $date_updated ) {
			$this->shipment->set_date_modified( $date_updated );
		} else {
			$this->shipment->set_date_modified( null );
		}
	}

	public function get_date_fulfilled(): ?string {
		return $this->shipment->get_date_sent() ? $this->shipment->get_date_sent()->date_i18n( 'Y-m-d H:i:s' ) : '';
	}

	public function set_date_fulfilled( string $date_fulfilled ): void {
		if ( '' !== $date_fulfilled ) {
			$this->shipment->set_date_sent( $date_fulfilled );
		} else {
			$this->shipment->set_date_sent( null );
		}
	}

	public function get_items(): array {
		$the_items = array();

		foreach ( $this->shipment->get_items() as $item ) {
			$the_items[] = array(
				'qty'     => $item->get_quantity(),
				'item_id' => $item->get_order_item_id(),
			);
		}

		return $the_items;
	}

	public function set_items( array $items ): void {
		$items_to_sync = array();

		foreach ( array_values( $items ) as $item ) {
			$items_to_sync[ $item['item_id'] ] = $item['qty'];
		}

		if ( empty( $items_to_sync ) ) {
			foreach ( $this->shipment->get_items() as $item_key => $item ) {
				$this->shipment->remove_item( $item_key );
			}
		} else {
			$this->shipment->sync_items( array( 'items' => $items_to_sync ) );
		}
	}

	public function is_locked(): bool {
		return false;
	}

	public function get_lock_message(): string {
		return '';
	}

	public function get_status(): ?string {
		return $this->shipment->get_status();
	}

	public function get_is_fulfilled(): bool {
		return $this->shipment->is_shipped();
	}

	public function set_is_fulfilled( bool $is_fulfilled ): void {
		if ( $is_fulfilled ) {
			$this->shipment->set_status( 'shipped' );
		} else {
			$this->shipment->set_status( 'draft' );
		}
	}

	public function set_status( ?string $status ): void {
		$statuses = wc_stc_get_shipment_statuses();

		if ( ! isset( $statuses[ $status ] ) ) {
			$status = $this->get_is_fulfilled() ? 'shipped' : 'draft';
		}

		$this->shipment->set_status( $status );
	}

	public function set_locked( bool $locked, string $message = '' ): void {}

	public function get_order(): ?\WC_Order {
		return $this->shipment->get_order();
	}

	public function get_tracking_number( $context = 'view' ): ?string {
		return $this->shipment->get_tracking_id( $context );
	}

	public function set_tracking_number( string $tracking_number ): void {
		$this->shipment->set_tracking_id( $tracking_number );
	}

	public function get_tracking_url( $context = 'view' ): ?string {
		return $this->shipment->get_tracking_url( $context );
	}

	public function set_tracking_url( string $tracking_url ): void {
		$this->shipment->set_tracking_url( $tracking_url );
	}

	public function get_shipment_provider( $context = 'view' ): ?string {
		$shipment_provider = str_replace( '_', '-', $this->shipment->get_shipping_provider( $context ) );

		return $shipment_provider;
	}

	public function set_shipment_provider( string $shipment_provider ): void {
		$shipment_provider = str_replace( '-', '_', $shipment_provider );

		$this->shipment->set_shipping_provider( $shipment_provider );
	}

	public function get_provider_name( $context = 'view' ): ?string {
		return $this->shipment->get_shipping_provider_title( $context );
	}

	public function set_provider_name( string $provider_name ): void {
		$this->shipment->set_shipping_provider_title( $provider_name );
	}

	public function get_shipping_option( $context = 'view' ): ?string {
		$shipping_option = $this->shipment->get_meta( '_shipping_option' );

		if ( 'view' === $context && empty( $shipping_option ) ) {
			$shipping_option = empty( $this->get_tracking_number() ) ? 'no-info' : 'manual-entry';
		}

		return $shipping_option;
	}

	public function set_shipping_option( string $shipping_option ): void {
		$this->shipment->update_meta_data( '_shipping_option', $shipping_option );
	}

	/**
	 * Read meta data if null.
	 *
	 * @since 3.0.0
	 */
	protected function maybe_read_meta_data() {
		$this->meta_data = array();
	}

	protected function is_internal_meta_key( $key ) {
		if ( in_array( $key, array( '_tracking_number', '_tracking_url', '_shipment_provider', '_provider_name', '_items' ), true ) ) {
			return true;
		}

		return parent::is_internal_meta_key( $key );
	}

	public function save_meta_data() {
		$this->shipment->save_meta_data();
	}

	public function set_meta_data( $data ) {
		if ( ! empty( $data ) && is_array( $data ) ) {
			$shipment_meta = array();
			$this->maybe_read_meta_data();
			foreach ( $data as $meta ) {
				$meta = (array) $meta;
				if ( isset( $meta['key'], $meta['value'], $meta['id'] ) ) {
					if ( $this->is_internal_meta_key( $meta['key'] ) ) {
						$function = 'set_' . ltrim( $meta['key'], '_' );

						if ( is_callable( array( $this, $function ) ) ) {
							$this->{$function}( $meta['value'] );
						}
					} else {
						$shipment_meta[] = $meta;
					}
				}
			}

			if ( ! empty( $shipment_meta ) ) {
				$this->shipment->set_meta_data( $shipment_meta );
			}
		}
	}

	/**
	 * Delete meta data.
	 *
	 * @since 2.6.0
	 * @param string $key Meta key.
	 */
	public function delete_meta_data( $key ) {
		if ( $this->is_internal_meta_key( $key ) ) {
			$function = 'set_' . ltrim( $key, '_' );

			if ( 'set_items' === $function ) {
				$this->set_items( array() );
			} elseif ( is_callable( array( $this, $function ) ) ) {
				$this->{$function}( null );
			}
		} else {
			$this->shipment->delete_meta_data( $key );
		}
	}

	public function get_meta( $key = '', $single = true, $context = 'view' ) {
		if ( $this->is_internal_meta_key( $key ) ) {
			$function = 'get_' . ltrim( $key, '_' );

			if ( is_callable( array( $this, $function ) ) ) {
				return $this->{$function}();
			}
		}

		return $this->shipment->get_meta( $key, $single, $context );
	}

	public function get_meta_data() {
		return $this->shipment->get_meta_data();
	}

	public function update_meta_data( $key, $value, $meta_id = 0 ) {
		$this->shipment->update_meta_data( $key, $value, $meta_id );
	}

	public function add_meta_data( $key, $value, $unique = false ) {
		$this->shipment->add_meta_data( $key, $value, $unique );
	}

	public function delete_meta_data_value( $key, $value ) {
		$this->shipment->delete_meta_data_value( $key, $value );
	}

	public function delete_meta_data_by_mid( $mid ) {
		$this->shipment->delete_meta_data_by_mid( $mid );
	}

	public function get_meta_cache_key() {
		return $this->shipment->get_meta_cache_key();
	}

	public static function generate_meta_cache_key( $id, $cache_group ) {
		return SimpleShipment::generate_meta_cache_key( $id, $cache_group );
	}

	public static function prime_raw_meta_data_cache( $raw_meta_data_collection, $cache_group ) {
		SimpleShipment::prime_raw_meta_data_cache( $raw_meta_data_collection, $cache_group );
	}

	public function init_meta_data( array $filtered_meta_data = array() ) {
		$this->shipment->init_meta_data( $filtered_meta_data );
	}

	public function meta_exists( $key = '' ) {
		return $this->shipment->meta_exists( $key );
	}

	public function get_raw_data() {
		return array(
			'id'             => $this->get_id(),
			'status'         => $this->get_status(),
			'date_deleted'   => $this->get_date_deleted(),
			'date_updated'   => $this->get_date_updated(),
			'date_fulfilled' => $this->get_date_fulfilled(),
			'is_fulfilled'   => $this->get_is_fulfilled(),
			'entity_type'    => $this->get_entity_type(),
			'entity_id'      => $this->get_entity_id(),
			'meta_data'      => $this->get_raw_meta_data(),
		);
	}

	/**
	 * Returns the meta data as array for this object.
	 *
	 * @return array
	 */
	public function get_raw_meta_data() {
		$default_meta_data = array(
			'_tracking_number'   => array(
				'key'   => '_tracking_number',
				'value' => $this->get_tracking_number(),
				'id'    => 0,
			),
			'_tracking_url'      => array(
				'key'   => '_tracking_url',
				'value' => $this->get_tracking_url(),
				'id'    => 0,
			),
			'_shipment_provider' => array(
				'key'   => '_shipment_provider',
				'value' => $this->get_shipment_provider(),
				'id'    => 0,
			),
			'_date_fulfilled'    => array(
				'key'   => '_date_fulfilled',
				'value' => $this->get_date_fulfilled(),
				'id'    => 0,
			),
			'_provider_name'     => array(
				'key'   => '_provider_name',
				'value' => $this->get_provider_name(),
				'id'    => 0,
			),
			'_shipping_option'   => array(
				'key'   => '_shipping_option',
				'value' => $this->get_shipping_option(),
				'id'    => 0,
			),
			'_items'             => array(
				'key'   => '_items',
				'value' => $this->get_items(),
				'id'    => 0,
			),
		);

		return array_merge( parent::get_raw_meta_data(), array_values( $default_meta_data ) );
	}

	public function save() {
		return $this->shipment->save();
	}

	public function delete( $force_delete = false ) {
		$this->shipment->delete( $force_delete );
	}
}
