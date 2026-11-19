<?php
/**
 * Generic review screen of background capable actions running in interactive mode (chosen per workflow step):
 * describes what happens, the action runs once confirmed (continue).
 */
defined( 'ABSPATH' ) || exit;
?>
<div class="stc-pack-summary stc-confirm-step">
	<?php /* Same compact header as the pack step: shipment (per shipment steps) + link to the shipments overlay. */ ?>
	<div class="stc-pack-summary-header">
		<span class="stc-pack-summary-title" data-wp-bind--hidden="!state.currentShipmentPosition">
			<strong data-wp-text="state.currentShipmentPosition"></strong>
			<span class="stc-pack-summary-meta" data-wp-text="state.currentShipmentNumber"></span>
		</span>
		<button type="button" class="stc-link-button" data-wp-on--click="actions.shipments.open" data-wp-bind--hidden="!state.hasLayout"><?php echo esc_html_x( 'Edit shipments', 'shipments', 'shiptastic-for-woocommerce' ); ?></button>
	</div>
	<p data-wp-text="state.view.description"></p>
</div>
