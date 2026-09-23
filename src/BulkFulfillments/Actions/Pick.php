<?php

namespace Vendidero\Shiptastic\BulkFulfillments\Actions;

use Vendidero\Shiptastic\BulkFulfillments\Scripts;
use Vendidero\Shiptastic\Package;

class Pick extends \Vendidero\Shiptastic\BulkFulfillments\FulfillmentAction {

	public static function get_title() {
		return _x( 'Pick', 'fulfillments', 'shiptastic-for-woocommerce' );
	}

	public static function get_description() {
		return _x( 'Pack items for shipments.', 'fulfillments', 'shiptastic-for-woocommerce' );
	}

	public static function get_name() {
		return 'pick';
	}

	public static function get_supported_contexts() {
		return array( 'shipment', 'order' );
	}

	public function render() {
		Scripts::register_script_module(
			'shiptastic/fulfillments/' . self::get_name(),
			Package::get_assets_url( 'static/fulfillments/pick.js' ),
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

		Scripts::enqueue_script_module( 'shiptastic/fulfillments/' . self::get_name() );
		?>
		<div
			data-wp-interactive="shiptastic/fulfillments/pick"
			data-wp-watch="callbacks.onUpdateState"
		>
			<ul>
				<template
					data-wp-each--shipment_item="state.allShipmentItems"
					data-wp-each-key="context.shipment_item.id"
				>
					<li>
						<span data-wp-text="context.shipment_item.name"></span> x<span data-wp-text="context.shipment_item.quantity"></span>
					</li>
				</template>
			</ul>
		</div>
		<?php
	}
}
