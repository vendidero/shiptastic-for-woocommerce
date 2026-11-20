<?php
/**
 * Document step (uploads within Shiptastic, document creation with Shiptastic Pro): the documents needed, see
 * partials/document-uploads.php.
 */
defined( 'ABSPATH' ) || exit;
?>
<div class="stc-pack-summary stc-documents-step">
	<div class="stc-pack-summary-header">
		<span class="stc-pack-summary-title">
			<strong data-wp-bind--hidden="!state.currentShipmentPosition" data-wp-text="state.currentShipmentPosition"></strong>
			<span class="stc-pack-summary-meta" data-wp-bind--hidden="!state.view.shipmentId" data-wp-text="state.currentShipmentNumber"></span>
			<span class="stc-pack-summary-meta" data-wp-bind--hidden="state.view.shipmentId" data-wp-text="state.view.description"></span>
		</span>
		<button type="button" class="stc-link-button" data-wp-on--click="actions.shipments.open" data-wp-bind--hidden="!state.hasLayout"><?php echo esc_html_x( 'Edit shipments', 'shipments', 'shiptastic-for-woocommerce' ); ?></button>
	</div>
	<?php echo \Vendidero\Shiptastic\BulkFulfillments\App::render_view( 'partials/document-uploads' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
</div>
