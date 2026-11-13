<?php
/**
 * Pack step: shows the packaging and dimensions to use and verifies the shipment's items. Shipment details and
 * missing items are handled via the shipments overlay.
 */
defined( 'ABSPATH' ) || exit;
?>
<div class="stc-pack-summary">
	<div class="stc-pack-summary-body">
		<span class="stc-label"><?php echo esc_html_x( 'Shipment', 'shipments', 'shiptastic-for-woocommerce' ); ?> #<span data-wp-text="state.view.shipmentId"></span></span>
		<strong class="stc-pack-packaging" data-wp-bind--hidden="!state.view.packaging" data-wp-text="state.view.packaging"></strong>
		<strong class="stc-pack-packaging is-missing" data-wp-bind--hidden="state.view.packaging"><?php echo esc_html_x( 'No packaging', 'shipments', 'shiptastic-for-woocommerce' ); ?></strong>
		<span class="stc-pack-dimensions" data-wp-text="state.view.dimensions"></span>
	</div>
	<button type="button" class="stc-button is-small" data-wp-on--click="actions.shipments.open"><?php echo esc_html_x( 'Change', 'shipments', 'shiptastic-for-woocommerce' ); ?></button>
</div>
<?php echo \Vendidero\Shiptastic\BulkFulfillments\App::render_view( 'partials/check-list', array( 'all_label' => _x( 'Pack all', 'shipments', 'shiptastic-for-woocommerce' ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
