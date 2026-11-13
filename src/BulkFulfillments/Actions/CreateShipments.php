<?php

namespace Vendidero\Shiptastic\BulkFulfillments\Actions;

use Vendidero\Shiptastic\BulkFulfillments\Action;
use Vendidero\Shiptastic\BulkFulfillments\StepResult;
use Vendidero\Shiptastic\Package;

defined( 'ABSPATH' ) || exit;

/**
 * Creates the shipments of a queue item's package: automatically by packaging rules (background) or based on a
 * layout submitted by the user (interactive). Backordered units are never added automatically.
 */
class CreateShipments extends Action {

	public static function get_name() {
		return 'create_shipments';
	}

	public static function get_title() {
		return _x( 'Create shipments', 'shipments', 'shiptastic-for-woocommerce' );
	}

	public static function get_description() {
		return _x( 'Create shipments from the items available to ship.', 'shipments', 'shiptastic-for-woocommerce' );
	}

	public static function get_modes() {
		return array( 'interactive', 'background' );
	}

	public static function get_requires() {
		return array();
	}

	protected static function get_default_status() {
		$status = Package::get_setting( 'auto_default_status' );

		return empty( $status ) ? 'processing' : $status;
	}

	/**
	 * @param \Vendidero\Shiptastic\BulkFulfillments\StepContext $context
	 *
	 * @return StepResult
	 */
	public function run( $context ) {
		$order_shipment = $context->order_shipment;

		if ( ! $order_shipment ) {
			return StepResult::error( _x( 'The order does not exist.', 'shipments', 'shiptastic-for-woocommerce' ), 'order_not_found' );
		}

		$created = $order_shipment->create_shipments(
			self::get_default_status(),
			array(
				'location'        => $context->queue_item->get_location(),
				'shipping_method' => $context->queue_item->get_shipping_method(),
			)
		);

		if ( is_wp_error( $created ) ) {
			return StepResult::from_wp_error( $created );
		}

		$shipments = $context->get_package_shipments();

		if ( empty( $shipments ) ) {
			return StepResult::error( _x( 'No shipment could be created, e.g. because all items are on backorder.', 'shipments', 'shiptastic-for-woocommerce' ), 'no_shipments' );
		}

		return StepResult::done( array( 'shipment_ids' => array_keys( $shipments ) ) );
	}

	/**
	 * Returns the items of the package which may be part of a shipment: order_item_id => available quantity
	 * (incl. backordered units, which may be added manually) + backordered quantity.
	 *
	 * @param \Vendidero\Shiptastic\BulkFulfillments\StepContext $context
	 *
	 * @return array
	 */
	protected function get_package_items( $context ) {
		$order_shipment = $context->order_shipment;
		$item           = $context->queue_item;
		$items          = array();

		foreach ( $order_shipment->get_packages() as $package ) {
			if ( $package['location'] !== $item->get_location() || $order_shipment->get_shipping_method_id( $package['method_id'] ) !== $item->get_shipping_method() ) {
				continue;
			}

			foreach ( $package['item_map'] as $order_item_id => $quantity ) {
				$items[ $order_item_id ] = array(
					'available'   => $quantity,
					'backordered' => 0,
				);
			}
		}

		foreach ( $order_shipment->get_backordered_items_left_for_shipping() as $order_item_id => $data ) {
			$product = $order_shipment->get_order_item_product( $data['item'] );

			if ( wc_stc_get_product_location_name( $product ) !== $item->get_location() ) {
				continue;
			}

			if ( ! isset( $items[ $order_item_id ] ) ) {
				$items[ $order_item_id ] = array(
					'available'   => 0,
					'backordered' => 0,
				);
			}

			$items[ $order_item_id ]['backordered'] = $data['quantity'];
		}

		return $items;
	}

