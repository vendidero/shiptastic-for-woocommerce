<?php

namespace Vendidero\Shiptastic\BulkFulfillments;

defined( 'ABSPATH' ) || exit;

class FulfillmentScriptModules extends \WP_Script_Modules {

	public function add_hooks() {
		add_action( 'woocommerce_shiptastic_fulfillment_print_footer_scripts', array( $this, 'print_import_map' ), 9 );
		add_action( 'woocommerce_shiptastic_fulfillment_print_footer_scripts', array( $this, 'print_enqueued_script_modules' ) );
		add_action( 'woocommerce_shiptastic_fulfillment_print_footer_scripts', array( $this, 'print_script_module_preloads' ) );

		/*
		 * Print translations after classic scripts like wp-i18n are loaded (at
		 * priority 10 via _wp_footer_scripts), but before the script modules
		 * execute. Script modules with type="module" are deferred by default,
		 * so inline translation scripts at priority 11 will execute before them.
		 */
		add_action( 'woocommerce_shiptastic_fulfillment_print_footer_scripts', array( $this, 'print_script_module_translations' ), 11 );

		add_action( 'woocommerce_shiptastic_fulfillment_print_footer_scripts', array( $this, 'print_script_module_data' ) );
		add_action( 'woocommerce_shiptastic_fulfillment_print_footer_scripts', array( $this, 'print_a11y_script_module_html' ), 20 );
	}
}
