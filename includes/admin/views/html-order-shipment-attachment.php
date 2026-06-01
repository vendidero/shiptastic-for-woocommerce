<?php
/**
 * Order shipments HTML for meta box.
 *
 * @package WooCommerce_Shiptastic/Admin
 */

use Vendidero\Shiptastic\Shipment;
use Vendidero\Shiptastic\Admin\Admin;

defined( 'ABSPATH' ) || exit;

$type_data  = wc_stc_get_shipment_attachment_type_data( $attachment_type, $shipment->get_type() );
$attachment = $shipment->get_attachment( $attachment_type );
$actions    = wc_stc_get_shipment_attachment_actions( $shipment, $attachment_type );
?>
<div class="wc-stc-shipment-attachment wc-stc-shipment-attachment-<?php echo esc_attr( $attachment_type ); ?> wc-stc-shipment-action-wrapper <?php echo esc_attr( array_key_exists( 'upload', $actions ) ? 'wc-stc-shipment-action-upload-drop' : '' ); ?> column column-spaced col-auto" data-attachment-type="<?php echo esc_attr( $attachment_type ); ?>">
	<h4><?php echo esc_html( $attachment ? $attachment->get_title() : wc_stc_get_shipment_attachment_type_name( $type_data ) ); ?></h4>

	<div class="wc-stc-shipment-attachment-content">
		<div class="shipment-inner-actions shipment-inner-actions-attachment">
			<div class="shipment-attachment-actions shipment-inner-actions-wrapper">
				<?php echo wc_stc_render_shipment_action_buttons( $actions ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>

				<?php do_action( 'woocommerce_shiptastic_shipment_after_attachment_actions', $actions, $attachment_type, $shipment, $attachment ); ?>
			</div>
		</div>
	</div>
</div>


