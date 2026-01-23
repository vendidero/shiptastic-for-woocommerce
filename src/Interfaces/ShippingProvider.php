<?php
namespace Vendidero\Shiptastic\Interfaces;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

interface ShippingProvider {

	/**
	 * Return the unique identifier for the label
	 *
	 * @return mixed
	 */
	public function get_id();

	/**
	 * @return string
	 */
	public function get_help_link();

	/**
	 * @return array
	 */
	public function get_supported_features();

	/**
	 * @return string
	 */
	public function get_icon();

	/**
	 * @return string
	 */
	public function get_section_help_link( $section );

	/**
	 * @return string
	 */
	public function get_signup_link();

	/**
	 * @return bool
	 */
	public function is_pro();

	/**
	 * @return array
	 */
	public function get_shipping_from_countries();

	/**
	 * @return array
	 */
	public function get_shipping_to_countries();

	/**
	 * @return bool
	 */
	public function is_base_country_supported();

	/**
	 * Whether or not this instance is a manual integration.
	 * Manual integrations are constructed dynamically from DB and do not support
	 * automatic shipment handling, e.g. label creation.
	 *
	 * @return bool
	 */
	public function is_manual_integration();

	/**
	 * @return bool
	 */
	public function supports_customer_return_requests();

	/**
	 * @return bool
	 */
	public function supports_labels( $label_type, $shipment = false );

	/**
	 * @return bool
	 */
	public function hide_return_address();

	/**
	 * @return string
	 */
	public function get_edit_link( $section = '' );

	/**
	 * @return bool
	 */
	public function is_activated();

	/**
	 * @return bool
	 */
	public function needs_manual_confirmation_for_returns();

	/**
	 * @return bool
	 */
	public function supports_customer_returns( $order = false );

	/**
	 * @return bool
	 */
	public function supports_guest_returns();

	/**
	 * @return string
	 */
	public function get_title( $context = 'view' );

	/**
	 * @return string
	 */
	public function get_name( $context = 'view' );

	/**
	 * @return string
	 */
	public function get_original_name( $context = 'view' );

	/**
	 * @return string
	 */
	public function get_description( $context = 'view' );

	/**
	 * @return bool
	 */
	public function has_return_instructions();

	public function activate();

	public function deactivate();

	/**
	 * @return string
	 */
	public function get_tracking_url( $shipment );

	/**
	 * @return string
	 */
	public function get_tracking_desc( $shipment, $plain = false );

	/**
	 * @return array
	 */
	public function get_tracking_placeholders( $shipment = false );

	public function get_setting( $key, $default_value = null );

	public function update_settings( $section = '', $data = null, $save = true );

	public function get_settings( $section = '' );

	public function update_setting( $setting, $value );

	public function get_setting_sections();

	public function get_shipping_method_settings();

	public function save();

	/**
	 * @param \Vendidero\Shiptastic\Shipment $shipment
	 *
	 * @return mixed|void
	 */
	public function get_label( $shipment );

	/**
	 * @param \Vendidero\Shiptastic\Shipment $shipment
	 */
	public function get_label_fields_html( $shipment );

	public function create_label( $shipment, $props = false );
}
