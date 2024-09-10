<?php

use Vendidero\Shiptastic\Tests\Helpers\ShipmentHelper;
use Vendidero\Shiptastic\Tests\Helpers\PackagingHelper;

/**
 * Class WC_Tests_Install.
 * @package WooCommerce\Tests\Util
 */
class Install extends \Vendidero\Shiptastic\Tests\Framework\UnitTestCase {

	public function update() {
		update_option( 'woocommerce_shiptastic_version', ( (float) \Vendidero\Shiptastic\Package::get_version() - 1 ) );
		update_option( 'woocommerce_shiptastic_db_version', \Vendidero\Shiptastic\Package::get_version() );
		\Vendidero\Shiptastic\Package::check_version();

		$this->assertTrue( did_action( 'woocommerce_shiptastic_updated' ) === 1 );
	}

	public function test_install() {
		// clean existing install first
		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', true );
			define( 'WC_SHIPTASTIC_REMOVE_ALL_DATA', true );
		}

		include( dirname( dirname( dirname( __FILE__ ) ) ) . '/uninstall.php' );

		\Vendidero\Shiptastic\Install::install();

		$this->assertTrue( get_option( 'woocommerce_shiptastic_version' ) === \Vendidero\Shiptastic\Package::get_version() );
		$this->assertEquals( 'yes', get_option( 'woocommerce_shiptastic_enable_auto_packing' ) );

		// Check if Tables are installed
		global $wpdb;

		// Shipments
		$table_name = $wpdb->get_var( "SHOW TABLES LIKE '{$wpdb->prefix}woocommerce_stc_shipments'" );
		$this->assertEquals( "{$wpdb->prefix}woocommerce_stc_shipments", $table_name );
	}
}