	/**
	 * @param \Vendidero\Shiptastic\Order $order_shipment
	 * @param \WC_Order_Item $order_item
	 *
	 * @return array
	 */
	public static function get_item_data( $order_shipment, $order_item ) {
		$product = $order_shipment->get_order_item_product( $order_item );

		return array(
			'id'     => $order_item->get_id(),
			'name'   => html_entity_decode( wp_strip_all_tags( $order_item->get_name() ), ENT_QUOTES, get_bloginfo( 'charset' ) ),
			'sku'    => $product ? $product->get_sku() : '',
			'gtin'   => $product && is_callable( array( $product->get_product(), 'get_global_unique_id' ) ) ? $product->get_product()->get_global_unique_id() : '',
			'image'  => $product && $product->get_product() ? wp_get_attachment_image_url( $product->get_product()->get_image_id(), 'thumbnail' ) : '',
			'weight' => $product ? (float) $product->get_shipping_weight() : 0.0,
			'length' => $product ? (float) $product->get_shipping_length() : 0.0,
			'width'  => $product ? (float) $product->get_shipping_width() : 0.0,
			'height' => $product ? (float) $product->get_shipping_height() : 0.0,
		);
	}

	public static function get_packaging_data() {
		$packaging_list = array();

		foreach ( wc_stc_get_packaging_list() as $packaging ) {
			$packaging_list[] = array(
				'id'        => $packaging->get_id(),
				'title'     => $packaging->get_title(),
				'weight'    => (float) $packaging->get_weight(),
				'maxWeight' => (float) $packaging->get_max_content_weight(),
				'length'    => (float) $packaging->get_inner_length(),
				'width'     => (float) $packaging->get_inner_width(),
				'height'    => (float) $packaging->get_inner_height(),
				'providers' => $packaging->get_available_shipping_provider(),
			);
		}

		return $packaging_list;
	}

	/**
	 * @param \Vendidero\Shiptastic\BulkFulfillments\StepContext $context
	 *
	 * @return array
	 */
	public function get_view_data( $context ) {
		$order_shipment = $context->order_shipment;
		$order          = $order_shipment->get_order();
		$items          = array();
		$shipments      = array();

		foreach ( $this->get_package_items( $context ) as $order_item_id => $quantities ) {
			if ( $order_item = $order->get_item( $order_item_id ) ) {
				$items[] = array_merge(
					self::get_item_data( $order_shipment, $order_item ),
					array(
						'quantity'    => $quantities['available'],
						'backordered' => $quantities['backordered'],
					)
				);
			}
		}

		foreach ( $context->get_package_shipments() as $shipment ) {
			$shipment_items = array();

			foreach ( $shipment->get_items() as $shipment_item ) {
				$shipment_items[] = array(
					'id'       => $shipment_item->get_order_item_id(),
					'name'     => $shipment_item->get_name(),
					'quantity' => $shipment_item->get_quantity(),
				);
			}

			$shipments[] = array(
				'id'          => $shipment->get_id(),
				'status'      => $shipment->get_status(),
				'editable'    => $shipment->is_editable(),
				'packagingId' => $shipment->get_packaging_id(),
				'items'       => $shipment_items,
			);
		}

		return array(
			'items'      => $items,
			'shipments'  => $shipments,
			'packaging'  => self::get_packaging_data(),
			'weightUnit' => get_option( 'woocommerce_weight_unit', 'kg' ),
			'dimUnit'    => get_option( 'woocommerce_dimension_unit', 'cm' ),
		);
	}

