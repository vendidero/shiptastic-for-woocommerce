<?php

namespace Vendidero\Shiptastic\BulkFulfillments\Actions;

use Vendidero\Shiptastic\BulkFulfillments\Scripts;
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

	public function process( $data ) {
		$shipment_order = $this->get_order()->get_shipment_order();
		$wc_order       = $shipment_order->get_order();
		$shipment_ids   = array();

		foreach ( $data as $shipment ) {
			$shipment = wp_parse_args(
				$shipment,
				array(
					'id'               => '',
					'status'           => '',
					'weight'           => '',
					'length'           => '',
					'width'            => '',
					'height'           => '',
					'weightUnit'       => '',
					'dimensionUnit'    => '',
					'shippingProvider' => '',
					'packagingId'      => '',
					'items'            => array(),
				)
			);

			$props = array(
				'status'            => wc_clean( $shipment['status'] ),
				'weight'            => wc_clean( $shipment['weight'] ),
				'length'            => wc_clean( $shipment['length'] ),
				'width'             => wc_clean( $shipment['width'] ),
				'height'            => wc_clean( $shipment['height'] ),
				'shipping_provider' => wc_clean( $shipment['shippingProvider'] ),
				'packaging_id'      => absint( $shipment['packagingId'] ),
			);

			$items = array();

			foreach ( $shipment['items'] as $item ) {
				$items[ absint( $item['id'] ) ] = absint( $item['quantity'] );
			}

			/**
			 * Remove empty elements to prevent override
			 */
			$props = array_filter( $props );

			if ( ! is_numeric( $shipment['id'] ) ) {
				$shipment = wc_stc_create_shipment(
					$shipment_order,
					array(
						'items' => $items,
						'props' => $props,
					)
				);

				if ( ! is_wp_error( $shipment ) ) {
					$shipment_order->add_shipment( $shipment );

					$shipment_ids[] = $shipment->get_id();
				}
			} elseif ( $shipment = $shipment_order->get_shipment( absint( $shipment['id'] ) ) ) {
				foreach ( $items as $order_item_id => $quantity ) {
					$order_item = $wc_order->get_item( $order_item_id );

					if ( $order_item ) {
						if ( $item = $shipment->get_item_by_order_item_id( $order_item_id ) ) {
							$item->sync( array( 'quantity' => $quantity ) );
						} else {
							$shipment_item = wc_stc_create_shipment_item( $this, $order_item, array( 'quantity' => $quantity ) );
							$shipment->add_item( $shipment_item );
						}
					}
				}

				/**
				 * Remove non-existent items
				 */
				foreach ( $shipment->get_items() as $item ) {
					$item_is_missing_from_sync = ! empty( $items ) && ! isset( $items[ $item->get_order_item_id() ] );
					$order_item_is_missing     = ! $wc_order->get_item( $item->get_order_item_id() );

					if ( $order_item_is_missing || $item_is_missing_from_sync ) {
						$shipment->remove_item( $item->get_id() );
					}
				}

				$shipment->sync( $props );
				$shipment->save();

				$shipment_ids[] = $shipment->get_id();
			}
		}

		foreach ( $shipment_order->get_simple_shipments() as $shipment ) {
			if ( ! in_array( $shipment->get_id(), $shipment_ids, true ) ) {
				$shipment_order->remove_shipment( $shipment->get_id() );
			}
		}

		$shipment_order->validate_shipments();
	}

	public function render() {
		Scripts::register_script_module(
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

		wp_interactivity_state(
			'shiptastic/fulfillments/' . self::get_name(),
			array(
				'shipments'            => function () {
					$state = wp_interactivity_state( 'shiptastic/fulfillments' );
					return $state['shipments'];
				},
				'packagingOptions'     => function () {
					$state = wp_interactivity_config( 'shiptastic/fulfillments' );
					return $state['packagingOptions'];
				},
				'itemsAvailableToShip' => function () {
					$state = wp_interactivity_state( 'shiptastic/fulfillments' );
					return $state['allItemsAvailableToShip'];
				},
			)
		);

		Scripts::enqueue_script_module( 'shiptastic/fulfillments/' . self::get_name() );
		?>
		<div>
			<template
				data-wp-each--item="state.itemsAvailableToShip"
				data-wp-each-key="context.item.id"
			>
				<div
					data-wp-on--click="actions.toggleSelectItem"
					data-wp-class--is-selected="state.isItemSelected"
					draggable="true"
					data-wp-on--dragstart="actions.onShipmentItemDrag"
				>
					<div class="item-column item-img-column">
						<img data-wp-bind--src="context.item.image" />
					</div>
					<div class="item-column item-details-column">
						<h4 data-wp-text="context.item.name"></h4>
						<div class="item-ids">
							<span data-wp-text="context.item.globalUniqueId"></span>
							<span data-wp-text="context.item.sku"></span>
						</div>
						<div class="item-attributes">
							<template
								data-wp-each--attribute="context.item.attributes"
								data-wp-each-key="context.item.attribute.id"
							>
								<div class="attribute-label" data-wp-watch="callbacks.renderItemAttributeLabel"></div>
								<div class="attribute-value" data-wp-watch="callbacks.renderItemAttributeValue"></div>
							</template>
						</div>
					</div>
					<div class="item-column item-qty-column">
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
					</div>
				</div>
			</template>
		</div>

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
			<div
				data-wp-init="callbacks.setupShipment"
				data-wp-watch="callbacks.findBestPackaging"
				data-wp-on--dragover="actions.onShipmentItemDragOver"
				data-wp-on--drop="actions.onShipmentItemDrop"
			>
				<h3>Shipment <span data-wp-text="state.currentShipmentNumber"></span>/<span data-wp-text="state.shipmentCount"></span></h3>

				<div class="shipment-attribute shipment-attribute-weight">
					<label><?php echo wp_kses_post( sprintf( _x( 'Content (%s)', 'shipments', 'shiptastic-for-woocommerce' ), '<span data-wp-text="context.shipment.weightUnit"></span>' ) ); ?></label>

					<input
						type="text"
						data-wp-bind--value="state.formattedShipmentWeight"
						data-wp-on--input="actions.setShipmentWeight"
						name="weight"
						pattern="^\d+([.,]\d+)?$"
						inputmode="decimal"
						data-wp-bind--placeholder="state.shipmentContentWeight"
					/>
				</div>

				<div class="shipment-attribute shipment-attribute-dimensions">
					<label><?php echo wp_kses_post( sprintf( _x( 'Dimensions (%s)', 'shipments', 'shiptastic-for-woocommerce' ), '<span data-wp-text="context.shipment.dimensionUnit"></span>' ) ); ?></label>

					<input
						type="text"
						data-wp-bind--value="state.formattedShipmentLength"
						data-wp-on--input="actions.setShipmentLength"
						name="length"
						pattern="^\d+([.,]\d+)?$"
						inputmode="decimal"
						data-wp-bind--placeholder="state.shipmentContentLength"
					/>

					<input
						type="text"
						data-wp-bind--value="state.formattedShipmentWidth"
						data-wp-on--input="actions.setShipmentWidth"
						name="width"
						pattern="^\d+([.,]\d+)?$"
						inputmode="decimal"
						data-wp-bind--placeholder="state.shipmentContentWidth"
					/>

					<input
						type="text"
						data-wp-bind--value="state.formattedShipmentHeight"
						data-wp-on--input="actions.setShipmentHeight"
						name="height"
						pattern="^\d+([.,]\d+)?$"
						inputmode="decimal"
						data-wp-bind--placeholder="state.shipmentContentHeight"
					/>
				</div>

				<div class="shipment-attribute shipment-attribute-packaging">
					<label><?php echo esc_html_x( 'Packaging', 'shipments', 'shiptastic-for-woocommerce' ); ?></label>

					<select
						data-wp-bind--value="state.shipmentPackagingId"
						data-wp-on--change="actions.setShipmentPackagingId"
						data-wp-watch="callbacks.updatePackaging"
					>
						<option value="0">None</option>
						<template
							data-wp-each--packaging="state.packagingOptions"
							data-wp-each-key="context.packaging.id"
						>
							<option
								data-wp-bind--value="context.packaging.id"
								data-wp-watch="callbacks.renderPackagingTitle"
							>
							</option>
						</template>
					</select>
				</div>

				<a data-wp-on--click="actions.deleteShipment">Delete shipment</a>

				<template
					data-wp-each--shipment_item="context.shipment.items"
					data-wp-each-key="context.shipment_item.itemId"
				>
					<div
						draggable="true"
						data-wp-on--dragstart="actions.onShipmentItemDrag"
					>
						<span data-wp-text="context.shipment_item.name"></span>
						<input
							type="number"
							name="quantity"
							data-wp-bind--value="state.shipmentItemQuantity"
							data-wp-on--click="actions.stopPropagation"
							data-wp-on--input="actions.setShipmentItemQuantity"
							min="0"
							step="1"
							data-wp-bind--max="state.shipmentItemMaxQuantity"
						/>
						<a data-wp-on--click="actions.deleteShipmentItem">Delete item</a>
					</div>
				</template>
			</div>
		</template>

		<template
			data-wp-each--shipment_item="state.allShipmentItems"
			data-wp-each-key="context.shipment_item.id"
		>
			<div>
				<span data-wp-text="context.shipment_item.name"></span> x<span data-wp-text="context.shipment_item.quantity"></span>
			</div>
		</template>
		<?php
	}
}
