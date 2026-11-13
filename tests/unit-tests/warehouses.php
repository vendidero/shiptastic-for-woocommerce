<?php

use Vendidero\Shiptastic\Backorders;
use Vendidero\Shiptastic\ShippingMethod\MethodHelper;
use Vendidero\Shiptastic\Warehouse;

/**
 * Warehouse based package splitting, shipment warehouses and backorder handling.
 */
class Warehouses extends \Vendidero\Shiptastic\Tests\Framework\UnitTestCase {

	public function setUp(): void {
		parent::setUp();

		update_option( 'woocommerce_shiptastic_enable_auto_packing', 'no' );
		update_option( 'woocommerce_manage_stock', 'yes' );
	}

	protected function create_warehouse( $title, $props = array() ) {
		$warehouse = new Warehouse();
		$warehouse->set_title( $title );
		$warehouse->set_props( $props );
		$warehouse->save();

		return wc_stc_get_warehouse( $warehouse->get_id() );
	}

	protected function create_group( $title, $props = array() ) {
		return $this->create_warehouse( $title, array_merge( $props, array( 'type' => 'group' ) ) );
	}

	protected function create_product( $props = array(), $warehouse_name = '', $ship_separately_via = '' ) {
		$product = WC_Helper_Product::create_simple_product();
		$product->set_props( $props );

		$s_product = wc_shiptastic_get_product( $product );

		if ( $warehouse_name ) {
			$s_product->set_warehouse_name( $warehouse_name );
		}

		if ( $ship_separately_via ) {
			$s_product->set_ship_separately_via( $ship_separately_via );
		}

		$product->save();

		return wc_get_product( $product->get_id() );
	}

	/**
	 * @param array $lines Array of [ product, quantity ]
	 * @param array $shipping_line_meta
	 *
	 * @return WC_Order
	 */
	protected function create_order( $lines, $shipping_line_meta = array() ) {
		$order = wc_create_order();

		foreach ( $lines as $line ) {
			list( $product, $quantity ) = $line;

			$item = new WC_Order_Item_Product();
			$item->set_props(
				array(
					'product'  => $product,
					'quantity' => $quantity,
					'subtotal' => 10 * $quantity,
					'total'    => 10 * $quantity,
				)
			);
			$order->add_item( $item );
		}

		$shipping = new WC_Order_Item_Shipping();
		$shipping->set_props(
			array(
				'method_title' => 'Flat rate',
				'method_id'    => 'flat_rate',
				'instance_id'  => 1,
				'total'        => '5',
			)
		);

		foreach ( $shipping_line_meta as $key => $value ) {
			$shipping->add_meta_data( $key, $value, true );
		}

		$order->add_item( $shipping );
		$order->set_shipping_first_name( 'Max' );
		$order->set_shipping_last_name( 'Mustermann' );
		$order->set_shipping_address_1( 'Musterstr. 12' );
		$order->set_shipping_postcode( '12222' );
		$order->set_shipping_city( 'Berlin' );
		$order->set_shipping_country( 'DE' );
		$order->set_status( 'processing' );
		$order->save();

		return wc_get_order( $order->get_id() );
	}

	protected function get_order_item( $order_id, $product ) {
		$order = wc_get_order( $order_id );

		foreach ( $order->get_items() as $item ) {
			if ( $item->get_product_id() === $product->get_id() ) {
				return $item;
			}
		}

		return null;
	}

	protected function get_shipment_order( $order ) {
		/**
		 * Pre-existing: Product::get_customs_description() reads an internal meta key via get_meta()
		 * which triggers a notice whenever shipment items are created (fails other suites too).
		 */
		$this->setExpectedIncorrectUsage( 'is_internal_meta_key' );

		if ( $cache = \Vendidero\Shiptastic\Caches\Helper::get_cache_object( 'shipment-orders' ) ) {
			$cache->remove( is_numeric( $order ) ? $order : $order->get_id() );
		}

		return wc_stc_get_shipment_order( is_numeric( $order ) ? $order : $order->get_id() );
	}

