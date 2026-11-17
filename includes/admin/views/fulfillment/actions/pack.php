<?php
/**
 * Pack step: shows the packaging (best fitting packaging suggested, see packing.js) and dimensions to use and verifies
 * the shipment's items. Shipment details and missing items are handled via the shipments overlay.
 */
defined( 'ABSPATH' ) || exit;
?>
<div class="stc-pack-summary">
	<div class="stc-pack-summary-body">
		<span class="stc-label"><?php echo esc_html_x( 'Shipment', 'shipments', 'shiptastic-for-woocommerce' ); ?> #<span data-wp-text="state.view.shipmentId"></span></span>
		<div class="stc-pack-packaging-choice" data-wp-bind--hidden="!state.pack.hasPackaging">
			<label class="stc-field">
				<span class="stc-label">
					<?php echo esc_html_x( 'Packaging', 'shipments', 'shiptastic-for-woocommerce' ); ?>
					<span class="stc-tag is-suggested" data-wp-bind--hidden="!state.pack.isSuggested"><?php echo esc_html_x( 'Suggested', 'shipments-packaging', 'shiptastic-for-woocommerce' ); ?></span>
				</span>
				<select class="stc-input stc-pack-packaging" data-wp-on--change="actions.pack.setPackaging">
					<option value="0"><?php echo esc_html_x( 'No packaging', 'shipments', 'shiptastic-for-woocommerce' ); ?></option>
					<template data-wp-each--option="state.pack.packagingOptions" data-wp-each-key="context.option.id">
						<option data-wp-bind--value="context.option.id" data-wp-bind--selected="context.option.selected" data-wp-class--is-unfit="!context.option.fits" data-wp-text="context.option.label"></option>
					</template>
				</select>
			</label>
			<span class="stc-hint" data-wp-bind--hidden="!state.pack.notice" data-wp-text="state.pack.notice"></span>
			<span class="stc-error-text" data-wp-bind--hidden="!state.pack.warning" data-wp-text="state.pack.warning"></span>
		</div>
		<strong class="stc-pack-packaging is-missing" data-wp-bind--hidden="state.pack.hasPackaging"><?php echo esc_html_x( 'No packaging', 'shipments', 'shiptastic-for-woocommerce' ); ?></strong>
		<span class="stc-pack-dimensions" data-wp-text="state.pack.dimensions"></span>
	</div>
	<button type="button" class="stc-button is-small" data-wp-on--click="actions.shipments.open"><?php echo esc_html_x( 'Change', 'shipments', 'shiptastic-for-woocommerce' ); ?></button>
</div>
<?php echo \Vendidero\Shiptastic\BulkFulfillments\App::render_view( 'partials/check-list', array( 'all_label' => _x( 'Pack all', 'shipments', 'shiptastic-for-woocommerce' ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
