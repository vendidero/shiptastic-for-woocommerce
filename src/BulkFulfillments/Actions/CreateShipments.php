<?php

namespace Vendidero\Shiptastic\BulkFulfillments\Actions;

use Vendidero\Shiptastic\Package;

class CreateShipments extends \Vendidero\Shiptastic\BulkFulfillments\FulfillmentAction {

	public static function get_title() {
		return _x( 'Create Shipments', 'fulfillments', 'shiptastic-for-woocommerce' );
	}

	public static function get_name() {
		return 'create_shipments';
	}

	public static function get_description() {
		return _x( 'Create shipments from the order items available to ship.', 'fulfillments', 'shiptastic-for-woocommerce' );
	}

	public static function get_must_run_before_actions() {
		return array();
	}

	public function render() {
		wp_register_script_module(
			'shiptastic/fulfillments/' . self::get_name(),
			Package::get_assets_url( 'static/fulfillments/create-shipments.js' ),
			array(
				'@wordpress/interactivity',
				array(
					'id'     => '@wordpress/interactivity-router',
					'import' => 'dynamic',
				),
			),
			Package::get_version()
		);

		wp_interactivity()->add_client_navigation_support_to_script_module(
			'shiptastic/fulfillments/' . self::get_name()
		);

		wp_enqueue_script_module( 'shiptastic/fulfillments/' . self::get_name() );

		$shipment_order = $this->get_order()->get_shipment_order();
		$items          = array();
		$shipments      = array();

		foreach ( $shipment_order->get_available_items_for_shipment() as $item_id => $item ) {
			$order_item = $shipment_order->get_order()->get_item( $item_id, false );
			$weight     = 0.0;
			$width      = 0.0;
			$length     = 0.0;
			$height     = 0.0;

			if ( $order_item && is_callable( array( $order_item, 'get_product' ) ) ) {
				if ( $product = $shipment_order->get_order_item_product( $order_item ) ) {
					$weight = $product->get_shipping_weight();
					$length = $product->get_shipping_length();
					$width  = $product->get_shipping_width();
					$height = $product->get_shipping_height();
				}
			}

			$items[] = array(
				'name'        => $item['name'],
				'maxQuantity' => $item['max_quantity'],
				'quantity'    => $item['max_quantity'],
				'weight'      => $weight,
				'length'      => $length,
				'width'       => $width,
				'height'      => $height,
				'id'          => $item_id,
			);
		}

		foreach ( $shipment_order->get_simple_shipments() as $shipment ) {
			$shipment_items = array();

			foreach ( $shipment->get_items() as $item_id => $item ) {
				$shipment_items[] = array(
					'name'        => $item->get_name(),
					'quantity'    => $item->get_quantity(),
					'maxQuantity' => $item->get_quantity(),
					'id'          => $item->get_order_item_id(),
					'itemId'      => $item->get_id(),
					'weight'      => wc_get_weight( $item->get_weight(), Package::get_current_weight_unit(), $shipment->get_weight_unit() ),
					'length'      => wc_get_dimension( $item->get_length(), Package::get_current_dimension_unit(), $shipment->get_dimension_unit() ),
					'width'       => wc_get_dimension( $item->get_width(), Package::get_current_dimension_unit(), $shipment->get_dimension_unit() ),
					'height'      => wc_get_dimension( $item->get_height(), Package::get_current_dimension_unit(), $shipment->get_dimension_unit() ),
				);
			}

			$shipments[] = array(
				'id'               => $shipment->get_id(),
				'status'           => $shipment->get_status(),
				'weight'           => wc_get_weight( $shipment->get_weight(), Package::get_current_weight_unit(), $shipment->get_weight_unit() ),
				'length'           => wc_get_dimension( $shipment->get_length(), Package::get_current_dimension_unit(), $shipment->get_dimension_unit() ),
				'width'            => wc_get_dimension( $shipment->get_width(), Package::get_current_dimension_unit(), $shipment->get_dimension_unit() ),
				'height'           => wc_get_dimension( $shipment->get_height(), Package::get_current_dimension_unit(), $shipment->get_dimension_unit() ),
				'shippingProvider' => $shipment->get_shipping_provider(),
				'packagingId'      => $shipment->get_packaging_id(),
				'items'            => $shipment_items,
			);
		}

		wp_interactivity_state(
			'shiptastic/fulfillments/shipments',
			array(
				'hasSelectedItems' => false,
				'shipments'        => $shipments,
				'items'            => $items,
			)
		);
		?>
		<div
			data-wp-interactive="shiptastic/fulfillments/shipments"
			data-wp-watch="callbacks.updateContext"
		>
			<ul>
				<template
					data-wp-each--item="state.items"
					data-wp-each-key="context.item.id"
				>
					<li
						data-wp-on--click="actions.toggleSelectItem"
						data-wp-class--is-selected="state.isItemSelected"
					>
						<span data-wp-text="context.item.name"></span>
						<input
							type="number"
							name="quantity"
							data-wp-bind--value="context.item.quantity"
							data-wp-on--click="actions.stopPropagation"
							data-wp-on--input="actions.setItemQuantity"
							min="1"
							step="1"
							data-wp-bind--max="context.item.maxQuantity"
						/>
					</li>
				</template>
			</ul>

			<button
				data-wp-on--click="actions.createShipment"
				data-wp-bind--hidden="!state.hasSelectedItems"
			>
				Create shipment
			</button>

			<template
				data-wp-each--shipment="state.shipments"
				data-wp-each-key="context.shipment.id"
			>
				<div>
					<template
						data-wp-each--shipment_item="context.shipment.items"
						data-wp-each-key="context.shipment_item.id"
					>
						<div>
							<span data-wp-text="context.shipment_item.name"></span>
							<input
								type="number"
								name="quantity"
								data-wp-bind--value="context.shipment_item.quantity"
								data-wp-on--click="actions.stopPropagation"
								data-wp-on--input="actions.setShipmentItemQuantity"
								min="0"
								step="1"
								data-wp-bind--max="context.shipment_item.maxQuantity"
							/>
						</div>
					</template>
				</div>
			</template>
		</div>
		<?php
	}
}
