<?php
defined( 'ABSPATH' ) || exit;
?>

<div class="wc-shiptastic-wizard-entry">
	<h1><?php echo esc_html_x( 'Manage your packaging options', 'shipments', 'shiptastic-for-woocommerce' ); ?></h1>
</div>

<div class="wc-shiptastic-wizard-inner-content">
	<p class="entry-desc"><?php echo esc_html_x( 'Packaging is an important concept in Shiptastic. Based on your packaging options, Shiptastic automatically determines which items fit into which packaging. Please make sure that your products have valid dimensions and weights too.', 'shipments', 'shiptastic-for-woocommerce' ); ?></p>

	<div class="wc-shiptastic-wizard-config">
		<div class="wc-shiptastic-error-wrapper"></div>

		<table class="wc-shiptastic-wizard-settings form-table">
			<tbody>
			<?php WC_Admin_Settings::output_fields( \Vendidero\Shiptastic\Admin\Setup\Wizard::get_settings( 'packaging' ) ); ?>
			</tbody>
		</table>
	</div>

	<div class="wc-shiptastic-wizard-links">
		<button class="button button-primary button-submit" type="submit"><?php echo esc_attr_x( 'Continue', 'shipments-wizard', 'shiptastic-for-woocommerce' ); ?></button>
	</div>
</div>

