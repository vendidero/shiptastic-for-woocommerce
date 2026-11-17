<?php

use Vendidero\Shiptastic\BulkFulfillments\ShipmentLayout;
use Vendidero\Shiptastic\Tests\Helpers\PackagingHelper;

/**
 * Packing data of the fulfillment app used for packaging suggestions (packing.js): same units (mm, g) and inner
 * dimensions as the server-side packer.
 */
class FulfillmentPackingTest extends \Vendidero\Shiptastic\Tests\Framework\UnitTestCase {

	public function setUp(): void {
		parent::setUp();

		// Shop (and packaging) units: cm and kg.
		update_option( 'woocommerce_dimension_unit', 'cm' );
		update_option( 'woocommerce_weight_unit', 'kg' );
	}

	public function test_packaging_packing_data() {
		$shipping_class = wp_insert_term( 'Bulky', 'product_shipping_class' );

		$packaging = PackagingHelper::create_packaging(
			array(
				'length'                     => 30,
				'width'                      => 20,
				'height'                     => 10,
				'inner_length'               => 28,
				'inner_width'                => 18,
				'inner_height'               => 9,
				'weight'                     => 0.5,
				'max_content_weight'         => 5,
				'available_shipping_classes' => array( $shipping_class['term_id'] ),
			)
		);

		$data = ShipmentLayout::get_packaging_packing_data( $packaging );

		// Inner dimensions in mm, weights in g, max weight = max content weight (without the empty weight).
		$this->assertEquals( 180, $data['width'] );
		$this->assertEquals( 280, $data['length'] );
		$this->assertEquals( 90, $data['depth'] );
		$this->assertEquals( 500, $data['emptyWeight'] );
		$this->assertEquals( 5000, $data['maxWeight'] );
		$this->assertEquals( array( $shipping_class['term_id'] ), $data['shippingClasses'] );

		// Without max content weight: no limit (0), without inner dimensions: outer dimensions minus the inner buffer.
		update_option( 'woocommerce_shiptastic_enable_auto_packing', 'yes' );
		update_option( 'woocommerce_shiptastic_packing_inner_buffer_type', 'fixed' );
		update_option( 'woocommerce_shiptastic_packing_inner_fixed_buffer', 5 );

		$unlimited = PackagingHelper::create_packaging(
			array(
				'length'             => 30,
				'width'              => 20,
				'height'             => 10,
				'max_content_weight' => 0,
			)
		);
		$data      = ShipmentLayout::get_packaging_packing_data( $unlimited );

		$this->assertEquals( 0, $data['maxWeight'] );
		$this->assertEquals( 195, $data['width'] );
		$this->assertEquals( 295, $data['length'] );
		$this->assertEquals( 95, $data['depth'] );
		$this->assertEquals( array(), $data['shippingClasses'] );

		$list = array_column( ShipmentLayout::get_packaging_data(), null, 'id' );
		$this->assertEquals( 180, $list[ $packaging->get_id() ]['packing']['width'] );
	}

	public function test_item_packing_data() {
		$shipping_class = wp_insert_term( 'Small', 'product_shipping_class' );
		$product        = WC_Helper_Product::create_simple_product();

		$product->set_length( 10 );
		$product->set_width( 5 );
		$product->set_height( 2 );
		$product->set_weight( 0.25 );
		$product->set_shipping_class_id( $shipping_class['term_id'] );
		$product->save();

		$order = wc_create_order();
		$item  = new WC_Order_Item_Product();
		$item->set_props(
			array(
				'product'  => $product,
				'quantity' => 2,
				'subtotal' => 20,
				'total'    => 20,
			)
		);
		$order->add_item( $item );
		$order->save();

		$data = ShipmentLayout::get_item_packing_data( $item );

		$this->assertEquals( 50, $data['width'] );
		$this->assertEquals( 100, $data['length'] );
		$this->assertEquals( 20, $data['depth'] );
		$this->assertEquals( 250, $data['weight'] );
		$this->assertEquals( $shipping_class['term_id'], $data['shippingClass'] );

		// Part of the item data (layout, pack view).
		$item_data = ShipmentLayout::get_item_data( wc_stc_get_shipment_order( $order ), $item );
		$this->assertEquals( $data, $item_data['packing'] );

		// Products without dimensions
		$product->set_length( '' );
		$product->set_width( '' );
		$product->set_height( '' );
		$product->save();

		$data = ShipmentLayout::get_item_packing_data( new WC_Order_Item_Product( $item->get_id() ) );
		$this->assertEquals( 0, $data['width'] );
		$this->assertEquals( 250, $data['weight'] );
	}
}
