<?php

namespace Vendidero\Shiptastic\BulkFulfillments;

defined( 'ABSPATH' ) || exit;

class FulfillmentScripts extends \WP_Scripts {

	/**
	 * Initialize the class.
	 *
	 * @since 3.4.0
	 */
	public function init() {
		/**
		 * Fires when the WP_Scripts instance is initialized.
		 *
		 * @param FulfillmentScripts $stc_fulfillment_scripts WP_Scripts instance (passed by reference).
		 */
		do_action_ref_array( 'woocommerce_shiptastic_fulfillment_default_scripts', array( &$this ) );
	}
}
