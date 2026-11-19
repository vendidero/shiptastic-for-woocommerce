<?php
/**
 * Create label step (interactive): review of the label (shipping provider defaults: product, services, weight,
 * dimensions, packaging) + create. The whole label form of the provider can be opened within the app on request.
 * Existing labels are offered for printing.
 */
defined( 'ABSPATH' ) || exit;
?>
<div class="stc-pack-summary stc-label-step">
	<div class="stc-pack-summary-header">
		<span class="stc-pack-summary-title">
			<strong data-wp-bind--hidden="!state.currentShipmentPosition" data-wp-text="state.currentShipmentPosition"></strong>
			<span class="stc-pack-summary-meta"><span data-wp-text="state.currentShipmentNumber"></span><span data-wp-bind--hidden="!state.view.provider"> · <span data-wp-text="state.view.provider"></span></span></span>
		</span>
		<button type="button" class="stc-link-button" data-wp-on--click="actions.shipments.open" data-wp-bind--hidden="!state.hasLayout"><?php echo esc_html_x( 'Edit shipments', 'shipments', 'shiptastic-for-woocommerce' ); ?></button>
	</div>

	<?php /* The shipment already has a label (e.g. created in the admin): print it, continue. */ ?>
	<div class="stc-label-existing" data-wp-bind--hidden="!state.view.hasLabel">
		<span>
			<?php echo esc_html_x( 'The label has already been created.', 'shipments', 'shiptastic-for-woocommerce' ); ?>
			<span class="stc-hint" data-wp-bind--hidden="!state.view.label.tracking_id" data-wp-text="state.view.label.tracking_id"></span>
		</span>
		<a class="stc-button is-small" target="_blank" rel="noopener" data-wp-bind--href="state.view.label.url"><?php echo esc_html_x( 'Print label', 'shipments', 'shiptastic-for-woocommerce' ); ?></a>
	</div>

	<p class="stc-hint" data-wp-bind--hidden="!state.view.notice" data-wp-text="state.view.notice"></p>

	<?php /* Review: the provider's defaults (product, options, services) + the package. */ ?>
	<div class="stc-label-overview" data-wp-bind--hidden="!state.createLabel.showOverview">
		<div class="stc-label-product" data-wp-bind--hidden="!state.view.product">
			<strong data-wp-text="state.view.product"></strong>
			<span class="stc-hint" data-wp-bind--hidden="!state.createLabel.optionsText" data-wp-text="state.createLabel.optionsText"></span>
		</div>
		<div class="stc-label-services" data-wp-bind--hidden="!state.view.canEdit">
			<span class="stc-label-caption"><?php echo esc_html_x( 'Services', 'shipments', 'shiptastic-for-woocommerce' ); ?></span>
			<ul class="stc-label-service-list" data-wp-bind--hidden="!state.view.services.length">
				<template data-wp-each--service="state.view.services">
					<li class="stc-tag" data-wp-text="context.service"></li>
				</template>
			</ul>
			<span class="stc-hint" data-wp-bind--hidden="state.view.services.length"><?php echo esc_html_x( 'No additional services', 'shipments', 'shiptastic-for-woocommerce' ); ?></span>
		</div>
		<dl class="stc-label-package">
			<template data-wp-each--fact="state.view.package" data-wp-each-key="context.fact.label">
				<div>
					<dt class="stc-label-caption" data-wp-text="context.fact.label"></dt>
					<dd>
						<strong data-wp-text="context.fact.value"></strong>
						<span class="stc-hint" data-wp-bind--hidden="!context.fact.hint" data-wp-text="context.fact.hint"></span>
					</dd>
				</div>
			</template>
		</dl>
	</div>

	<div class="stc-label-form-toggle" data-wp-bind--hidden="!state.view.canEdit">
		<button type="button" class="stc-link-button" data-wp-on--click="actions.createLabel.openForm" data-wp-bind--hidden="state.form.editing"><?php echo esc_html_x( 'Edit label settings', 'shipments', 'shiptastic-for-woocommerce' ); ?></button>
		<button type="button" class="stc-link-button" data-wp-on--click="actions.createLabel.closeForm" data-wp-bind--hidden="!state.form.editing"><?php echo esc_html_x( 'Use default settings', 'shipments', 'shiptastic-for-woocommerce' ); ?></button>
	</div>

	<?php /* The provider's label form (same fields as the label modal of the shipment admin), loaded on request. */ ?>
	<p class="stc-hint" data-wp-bind--hidden="!state.form.loading"><?php echo esc_html_x( 'Loading label settings…', 'shipments', 'shiptastic-for-woocommerce' ); ?></p>
	<div class="stc-label-form" data-wp-bind--hidden="!state.createLabel.showForm" data-wp-watch="callbacks.renderLabelForm"></div>
</div>
