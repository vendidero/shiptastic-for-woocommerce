<?php
/**
 * Customer guest return shipment request (plain text).
 *
 * This template can be overridden by copying it to yourtheme/shiptastic/emails/plain/customer-guest-return-shipment-request.php.
 *
 * HOWEVER, on occasion Shiptastic will need to update template files and you
 * (the theme developer) will need to copy the new files to your theme to
 * maintain compatibility. We try to do this as little as possible, but it does
 * happen. When this occurs the version of the template file will be bumped and
 * the readme will list any important changes.
 *
 * @package Shiptastic/Templates/Emails
 * @version 4.3.0
 */
defined( 'ABSPATH' ) || exit;

echo "=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=\n";
echo esc_html( wp_strip_all_tags( $email_heading ) );
echo "\n=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=\n\n";

/* translators: %s: Customer first name */
echo sprintf( esc_html_x( 'Hi %s,', 'shipments', 'shiptastic-for-woocommerce' ), esc_html( $order->get_billing_first_name() ) ) . "\n\n";

echo sprintf( esc_html_x( 'You\'ve requested a return to your order %s. Please follow the link to add your return request.', 'shipments', 'shiptastic-for-woocommerce' ), esc_html( $order->get_order_number() ) ) . "\n\n";

echo esc_url( $add_return_request_url ) . "\n\n";

/**
 * Show user-defined additional content - this is set in each email's settings.
 */
if ( $additional_content ) {
	echo esc_html( wp_strip_all_tags( wptexturize( $additional_content ) ) );
	echo "\n\n----------------------------------------\n\n";
}

echo "\n----------------------------------------\n\n";

echo wp_kses_post( apply_filters( 'woocommerce_email_footer_text', get_option( 'woocommerce_email_footer_text' ) ) );
