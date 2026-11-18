<?php

namespace Vendidero\Shiptastic\BulkFulfillments;

defined( 'ABSPATH' ) || exit;

class FulfillmentScriptModules extends \WP_Script_Modules {

	/**
	 * Prints the import map incl. versioned URLs of all modules of the app: modules import each other relatively
	 * (e.g. `./countries.js`), those URLs carry no version. Import maps support URL keys, so each module URL is mapped
	 * to its versioned URL (file modification time) - browsers load changed modules instead of cached copies.
	 */
	public function print_import_map() {
		$import_map = array( 'imports' => array() );

		try {
			$method = new \ReflectionMethod( \WP_Script_Modules::class, 'get_import_map' );
			$method->setAccessible( true );
			$import_map = (array) $method->invoke( $this );
		} catch ( \ReflectionException $e ) {
			parent::print_import_map();
			return;
		}

		$import_map['imports'] = array_merge( isset( $import_map['imports'] ) ? (array) $import_map['imports'] : array(), App::get_versioned_module_urls() );

		if ( ! empty( $import_map['imports'] ) ) {
			wp_print_inline_script_tag(
				(string) wp_json_encode( $import_map, JSON_HEX_TAG | JSON_UNESCAPED_SLASHES ),
				array(
					'type' => 'importmap',
					'id'   => 'wp-importmap',
				)
			);
		}
	}

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