	protected function get_shipment_quantities( $shipment ) {
		$quantities = array();

		foreach ( $shipment->get_items() as $item ) {
			$quantities[ $item->get_product_id() ] = $item->get_quantity();
		}

		return $quantities;
	}

	public function test_warehouse_props() {
		$group     = $this->create_group(
			'Nord',
			array(
				'override_address' => true,
				'address'          => array(
					'company'   => 'Lager Nord',
					'address_1' => 'Hafenstr. 5',
					'postcode'  => '20095',
					'city'      => 'Hamburg',
					'country'   => 'DE',
					'unknown'   => 'ignored',
				),
			)
		);
		$warehouse = $this->create_warehouse( 'Hamburg', array( 'group' => 'nord' ) );
		$ungrouped = $this->create_warehouse( 'Berlin' );

		$this->assertEquals( 'hamburg', $warehouse->get_name() );
		$this->assertTrue( $group->is_group() );
		$this->assertFalse( $warehouse->is_group() );
		$this->assertEquals( 'self', $warehouse->get_type() );

		// Groups are not listed as warehouses (e.g. product warehouse select)
		$this->assertEquals( array( 'hamburg', 'berlin' ), wp_list_pluck( array_map( function ( $w ) { return $w->get_data(); }, wc_stc_get_warehouse_list() ), 'name' ) );
		$this->assertEquals( array( 'nord' ), wp_list_pluck( array_map( function ( $w ) { return $w->get_data(); }, wc_stc_get_warehouse_group_list() ), 'name' ) );

		// Locations
		$this->assertEquals( 'nord', $warehouse->get_location()->get_name() );
		$this->assertEquals( 'berlin', $ungrouped->get_location()->get_name() );

		$this->assertTrue( $group->has_address() );
		$this->assertArrayNotHasKey( 'unknown', $group->get_address() );

		// The address is only used while the override is active
		$group->set_override_address( false );
		$this->assertFalse( $group->has_address() );
		$this->assertEquals( wc_stc_get_shipment_setting_address_fields( 'shipper' ), $group->get_sender_address() );
		$group->set_override_address( true );

		// Warehouses may not override the address
		$ungrouped->set_props(
			array(
				'override_address' => true,
				'address'          => array(
					'address_1' => 'Teststr. 1',
					'country'   => 'DE',
				),
			)
		);
		$this->assertFalse( $ungrouped->has_address() );

		$sender = $group->get_sender_address();
		$this->assertEquals( 'Hafenstr.', $sender['street'] );
		$this->assertEquals( '5', $sender['street_number'] );
		$this->assertEquals( 'DE', $sender['country'] );
		// Deleting a group turns its warehouses into locations of their own
		$group->delete( true );
		$this->assertEquals( 'hamburg', wc_stc_get_warehouse( $warehouse->get_id() )->get_location()->get_name() );
	}

	public function test_product_location_name() {
		$this->create_group( 'Nord' );
		$this->create_warehouse( 'Hamburg', array( 'group' => 'nord' ) );
		$this->create_warehouse( 'Berlin' );

		$this->assertEquals( 'nord', wc_stc_get_product_location_name( $this->create_product( array(), 'hamburg' ) ) );
		$this->assertEquals( 'berlin', wc_stc_get_product_location_name( $this->create_product( array(), 'berlin' ) ) );
		$this->assertEquals( '', wc_stc_get_product_location_name( $this->create_product( array(), 'does-not-exist' ) ) );
		$this->assertEquals( '', wc_stc_get_product_location_name( $this->create_product( array(), 'nord' ) ) );
		$this->assertEquals( '', wc_stc_get_product_location_name( $this->create_product() ) );
	}

