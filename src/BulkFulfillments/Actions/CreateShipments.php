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
				'itemsAvailableToShip' => function () {
					$state = wp_interactivity_state( 'shiptastic/fulfillments' );

					return $state['itemsAvailableToShip'];
				},
				'shipments'            => function () {
					$state = wp_interactivity_state( 'shiptastic/fulfillments' );

					return $state['shipments'];
				},
			)
		);

		Scripts::enqueue_script_module( 'shiptastic/fulfillments/' . self::get_name() );
		?>
		<div
			data-wp-interactive="shiptastic/fulfillments/create_shipments"
			data-wp-watch="callbacks.onUpdateState"
		>
			<ul>
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
				<div
					data-wp-on--dragover="actions.onShipmentItemDragOver"
					data-wp-on--drop="actions.onShipmentItemDrop"
				>
					<h3>Shipment <span data-wp-text="context.shipment.currentShipmentNumber"></span>/<span data-wp-text="state.shipmentCount"></span></h3>

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

			<template
				data-wp-each--shipment_item="state.allShipmentItems"
				data-wp-each-key="context.shipment_item.id"
			>
				<div>
					<span data-wp-text="context.shipment_item.name"></span> x<span data-wp-text="context.shipment_item.quantity"></span>
				</div>
			</template>
		</div>
		<?php
	}
}
