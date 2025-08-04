<?php
defined( 'ABSPATH' ) || exit;
?>

<div class="wc-shiptastic-wizard-entry">
	<h1><?php echo esc_html_x( 'Setup Shipping Service Providers', 'shipments', 'shiptastic-for-woocommerce' ); ?></h1>
</div>

<div class="wc-shiptastic-wizard-inner-content">
	<p class="entry-desc"><?php echo esc_html_x( 'Shiptastic allows you to integrate with popular shipping service providers out of the box. In case there is no official integration available, you may manually add your shipping service provider later.', 'shipments', 'shiptastic-for-woocommerce' ); ?></p>

	<div class="error-wrapper"></div>

	<table class="wc-shiptastic-wizard-settings">
		<tbody>
		</tbody>
	</table>

	<div class="wc-shiptastic-wizard-links">
		<button class="button button-primary button-submit" type="submit"><?php echo esc_attr_x( 'Continue', 'shipments-wizard', 'shiptastic-for-woocommerce' ); ?></button>
	</div>
</div>