	public function test_split_cart_packages() {
		$this->create_group( 'Nord' );
		$this->create_warehouse( 'Hamburg', array( 'group' => 'nord' ) );
		$this->create_warehouse( 'Kiel', array( 'group' => 'nord' ) );
		$this->create_warehouse( 'Berlin' );

		$products = array(
			'default'  => $this->create_product(),
			'hamburg'  => $this->create_product( array(), 'hamburg' ),
			'kiel'     => $this->create_product( array(), 'kiel' ),
			'berlin'   => $this->create_product( array(), 'berlin' ),
			'hh_dhl'   => $this->create_product( array(), 'hamburg', 'dhl' ),
			'dhl_only' => $this->create_product( array(), '', 'dhl' ),
		);

		$contents = array();

		foreach ( $products as $key => $product ) {
			$contents[ $key ] = array(
				'data'       => $product,
				'quantity'   => 1,
				'line_total' => 10,
			);
		}

		$packages = MethodHelper::split_cart_packages(
			array(
				array(
					'contents'        => $contents,
					'contents_cost'   => 60,
					'applied_coupons' => array( 'free' ),
				),
			)
		);

		$this->assertCount( 5, $packages );

		// Default location
		$this->assertEquals( array( 'default' ), array_keys( $packages[0]['contents'] ) );
		$this->assertEquals( 10, $packages[0]['contents_cost'] );
		$this->assertEquals( array( 'free' ), $packages[0]['applied_coupons'] );

		// Warehouses of the same group ship together, coupons are kept
		$this->assertEquals( array( 'hamburg', 'kiel' ), array_keys( $packages[1]['contents'] ) );
		$this->assertEquals( 'nord', $packages[1]['location'] );
		$this->assertEquals( 20, $packages[1]['contents_cost'] );
		$this->assertEquals( array( 'free' ), $packages[1]['applied_coupons'] );
		$this->assertArrayNotHasKey( 'shipping_provider', $packages[1] );

		// Ungrouped warehouse is a location of its own
		$this->assertEquals( array( 'berlin' ), array_keys( $packages[2]['contents'] ) );
		$this->assertEquals( 'berlin', $packages[2]['location'] );

		// Provider split within a location
		$this->assertEquals( array( 'hh_dhl' ), array_keys( $packages[3]['contents'] ) );
		$this->assertEquals( 'nord', $packages[3]['location'] );
		$this->assertEquals( 'dhl', $packages[3]['shipping_provider'] );
		$this->assertEquals( array(), $packages[3]['applied_coupons'] );

		// Provider split within the default location
		$this->assertEquals( array( 'dhl_only' ), array_keys( $packages[4]['contents'] ) );
		$this->assertEquals( '', $packages[4]['location'] );
		$this->assertEquals( 'dhl', $packages[4]['shipping_provider'] );
	}

	public function test_rate_meta() {
		$product = $this->create_product();
		$rate    = new WC_Shipping_Rate( 'flat_rate:1', 'Flat', 10, array(), 'flat_rate', 1 );
		$rate    = MethodHelper::register_rate_meta(
			$rate,
			array(
				'package' => array(
					'contents' => array(
						'a' => array(
							'data'     => $product,
							'quantity' => 2,
						),
						'b' => array(
							'data'     => $product,
							'quantity' => 3,
						),
					),
					'location' => 'nord',
				),
			),
			'flat_rate:1'
		);

		$meta = $rate->get_meta_data();

		$this->assertEquals( 5, $meta['_packaged_items'][ $product->get_id() ]['quantity'] );
		$this->assertEquals( 'nord', $meta['_location'] );
	}

	public function test_order_shipments_split_by_location() {
		$this->create_group( 'Nord' );
		$this->create_warehouse( 'Hamburg', array( 'group' => 'nord' ) );
		$this->create_warehouse( 'Kiel', array( 'group' => 'nord' ) );

		$p_default = $this->create_product();
		$p_hamburg = $this->create_product( array(), 'hamburg' );
		$p_kiel    = $this->create_product( array(), 'kiel' );
		$order     = $this->create_order( array( array( $p_default, 2 ), array( $p_hamburg, 1 ), array( $p_kiel, 3 ) ) );

		$shipments = $this->get_shipment_order( $order )->create_shipments();

		$this->assertCount( 2, $shipments );

		$by_location = array();

		foreach ( $shipments as $shipment ) {
			$by_location[ $shipment->get_location() ] = wc_stc_get_shipment( $shipment->get_id() );
		}

		$this->assertEquals( array( '', 'nord' ), array_keys( $by_location ) );
		$this->assertEquals( array( $p_default->get_id() => 2 ), $this->get_shipment_quantities( $by_location[''] ) );
		$this->assertEquals( array( '' ), $by_location['']->get_warehouses() );

		// One shipment holding items of two warehouses of the same group
		$this->assertEquals(
			array(
				$p_hamburg->get_id() => 1,
				$p_kiel->get_id()    => 3,
			),
			$this->get_shipment_quantities( $by_location['nord'] )
		);
		$this->assertEquals( array( 'hamburg', 'kiel' ), $by_location['nord']->get_warehouses() );

		// Persisted + queryable
		$this->assertEquals( 'nord', $by_location['nord']->get_location() );

		$query = wc_stc_get_shipments(
			array(
				'order_id' => $order->get_id(),
				'location' => 'nord',
			)
		);

		$this->assertCount( 1, $query );
	}

