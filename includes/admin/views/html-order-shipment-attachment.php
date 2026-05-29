<?php
/**
 * Order shipments HTML for meta box.
 *
 * @package WooCommerce_Shiptastic/Admin
 */

use Vendidero\Shiptastic\Shipment;
use Vendidero\Shiptastic\Admin\Admin;

defined( 'ABSPATH' ) || exit;

$type_data    = wc_stc_get_shipment_attachment_type_data( $attachment_type, $shipment->get_type() );
$attachment   = $shipment->get_attachment( $attachment_type );
$needs_upload = false;
$has_modal    = false;
$actions      = wc_stc_get_shipment_attachment_actions( $shipment, $attachment_type );
$has_upload   = array_key_exists( 'upload', $actions );
$modal_key    = array_key_exists( 'create', $actions ) ? 'create' : 'refresh';
$has_modal    = array_key_exists( $modal_key, $actions ) && isset( $actions[ $modal_key ]['has_modal'] );
?>
<div class="wc-stc-shipment-attachment wc-stc-shipment-attachment-<?php echo esc_attr( $attachment_type ); ?> wc-stc-shipment-action-wrapper <?php echo esc_attr( $has_upload ? 'wc-stc-shipment-action-upload-drop' : '' ); ?> column column-spaced col-auto" data-attachment-type="<?php echo esc_attr( $attachment_type ); ?>">
	<h4><?php echo esc_html( $attachment ? $attachment->get_title() : wc_stc_get_shipment_attachment_type_name( $type_data ) ); ?></h4>

	<div class="wc-stc-shipment-attachment-content">
		<div class="shipment-inner-actions shipment-inner-actions-attachment">
			<div class="shipment-attachment-actions shipment-inner-actions-wrapper">
				<?php echo wc_stc_render_shipment_action_buttons( $actions ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>

				<?php if ( $has_upload ) : ?>
					<input type="file" id="upload_attachment_<?php echo esc_attr( $attachment_type ); ?>_<?php echo esc_attr( $shipment->get_id() ); ?>" class="wc-stc-shipment-upload-attachment hide-default" name="shipment_attachments[<?php echo esc_attr( $shipment->get_id() ); ?>][<?php echo esc_attr( $attachment_type ); ?>]" accept="<?php echo esc_attr( implode( ', ', $type_data['mime_types'] ) ); ?>" />
				<?php endif; ?>

				<?php if ( $has_modal ) : ?>
					<?php include 'html-order-shipment-create-attachment-modal.php'; ?>
				<?php endif; ?>

				<?php do_action( 'woocommerce_shiptastic_shipment_after_attachment_actions', $attachment_type, $shipment, $attachment ); ?>
			</div>
		</div>
	</div>
</div>


