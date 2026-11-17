<?php
/**
 * Regular shipment
 *
 * @package Vendidero/Shiptastic
 * @version 1.0.0
 */
namespace Vendidero\Shiptastic;

use WC_Data;
use WC_Data_Store;
use Exception;
use WC_Order;
use WC_DateTime;

defined( 'ABSPATH' ) || exit;

/**
 * Shipment Class.
 */
class SimpleShipment extends Shipment {

	/**
	 * The corresponding order object.
	 *
	 * @var null|WC_Order
	 */
	private $order = null;

	/**
	 * The corresponding order object.
	 *
	 * @var null|Order
	 */
	private $order_shipment = null;

	protected $extra_data = array(
		'order_id' => 0,
	);

	/**
	 * Returns the shipment type.
	 *
	 * @return string
	 */
	public function get_type() {
		return 'simple';
	}

	/**
	 * Returns the order id belonging to the shipment.
	 *
	 * @param  string $context What the value is for. Valid values are 'view' and 'edit'.
	 * @return integer
	 */
	public function get_order_id( $context = 'view' ) {
		return $this->get_prop( 'order_id', $context );
	}

	/**
	 * Set shipment order id.
	 *
	 * @param string $order_id The order id.
	 */
	public function set_order_id( $order_id ) {
		// Reset order object
		$this->order = null;

		$this->set_prop( 'order_id', absint( $order_id ) );
	}

	/**
	 * Set shipment order.
	 *
	 * @param Order $order_shipment The order shipment.
	 */
	public function set_order_shipment( &$order_shipment ) {
		$this->order_shipment = $order_shipment;
	}

	/**
	 * Tries to fetch the order for the current shipment.
	 *
	 * @return bool|WC_Order|null
	 */
	public function get_order() {
		if ( is_null( $this->order ) ) {
			$this->order = ( $this->get_order_id() > 0 ? wc_get_order( $this->get_order_id() ) : false );
		}

		return $this->order;
	}

	/**
	 * Returns the order shipment instance. Loads from DB if not yet exists.
	 *
	 * @return bool|Order
	 */
	public function get_order_shipment() {
		if ( is_null( $this->order_shipment ) ) {
			$order                = $this->get_order();
			$this->order_shipment = ( $order ? wc_stc_get_shipment_order( $order ) : false );
		}

		return $this->order_shipment;
	}

