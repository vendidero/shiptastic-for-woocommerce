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

		wp_enqueue_script_module( 'shiptastic/fulfillments/' . self::get_name() );
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
						data-wp-each-key="context.shipment_item.itemId"
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