	public function test_order_shipments_use_shipping_line_location() {
		$this->create_warehouse( 'Berlin' );

		$product = $this->create_product();
		$order   = $this->create_order(
			array( array( $product, 2 ) ),
			array(
				'_packaged_items' => array( $product->get_id() => array( 'quantity' => 2 ) ),
				'_location'       => 'berlin',
			)
		);

		$shipments = $this->get_shipment_order( $order )->create_shipments();

		$this->assertCount( 1, $shipments );
		$this->assertEquals( 'berlin', current( $shipments )->get_location() );
	}

	public function test_manual_shipment_location_from_items() {
		$this->create_warehouse( 'Berlin' );

		$p_berlin  = $this->create_product( array(), 'berlin' );
		$p_default = $this->create_product();
		$order     = $this->create_order( array( array( $p_berlin, 1 ), array( $p_default, 1 ) ) );

		$order_shipment = $this->get_shipment_order( $order );
		$berlin_item    = $this->get_order_item( $order->get_id(), $p_berlin );

		$shipment = wc_stc_create_shipment( $order_shipment, array( 'items' => array( $berlin_item->get_id() => 1 ) ) );
		$this->assertEquals( 'berlin', $shipment->get_location() );
		$this->assertEquals( array( 'berlin' ), $shipment->get_warehouses() );

		// Items of different locations -> default location, but all warehouses are known
		$order    = $this->create_order( array( array( $p_berlin, 1 ), array( $p_default, 1 ) ) );
		$shipment = wc_stc_create_shipment( $this->get_shipment_order( $order->get_id() ) );
		$this->assertEquals( '', $shipment->get_location() );
		$this->assertEqualsCanonicalizing( array( 'berlin', '' ), $shipment->get_warehouses() );
	}

	public function test_shipment_sender_address_from_location() {
		$this->create_group(
			'Nord',
			array(
				'override_address' => true,
				'address'          => array(
					'company'   => 'Lager Nord',
					'address_1' => 'Hafenstr. 5',
					'postcode'  => '20095',
					'city'      => 'Hamburg',
					'country'   => 'DE',
				),
			)
		);
		$this->create_warehouse( 'Hamburg', array( 'group' => 'nord' ) );

		$product   = $this->create_product( array(), 'hamburg' );
		$order     = $this->create_order( array( array( $product, 1 ) ) );
		$shipments = $this->get_shipment_order( $order )->create_shipments();
		$shipment  = current( $shipments );

		$this->assertEquals( 'Hamburg', $shipment->get_sender_city() );
		$this->assertEquals( 'Lager Nord', $shipment->get_sender_company() );
	}

	public function test_calculate_backordered_quantity() {
		$this->assertEquals( 0, Backorders::calculate_backordered_quantity( 10, 3 ) );
		$this->assertEquals( 0, Backorders::calculate_backordered_quantity( 3, 3 ) );
		$this->assertEquals( 3, Backorders::calculate_backordered_quantity( 2, 5 ) );
		$this->assertEquals( 5, Backorders::calculate_backordered_quantity( 0, 5 ) );
		$this->assertEquals( 5, Backorders::calculate_backordered_quantity( -4, 5 ) );
	}