	/**
	 * Sync the shipment with it's corresponding order.
	 *
	 * @param array $args
	 *
	 * @return bool
	 */
	public function sync( $args = array() ) {
		try {
			if ( ! $order_shipment = $this->get_order_shipment() ) {
				throw new Exception( _x( 'Invalid shipment order', 'shipments', 'shiptastic-for-woocommerce' ) );
			}

			$order        = $order_shipment->get_order();
			$address_data = array_merge(
				( $order->has_shipping_address() ? $order->get_address( 'shipping' ) : $order->get_address( 'billing' ) ),
				array(
					'email' => $order->get_billing_email(),
					'phone' => $order->get_billing_phone(),
				)
			);

			// Prefer shipping phone in case exists
			if ( is_callable( array( $order, 'get_shipping_phone' ) ) && $order->get_shipping_phone() ) {
				$address_data['phone'] = $order->get_shipping_phone();
			}

			$billing_address = $order_shipment->has_differing_shipping_address() ? array_merge(
				$order->get_address( 'billing' ),
				array(
					'email' => $order->get_billing_email(),
					'phone' => $order->get_billing_phone(),
				)
			) : array();

			/**
			 * Fix to make sure that we are not syncing formatted customer titles (e.g. Herr)
			 * which prevents shipment addresses from being translated.
			 */
			if ( isset( $address_data['title'] ) && ! empty( $address_data['title'] ) ) {
				if ( $title = $order->get_meta( '_shipping_title', true ) ) {
					$address_data['title'] = $title;
				}
			}

			/**
			 * Force the country to have a max length of 2.
			 * https://github.com/woocommerce/woocommerce/issues/27521
			 */
			$country      = substr( strtoupper( ( $order->has_shipping_address() ? $order->get_shipping_country() : $order->get_billing_country() ) ), 0, 2 );
			$packaging_id = $this->get_packaging_id( 'edit' );

			$dimensions = array(
				'width'  => $this->get_width( 'edit' ),
				'length' => $this->get_length( 'edit' ),
				'height' => $this->get_height( 'edit' ),
			);

			$args = wp_parse_args(
				$args,
				array(
					'order_id'                        => $order->get_id(),
					'shipping_method'                 => $this->get_shipping_method( 'edit' ) ? $this->get_shipping_method( 'edit' ) : $order_shipment->get_shipping_method_id(),
					'packaging_id'                    => $this->get_packaging_id( 'edit' ),
					'address'                         => $address_data,
					'billing_address'                 => $billing_address,
					'country'                         => $country,
					'weight'                          => $this->get_weight( 'edit' ),
					'packaging_weight'                => $this->get_packaging_weight( 'edit' ),
					'pickup_location_code'            => $order_shipment->has_pickup_location() ? $order_shipment->get_pickup_location_code() : '',
					'pickup_location_customer_number' => $order_shipment->has_pickup_location() ? $order_shipment->get_pickup_location_customer_number() : '',
					'length'                          => $dimensions['length'],
					'width'                           => $dimensions['width'],
					'height'                          => $dimensions['height'],
					'additional_total'                => $order_shipment->calculate_shipment_additional_total( $this ),
				)
			);

			/**
			 * Make sure that manually adjusted providers are not overridden by syncing.
			 */
			$default_provider_instance = wc_stc_get_order_shipping_provider( $order, $args['shipping_method'] );
			$default_provider          = $default_provider_instance ? $default_provider_instance->get_name() : '';
			$provider                  = $this->get_shipping_provider( 'edit' );

			$args = wp_parse_args(
				$args,
				array(
					'shipping_provider' => ( ! empty( $provider ) || $this->get_shipping_provider_title() ) ? $provider : $default_provider,
				)
			);

			/**
			 * Filter to allow adjusting the shipment props synced from the corresponding order.
			 *
			 * @param mixed          $args The properties in key => value pairs.
			 * @param SimpleShipment $shipment The shipment object.
			 * @param Order          $order_shipment The shipment order object.
			 *
			 * @package Vendidero/Shiptastic
			 */
			$args = apply_filters( 'woocommerce_shiptastic_shipment_sync_props', $args, $this, $order_shipment );

			$this->set_props( $args );

			/**
			 * Action that fires after a shipment has been synced. Syncing is used to
			 * keep the shipment in sync with the corresponding order.
			 *
			 * @param SimpleShipment $shipment The shipment object.
			 * @param Order          $order_shipment The shipment order object.
			 * @param array          $args Array containing properties in key => value pairs to be updated.
			 *
			 * @package Vendidero/Shiptastic
			 */
			do_action( 'woocommerce_shiptastic_shipment_synced', $this, $order_shipment, $args );
		} catch ( Exception $e ) {
			return false;
		}

		return true;
	}

	/**
	 * Sync items with the corresponding order items.
	 * Limits quantities and removes non-existing items.
	 *
	 * @param array $args
	 *
	 * @return bool
	 */
	/**
	 * Adds an order item to the shipment, e.g. manually. In case the order item consists of inner boxes,
	 * all inner boxes still available are added.
	 *
	 * @param \WC_Order_Item $order_item
	 * @param int|false      $quantity Units to add, false to add all units left.
	 *
	 * @return ShipmentItem[] Items added or updated.
	 */
	public function add_order_item( $order_item, $quantity = false ) {
		$order_shipment = $this->get_order_shipment();
		$added          = array();

		if ( ! $order_shipment ) {
			return $added;
		}

		$available = $order_shipment->get_available_items_for_shipment(
			array(
				'shipment_id'              => $this->get_id(),
				'exclude_current_shipment' => true,
			)
		);

		if ( ! isset( $available[ $order_item->get_id() ] ) ) {
			return $added;
		}

		$item_data = $available[ $order_item->get_id() ];

		if ( ! empty( $item_data['inner_boxes'] ) ) {
			foreach ( $item_data['inner_boxes'] as $box_key => $box_quantity_left ) {
				$box_key  = (string) $box_key;
				$existing = $this->get_item_by_order_item_id( $order_item->get_id(), $box_key );
				$current  = $existing ? absint( $existing->get_quantity() ) : 0;
				$new_qty  = false === $quantity ? $box_quantity_left : min( $box_quantity_left, $current + absint( $quantity ) );

				if ( $new_qty <= $current ) {
					continue;
				}

				if ( $existing ) {
					$existing->sync( array( 'quantity' => $new_qty ) );
					$added[] = $existing;
				} else {
					$item = wc_stc_create_shipment_item(
						$this,
						$order_item,
						array(
							'quantity'  => $new_qty,
							'inner_box' => $box_key,
						)
					);

					if ( ! is_wp_error( $item ) ) {
						$this->add_item( $item );
						$added[] = $item;
					}
				}
			}
		} elseif ( ! $this->get_item_by_order_item_id( $order_item->get_id() ) ) {
			$new_qty = false === $quantity ? $item_data['max_quantity'] : min( $item_data['max_quantity'], absint( $quantity ) );
			$item    = wc_stc_create_shipment_item( $this, $order_item, array( 'quantity' => $new_qty ) );

			if ( ! is_wp_error( $item ) ) {
				$this->add_item( $item );
				$added[] = $item;
			}
		}

		return $added;
	}

