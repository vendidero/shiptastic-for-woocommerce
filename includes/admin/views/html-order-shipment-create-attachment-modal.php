<?php
/**
 * Shipment attachment modal.
 */
defined( 'ABSPATH' ) || exit;

/**
 * @var \Vendidero\Shiptastic\Shipment $shipment
 * @var string $attachment_type
 */
?>
<script type="text/template" id="tmpl-wc-stc-create-attachment-modal-<?php echo esc_attr( $attachment_type ); ?>-<?php echo esc_attr( $shipment->get_id() ); ?>" class="wc-stc-create-attachment-modal-<?php echo esc_attr( $attachment_type ); ?>">
	<div class="wc-backbone-modal wc-stc-admin-shipment-modal wc-stc-create-attachment-modal wc-stc-create-attachment-modal-<?php echo esc_attr( $attachment_type ); ?>">
		<div class="wc-backbone-modal-content">
			<section class="wc-backbone-modal-main" role="main">
				<header class="wc-backbone-modal-header">
					<h1><?php echo esc_html( sprintf( esc_html_x( 'Create %1$s', 'shipments-attachment', 'shiptastic-for-woocommerce' ), wc_stc_get_shipment_attachment_type_name( $attachment_type, $shipment->get_type() ) ) ); ?></h1>
					<button class="modal-close modal-close-link dashicons dashicons-no-alt">
						<span class="screen-reader-text">Close modal panel</span>
					</button>
				</header>
				<article class="shiptastic-shipments shiptastic-attachment-modal-<?php echo esc_attr( $attachment_type ); ?>" data-shipment-type="<?php echo esc_attr( $shipment->get_type() ); ?>">
					<div class="notice-wrapper"></div>

					<form action="" method="post" class="wc-stc-shipment-create-attachment-modal-form">
						<div class="wc-stc-shipment-create-attachment-modal"></div>
					</form>
				</article>
				<footer>
					<div class="inner">
						<button id="btn-ok" class="button button-primary button-large"><?php echo esc_html_x( 'Create', 'shipments-attachment', 'shiptastic-for-woocommerce' ); ?></button>
					</div>
				</footer>
			</section>
		</div>
	</div>
	<div class="wc-backbone-modal-backdrop modal-close"></div>
</script>
