<?php
/**
 * Generic review screen of background capable actions running in interactive mode (chosen per workflow step):
 * describes what happens, the action runs once confirmed (continue).
 */
defined( 'ABSPATH' ) || exit;
?>
<div class="stc-card stc-confirm-step">
	<span class="stc-label" data-wp-bind--hidden="!state.view.shipmentId"><?php echo esc_html_x( 'Shipment', 'shipments', 'shiptastic-for-woocommerce' ); ?> #<span data-wp-text="state.view.shipmentId"></span></span>
	<p data-wp-text="state.view.description"></p>
	<button type="button" class="stc-button" data-wp-on--click="actions.shipments.open" data-wp-bind--hidden="!state.hasLayout"><?php echo esc_html_x( 'Edit shipments', 'shipments', 'shiptastic-for-woocommerce' ); ?></button>
</div>