	/**
	 * Syncs the inner box items of a certain order item. The requested quantity (args.items) may either be
	 * a quantity of units (applied to each inner box) or an array of inner box key => quantity.
	 *
	 * @param \WC_Order_Item $order_item
	 * @param array          $item_data
	 * @param array          $args
	 */
	protected function sync_inner_box_items( $order_item, $item_data, $args ) {
		$order_shipment = $this->get_order_shipment();
		$item_id        = $order_item->get_id();
		$requested      = ! empty( $args['items'] ) && isset( $args['items'][ $item_id ] ) ? $args['items'][ $item_id ] : null;

		if ( ! empty( $args['items'] ) && is_null( $requested ) ) {
			return;
		}

		$backordered = empty( $args['items'] ) && $order_shipment ? $order_shipment->get_item_backordered_quantity( $order_item ) : 0;
		$synced      = false;

		foreach ( $item_data['inner_boxes'] as $box_key => $quantity ) {
			$box_key       = (string) $box_key;
			$existing_item = $this->get_item_by_order_item_id( $item_id, $box_key );

			if ( $backordered > 0 ) {
				$quantity = max( $quantity - $backordered, $existing_item ? absint( $existing_item->get_quantity() ) : 0 );
			}

			if ( ! is_null( $requested ) ) {
				if ( is_array( $requested ) ) {
					$quantity = isset( $requested[ $box_key ] ) ? min( $quantity, absint( $requested[ $box_key ] ) ) : 0;
				} else {
					$quantity = min( $quantity, absint( $requested ) );
				}
			}

			if ( $quantity <= 0 ) {
				if ( $existing_item && ! is_null( $requested ) ) {
					$this->remove_item( $existing_item->get_id() );
				}

				continue;
			}

			if ( $existing_item ) {
				$existing_item->sync( array( 'quantity' => $quantity ) );
			} else {
				$this->add_item(
					wc_stc_create_shipment_item(
						$this,
						$order_item,
						array(
							'quantity'  => $quantity,
							'inner_box' => $box_key,
						)
					)
				);
			}

			$synced = true;
		}

		// Replace (legacy) items containing complete units with inner box items.
		if ( $synced && ( $unit_item = $this->get_item_by_order_item_id( $item_id, '' ) ) ) {
			$this->remove_item( $unit_item->get_id() );
		}
	}

