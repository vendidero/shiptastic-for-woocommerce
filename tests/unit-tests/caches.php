<?php

use Vendidero\Shiptastic\Tests\Helpers\ShipmentHelper;
use Vendidero\Shiptastic\Tests\Helpers\PackagingHelper;

/**
 * Class WC_Tests_Install.
 * @package WooCommerce\Tests\Util
 */
class Caches extends \Vendidero\Shiptastic\Tests\Framework\UnitTestCase {

	function test_temp_disable() {
		$this->assertEquals( true, \Vendidero\Shiptastic\Caches\Helper::is_enabled( 'shipments' ) );
		\Vendidero\Shiptastic\Caches\Helper::disable( 'shipments' );
		$this->assertEquals( false, \Vendidero\Shiptastic\Caches\Helper::is_enabled( 'shipments' ) );
		\Vendidero\Shiptastic\Caches\Helper::enable( 'shipments' );
		$this->assertEquals( true, \Vendidero\Shiptastic\Caches\Helper::is_enabled( 'shipments' ) );
	}

	function setUp(): void {
		parent::setUp();

		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );
	}

	function tear_down() {
		parent::tear_down();

		update_option( 'woocommerce_custom_orders_table_enabled', 'no' );
	}

	function test_shipment_order_cache() {
		update_option( 'woocommerce_custom_orders_table_enabled', 'yes' );

		add_action( 'woocommerce_init', function() {
			WC_Install::create_tables();
		} );

		do_action( 'woocommerce_init' );

		$shipment = ShipmentHelper::create_simple_shipment();
		$order = wc_get_order( $shipment->get_order_id() );

		$shipment_order = wc_stc_get_shipment_order( $order );
		$shipment_order->get_shipments();

		$this->assertEquals( true, true );

		if ( $cache = \Vendidero\Shiptastic\Caches\Helper::get_cache_object( 'shipment-orders' ) ) {
			$this->assertEquals( null !== $cache->get( $order->get_id() ), true );

			// Saving the order should remove cache
			$order->save();

			$this->assertEquals( null === $cache->get( $order->get_id() ), true );

			$shipment_order = wc_stc_get_shipment_order( $order );
			$this->assertEquals( null !== $cache->get( $order->get_id() ), true );

			// Deleting the order should remove cache too
			$order->delete( true );

			$this->assertEquals( null === $cache->get( $order->get_id() ), true );
		}

		update_option( 'woocommerce_custom_orders_table_enabled', 'no' );
	}
}