	/**
	 * Applies the submitted shipment layout of the package:
	 * shipments: list of [ id (existing id or temporary key for new shipments), packaging_id, items: order_item_id => quantity ].
	 * Package shipments missing from the layout are removed (in case editable).
	 *
	 * @param \Vendidero\Shiptastic\BulkFulfillments\StepContext $context
	 * @param array $data
	 *
	 * @return StepResult
	 */
	public function process( $context, $data ) {
		$order_shipment = $context->order_shipment;
		$order          = $order_shipment->get_order();
		$allowed_items  = $this->get_package_items( $context );
		$existing       = $context->get_package_shipments();
		$id_map         = array();
		$kept           = array();

		foreach ( $existing as $shipment ) {
			foreach ( $shipment->get_items() as $shipment_item ) {
				$allowed_items[ $shipment_item->get_order_item_id() ] = true;
			}
		}

		foreach ( (array) ( isset( $data['shipments'] ) ? $data['shipments'] : array() ) as $shipment_data ) {
			$shipment_data = wp_parse_args(
				(array) $shipment_data,
				array(
					'id'           => '',
					'packaging_id' => 0,
					'items'        => array(),
				)
			);

			$items = array();

			foreach ( (array) $shipment_data['items'] as $order_item_id => $quantity ) {
				$order_item_id = absint( $order_item_id );
				$quantity      = absint( $quantity );

				if ( $quantity > 0 && isset( $allowed_items[ $order_item_id ] ) && $order->get_item( $order_item_id ) ) {
					$items[ $order_item_id ] = $quantity;
				}
			}

			if ( empty( $items ) ) {
				continue;
			}

			$props = array_filter(
				array(
					'packaging_id' => absint( $shipment_data['packaging_id'] ),
				)
			);

			if ( is_numeric( $shipment_data['id'] ) && isset( $existing[ absint( $shipment_data['id'] ) ] ) ) {
				$shipment = $existing[ absint( $shipment_data['id'] ) ];

				if ( $shipment->is_editable() ) {
					$this->sync_shipment_items( $shipment, $order, $items );

					if ( ! empty( $props ) ) {
						$shipment->sync( $props );
					}

					$shipment->save();
				}

				$kept[] = $shipment->get_id();
				continue;
			}

			$shipment = wc_stc_create_shipment(
				$order_shipment,
				array(
					'items' => $items,
					'props' => array_merge(
						$props,
						array(
							'status'          => self::get_default_status(),
							'location'        => $context->queue_item->get_location(),
							'shipping_method' => $context->queue_item->get_shipping_method(),
						)
					),
				)
			);

			if ( is_wp_error( $shipment ) ) {
				return StepResult::from_wp_error( $shipment );
			}

			$order_shipment->add_shipment( $shipment );

			$id_map[ (string) $shipment_data['id'] ] = $shipment->get_id();
			$kept[]                                  = $shipment->get_id();
		}

		foreach ( $existing as $shipment_id => $shipment ) {
			if ( ! in_array( $shipment_id, $kept, true ) && $shipment->is_editable() ) {
				$order_shipment->remove_shipment( $shipment_id );
			}
		}

		$order_shipment->validate_shipment_item_quantities();
		$order_shipment->save();

		if ( empty( $kept ) ) {
			return StepResult::error( _x( 'Please add at least one shipment.', 'shipments', 'shiptastic-for-woocommerce' ), 'no_shipments' );
		}

		return StepResult::done(
			array(
				'shipment_ids' => $kept,
				'id_map'       => $id_map,
			)
		);
	}

	/**
	 * @param \Vendidero\Shiptastic\SimpleShipment $shipment
	 * @param \WC_Order $order
	 * @param array $items order_item_id => quantity
	 */
	protected function sync_shipment_items( $shipment, $order, $items ) {
		foreach ( $items as $order_item_id => $quantity ) {
			if ( $shipment_item = $shipment->get_item_by_order_item_id( $order_item_id ) ) {
				$shipment_item->sync( array( 'quantity' => $quantity ) );
			} elseif ( $order_item = $order->get_item( $order_item_id ) ) {
				$shipment->add_item( wc_stc_create_shipment_item( $shipment, $order_item, array( 'quantity' => $quantity ) ) );
			}
		}

		foreach ( $shipment->get_items() as $shipment_item ) {
			if ( ! isset( $items[ $shipment_item->get_order_item_id() ] ) ) {
				$shipment->remove_item( $shipment_item->get_id() );
			}
		}
	}
}