	public function test_backordered_units_excluded_from_shipments() {
		$product = $this->create_product(
			array(
				'manage_stock'   => true,
				'stock_quantity' => 2,
				'backorders'     => 'yes',
			)
		);
		$other   = $this->create_product();

		$order = $this->create_order( array( array( $product, 4 ), array( $other, 1 ) ) );
		wc_reduce_stock_levels( $order->get_id() );

		$item = $this->get_order_item( $order->get_id(), $product );
		$this->assertEquals( 2, Backorders::get_item_backordered_quantity( $item ) );
		$this->assertEquals( -2, wc_get_product( $product->get_id() )->get_stock_quantity() );

		$order_shipment = $this->get_shipment_order( $order );
		$this->assertTrue( $order_shipment->has_backorders() );

		// Available units are shipped, backordered units are left out
		$shipments = $order_shipment->create_shipments();
		$this->assertCount( 1, $shipments );
		$this->assertEquals(
			array(
				$product->get_id() => 2,
				$other->get_id()   => 1,
			),
			$this->get_shipment_quantities( current( $shipments ) )
		);

		// Restocking does not release backorders (stock allocation is up to Woo core)
		wc_update_product_stock( wc_get_product( $product->get_id() ), 10, 'set' );

		$order_shipment = $this->get_shipment_order( $order );
		$this->assertEquals( 2, Backorders::get_item_backordered_quantity( $this->get_order_item( $order->get_id(), $product ) ) );
		$this->assertEquals( array(), $order_shipment->create_shipments() );

		// Still warned about until added manually
		$left = $order_shipment->get_backordered_items_left_for_shipping();
		$this->assertEquals( 2, $left[ $item->get_id() ]['quantity'] );
	}

	public function test_backordered_units_not_synced_automatically() {
		$product = $this->create_product(
			array(
				'manage_stock'   => true,
				'stock_quantity' => 1,
				'backorders'     => 'yes',
			)
		);

		$order = $this->create_order( array( array( $product, 3 ) ) );
		wc_reduce_stock_levels( $order->get_id() );

		$order_shipment = $this->get_shipment_order( $order );
		$item           = $this->get_order_item( $order->get_id(), $product );

		// Syncing all items (no explicit items) leaves out backordered units
		$shipment = wc_stc_create_shipment( $order_shipment );
		$this->assertEquals( array( $product->get_id() => 1 ), $this->get_shipment_quantities( $shipment ) );

		$left = $this->get_shipment_order( $order )->get_backordered_items_left_for_shipping();
		$this->assertEquals( 2, $left[ $item->get_id() ]['quantity'] );

		// Manually adding backordered units is possible, a later sync keeps them
		$shipment = wc_stc_get_shipment( $shipment->get_id() );
		$shipment->get_item_by_order_item_id( $item->get_id() )->set_quantity( 3 );
		$shipment->save();

		$shipment = wc_stc_get_shipment( $shipment->get_id() );
		$order_shipment = $this->get_shipment_order( $order );
		$shipment->set_order_shipment( $order_shipment );
		$shipment->sync_items();
		$shipment->save();

		$this->assertEquals( array( $product->get_id() => 3 ), $this->get_shipment_quantities( wc_stc_get_shipment( $shipment->get_id() ) ) );
		$this->assertFalse( $this->get_shipment_order( $order )->has_backorders() );
	}

	public function test_cancelled_order_clears_backorders() {
		$product = $this->create_product(
			array(
				'manage_stock'   => true,
				'stock_quantity' => 0,
				'backorders'     => 'yes',
			)
		);

		$order = $this->create_order( array( array( $product, 4 ) ) );
		wc_reduce_stock_levels( $order->get_id() );

		$this->assertEquals( 4, Backorders::get_item_backordered_quantity( $this->get_order_item( $order->get_id(), $product ) ) );

		wc_increase_stock_levels( $order->get_id() );

		$this->assertEquals( 0, Backorders::get_item_backordered_quantity( $this->get_order_item( $order->get_id(), $product ) ) );
		$this->assertEquals( 0, wc_get_product( $product->get_id() )->get_stock_quantity() );
	}
}