	public function sync_items( $args = array() ) {
		try {
			if ( ! $order_shipment = $this->get_order_shipment() ) {
				throw new Exception( _x( 'Invalid shipment order', 'shipments', 'shiptastic-for-woocommerce' ) );
			}

			$order = $order_shipment->get_order();

			$args = wp_parse_args(
				$args,
				array(
					'items' => array(),
				)
			);

			$available_items = $order_shipment->get_available_items_for_shipment(
				array(
					'shipment_id'              => $this->get_id(),
					'exclude_current_shipment' => true,
				)
			);

			foreach ( $available_items as $item_id => $item_data ) {
				if ( ( $order_item = $order->get_item( $item_id ) ) && ! empty( $item_data['inner_boxes'] ) ) {
					$this->sync_inner_box_items( $order_item, $item_data, $args );
					continue;
				}

				if ( $order_item ) {
					$quantity = $item_data['max_quantity'];

					/**
					 * Backordered units are not added automatically (e.g. syncing all items).
					 * Units already part of this shipment (e.g. added manually) are kept.
					 */
					if ( empty( $args['items'] ) && ( $backordered = $order_shipment->get_item_backordered_quantity( $order_item ) ) > 0 ) {
						$existing_item = $this->get_item_by_order_item_id( $item_id );
						$quantity      = max( $quantity - $backordered, $existing_item ? absint( $existing_item->get_quantity() ) : 0 );

						if ( $quantity <= 0 ) {
							continue;
						}
					}

					if ( ! empty( $args['items'] ) ) {
						if ( isset( $args['items'][ $item_id ] ) ) {
							$new_quantity = absint( $args['items'][ $item_id ] );

							if ( $new_quantity < $quantity ) {
								$quantity = $new_quantity;
							}
						} else {
							continue;
						}
					}

					if ( ! $shipment_item = $this->get_item_by_order_item_id( $item_id ) ) {
						$shipment_item = wc_stc_create_shipment_item( $this, $order_item, array( 'quantity' => $quantity ) );

						$this->add_item( $shipment_item );
					} else {
						$shipment_item->sync( array( 'quantity' => $quantity ) );
					}
				}
			}

			foreach ( $this->get_items() as $item ) {
				$item_is_missing_from_sync = ! empty( $args['items'] ) && ! isset( $args['items'][ $item->get_order_item_id() ] );
				$order_item_is_missing     = ! $order->get_item( $item->get_order_item_id() );

				if ( ! $item_is_missing_from_sync && ! empty( $args['items'] ) && $item->has_inner_box() && is_array( $args['items'][ $item->get_order_item_id() ] ) ) {
					$item_is_missing_from_sync = ! isset( $args['items'][ $item->get_order_item_id() ][ $item->get_inner_box() ] );
				}

				// Remove non-existent items
				if ( $order_item_is_missing || $item_is_missing_from_sync ) {
					$this->remove_item( $item->get_id() );
				}
			}

			// Sync packaging
			$this->sync_packaging();

			/**
			 * Action that fires after items of a shipment have been synced.
			 *
			 * @param SimpleShipment $shipment The shipment object.
			 * @param Order          $order_shipment The shipment order object.
			 * @param array          $args Array containing additional data e.g. items.
			 *
			 * @package Vendidero/Shiptastic
			 */
			do_action( 'woocommerce_shiptastic_shipment_items_synced', $this, $order_shipment, $args );

		} catch ( Exception $e ) {
			return false;
		}

		return true;
	}

	/**
	 * Returns available shipment methods by checking the corresponding order.
	 *
	 * @return string[]
	 */
	public function get_available_shipping_methods() {
		$methods = array();

		if ( $order = $this->get_order() ) {
			$items = $order->get_shipping_methods();

			foreach ( $items as $item ) {
				$methods[ $item->get_method_id() . ':' . $item->get_instance_id() ] = $item->get_name();
			}
		}

		return $methods;
	}

	/**
	 * Returns the number of items available for shipment.
	 *
	 * @return int|mixed|void
	 */
	public function get_shippable_item_count() {
		if ( $order_shipment = $this->get_order_shipment() ) {
			return $order_shipment->get_shippable_item_count();
		}

		return 0;
	}

	/**
	 * Returns whether the Shipment needs additional items or not.
	 *
	 * @param bool|integer[] $available_items
	 *
	 * @return bool
	 */
	public function needs_items( $available_items = false ) {

		if ( ! $available_items && ( $order = wc_stc_get_shipment_order( $this->get_order() ) ) ) {
			$available_items = array_keys( $order->get_available_items_for_shipment() );
		}

		return ( $this->is_editable() && ! $this->contains_order_item( $available_items ) );
	}

	/**
	 * Returns the edit shipment URL.
	 *
	 * @return mixed|string|void
	 */
	public function get_edit_shipment_url() {
		/**
		 * Filter to adjust the edit Shipment admin URL.
		 *
		 * The dynamic portion of this hook, `$this->get_hook_prefix()` is used to construct a
		 * unique hook for a shipment type.
		 *
		 * Example hook name: woocommerce_shiptastic_shipment_get_edit_url
		 *
		 * @param string   $url  The URL.
		 * @param Shipment $this The shipment object.
		 *
		 * @package Vendidero/Shiptastic
		 */
		return apply_filters( "{$this->get_hook_prefix()}edit_url", get_admin_url( null, 'post.php?post=' . $this->get_order_id() . '&action=edit&shipment_id=' . $this->get_id() ), $this );
	}
}
