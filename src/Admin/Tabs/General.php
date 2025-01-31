<?php

namespace Vendidero\Germanized\Shipments\Admin\Tabs;

use Vendidero\Germanized\Shipments\Admin\Settings;
use Vendidero\Germanized\Shipments\Admin\Tutorial;

class General extends Tab {

	public function get_description() {
		return _x( 'Configure when and how to create shipments and manage information on your business.', 'shipments', 'woocommerce-germanized-shipments' );
	}

	public function get_label() {
		return _x( 'General', 'shipments', 'woocommerce-germanized-shipments' );
	}

	public function get_name() {
		return 'general';
	}

	public function get_sections() {
		$sections = array(
			''                     => _x( 'General', 'shipments', 'woocommerce-germanized-shipments' ),
			'automation'           => _x( 'Automation', 'shipments', 'woocommerce-germanized-shipments' ),
			'return'               => _x( 'Returns', 'shipments', 'woocommerce-germanized-shipments' ),
			'business_information' => _x( 'Business Information', 'shipments', 'woocommerce-germanized-shipments' ),
		);

		return $sections;
	}

	public function get_section_description( $section ) {
		return '';
	}

	public function get_pointers() {
		$current_section = $this->get_current_section();
		$pointers        = array();

		if ( '' === $current_section ) {
			$next_url = Tutorial::get_tutorial_url( 'general', 'automation' );

			$pointers = array(
				'pointers' => array(
					'default' => array(
						'target'       => '#woocommerce_gzd_shipments_notify_enable-toggle',
						'next'         => '',
						'next_url'     => $next_url,
						'next_trigger' => array(),
						'options'      => array(
							'content'  => '<h3>' . esc_html_x( 'E-Mail Notification', 'shipments', 'woocommerce-germanized-shipments' ) . '</h3><p>' . esc_html_x( 'By enabling this option customers receive an email notification as soon as a shipment is marked as shipped.', 'shipments', 'woocommerce-germanized-shipments' ) . '</p>',
							'position' => array(
								'edge'  => 'left',
								'align' => 'left',
							),
						),
					),
				),
			);
		} elseif ( 'automation' === $current_section ) {
			$next_url = Tutorial::get_tutorial_url( 'general', 'return' );

			$pointers = array(
				'pointers' => array(
					'auto' => array(
						'target'       => '#woocommerce_gzd_shipments_auto_enable-toggle',
						'next'         => '',
						'next_url'     => $next_url,
						'next_trigger' => array(),
						'options'      => array(
							'content'  => '<h3>' . esc_html_x( 'Automation', 'shipments', 'woocommerce-germanized-shipments' ) . '</h3><p>' . esc_html_x( 'Decide whether you want to automatically create shipments to orders reaching a specific status. You can always adjust your shipments by manually editing the shipment within the edit order screen.', 'shipments', 'woocommerce-germanized-shipments' ) . '</p>',
							'position' => array(
								'edge'  => 'left',
								'align' => 'left',
							),
						),
					),
				),
			);
		} elseif ( 'return' === $current_section ) {
			$next_url = Tutorial::get_tutorial_url( 'general', 'business_information' );

			$pointers = array(
				'pointers' => array(
					'returns' => array(
						'target'       => '#shipments_return_options-description',
						'next'         => '',
						'next_url'     => $next_url,
						'next_trigger' => array(),
						'options'      => array(
							'content'  => '<h3>' . esc_html_x( 'Returns', 'shipments', 'woocommerce-germanized-shipments' ) . '</h3><p>' . sprintf( _x( 'Minimize manual work while handling customer returns. Learn more about returns within our %s.', 'shipments', 'woocommerce-germanized-shipments' ), '<a href="https://vendidero.de/doc/woocommerce-germanized/retouren-konfigurieren-und-verwalten" target="_blank">' . _x( 'documentation', 'shipments', 'woocommerce-germanized-shipments' ) . '</a>' ) . '</p>',
							'position' => array(
								'edge'  => 'top',
								'align' => 'top',
							),
						),
					),
				),
			);
		} elseif ( 'business_information' === $current_section ) {
			$next_url = Tutorial::get_tutorial_url( 'shipping_provider' );

			$pointers = array(
				'pointers' => array(
					'returns' => array(
						'target'       => '#woocommerce_gzd_shipments_shipper_address_first_name',
						'next'         => '',
						'next_url'     => $next_url,
						'next_trigger' => array(),
						'options'      => array(
							'content'  => '<h3>' . esc_html_x( 'Shipper Address', 'shipments', 'woocommerce-germanized-shipments' ) . '</h3><p>' . _x( 'Make sure to keep your business information up-to-date as the data will be used within labels and returns.', 'shipments', 'woocommerce-germanized-shipments' ) . '</p>',
							'position' => array(
								'edge'  => 'top',
								'align' => 'top',
							),
						),
					),
				),
			);
		}

		return $pointers;
	}

	public static function get_address_label_by_prop( $prop, $type = 'shipper' ) {
		$label  = '';
		$fields = wc_gzd_get_shipment_setting_default_address_fields( $type );

		if ( array_key_exists( $prop, $fields ) ) {
			$label = $fields[ $prop ];
		}

		return $label;
	}

	protected static function get_address_field_type_by_prop( $prop ) {
		$type = 'text';

		if ( 'country' === $prop ) {
			$type = 'shipments_country_select';
		}

		return $type;
	}

	protected static function get_address_desc_by_prop( $prop ) {
		$desc = false;

		if ( 'customs_reference_number' === $prop ) {
			$desc = _x( 'Your customs reference number, e.g. EORI number', 'shipments', 'woocommerce-germanized-shipments' );
		} elseif ( 'customs_uk_vat_id' === $prop ) {
			$desc = _x( 'Your UK VAT ID, e.g. for UK exports <= 135 GBP.', 'shipments', 'woocommerce-germanized-shipments' );
		}

		return $desc;
	}

	protected static function get_address_fields_to_skip() {
		return array( 'state', 'street', 'street_number', 'full_name' );
	}

	protected function get_business_information_settings() {
		$shipper_fields = wc_gzd_get_shipment_setting_address_fields( 'shipper' );
		$return_fields  = wc_gzd_get_shipment_setting_address_fields( 'return' );

		$settings = array(
			array(
				'title' => _x( 'Shipper Address', 'shipments', 'woocommerce-germanized-shipments' ),
				'type'  => 'title',
				'id'    => 'shipments_shipper_address',
			),
		);

		foreach ( $shipper_fields as $field => $value ) {
			if ( in_array( $field, $this->get_address_fields_to_skip(), true ) ) {
				continue;
			}

			$settings = array_merge(
				$settings,
				array(
					array(
						'title'        => $this->get_address_label_by_prop( $field ),
						'type'         => $this->get_address_field_type_by_prop( $field ),
						'id'           => "woocommerce_gzd_shipments_shipper_address_{$field}",
						'default'      => 'country' === $field ? $value . ':' . $shipper_fields['state'] : $value,
						'desc_tip'     => $this->get_address_desc_by_prop( $field ),
						'skip_install' => true,
					),
				)
			);
		}

		$settings = array_merge(
			$settings,
			array(
				array(
					'type' => 'sectionend',
					'id'   => 'shipments_shipper_address',
				),
				array(
					'title' => _x( 'Return Address', 'shipments', 'woocommerce-germanized-shipments' ),
					'type'  => 'title',
					'id'    => 'shipments_return_address',
				),
				array(
					'title'   => _x( 'Alternate return?', 'shipments', 'woocommerce-germanized-shipments' ),
					'desc'    => _x( 'Optionally configure a separate return address', 'shipments', 'woocommerce-germanized-shipments' ),
					'id'      => 'woocommerce_gzd_shipments_use_alternate_return',
					'default' => ! empty( get_option( 'woocommerce_gzd_shipments_return_address_address_1', '' ) ) ? 'yes' : 'no',
					'type'    => 'gzd_shipments_toggle',
				),
			)
		);

		foreach ( $return_fields as $field => $value ) {
			if ( in_array( $field, $this->get_address_fields_to_skip(), true ) ) {
				continue;
			}

			$settings = array_merge(
				$settings,
				array(
					array(
						'title'             => $this->get_address_label_by_prop( $field ),
						'type'              => $this->get_address_field_type_by_prop( $field ),
						'id'                => "woocommerce_gzd_shipments_return_address_{$field}",
						'default'           => 'country' === $field ? $value . ':' . $return_fields['state'] : $value,
						'desc_tip'          => $this->get_address_desc_by_prop( $field ),
						'skip_install'      => true,
						'custom_attributes' => array(
							'data-show_if_woocommerce_gzd_shipments_use_alternate_return' => '',
						),
					),
				)
			);
		}

		$settings = array_merge(
			$settings,
			array(
				array(
					'type' => 'sectionend',
					'id'   => 'shipments_shipper_address',
				),
			)
		);

		return $settings;
	}

	protected function get_automation_settings() {
		$statuses = array_diff_key( wc_gzd_get_shipment_statuses(), array_flip( array( 'gzd-requested' ) ) );

		$settings = array(
			array(
				'title' => '',
				'type'  => 'title',
				'id'    => 'shipments_auto_options',
			),

			array(
				'title'   => _x( 'Enable', 'shipments', 'woocommerce-germanized-shipments' ),
				'desc'    => _x( 'Automatically create shipments for orders.', 'shipments', 'woocommerce-germanized-shipments' ),
				'id'      => 'woocommerce_gzd_shipments_auto_enable',
				'default' => 'yes',
				'type'    => 'gzd_shipments_toggle',
			),

			array(
				'title'             => _x( 'Order statuses', 'shipments', 'woocommerce-germanized-shipments' ),
				'desc_tip'          => _x( 'Create shipments as soon as the order reaches one of the following status(es).', 'shipments', 'woocommerce-germanized-shipments' ),
				'id'                => 'woocommerce_gzd_shipments_auto_statuses',
				'default'           => array( 'wc-processing', 'wc-on-hold' ),
				'class'             => 'wc-enhanced-select-nostd',
				'options'           => wc_get_order_statuses(),
				'type'              => 'multiselect',
				'custom_attributes' => array(
					'data-show_if_woocommerce_gzd_shipments_auto_enable' => '',
					'data-placeholder' => _x( 'On new order creation', 'shipments', 'woocommerce-germanized-shipments' ),
				),
			),

			array(
				'title'             => _x( 'Default status', 'shipments', 'woocommerce-germanized-shipments' ),
				'desc_tip'          => _x( 'Choose a default status for the automatically created shipment.', 'shipments', 'woocommerce-germanized-shipments' ),
				'id'                => 'woocommerce_gzd_shipments_auto_default_status',
				'default'           => 'gzd-processing',
				'class'             => 'wc-enhanced-select',
				'options'           => $statuses,
				'type'              => 'select',
				'custom_attributes' => array(
					'data-show_if_woocommerce_gzd_shipments_auto_enable' => '',
				),
			),

			array(
				'title'   => _x( 'Update status', 'shipments', 'woocommerce-germanized-shipments' ),
				'desc'    => _x( 'Mark order as completed after order is fully shipped.', 'shipments', 'woocommerce-germanized-shipments' ) . '<div class="wc-gzd-shipments-additional-desc">' . _x( 'This option will automatically update the order status to completed as soon as all required shipments have been marked as shipped.', 'shipments', 'woocommerce-germanized-shipments' ) . '</div>',
				'id'      => 'woocommerce_gzd_shipments_auto_order_shipped_completed_enable',
				'default' => 'yes',
				'type'    => 'gzd_shipments_toggle',
			),

			array(
				'title'   => _x( 'Mark as shipped', 'shipments', 'woocommerce-germanized-shipments' ),
				'desc'    => _x( 'Mark shipments as shipped after order completion.', 'shipments', 'woocommerce-germanized-shipments' ) . '<div class="wc-gzd-shipments-additional-desc">' . _x( 'This option will automatically update contained shipments to shipped (if possible, e.g. not yet delivered) as soon as the order was marked as completed.', 'shipments', 'woocommerce-germanized-shipments' ) . '</div>',
				'id'      => 'woocommerce_gzd_shipments_auto_order_completed_shipped_enable',
				'default' => 'no',
				'type'    => 'gzd_shipments_toggle',
			),

			array(
				'type' => 'sectionend',
				'id'   => 'shipments_auto_options',
			),
		);

		return $settings;
	}

	protected function get_return_settings() {
		$settings = array(
			array(
				'title' => '',
				'type'  => 'title',
				'id'    => 'shipments_return_options',
				'desc'  => sprintf( _x( 'Returns can be added manually by the shop manager or by the customer. Decide what suits you best by turning customer-added returns on or off in your %s.', 'shipments', 'woocommerce-germanized-shipments' ), '<a href="' . esc_url( Settings::get_settings_url( 'shipping_provider' ) ) . '">' . _x( 'shipping provider settings', 'shipments', 'woocommerce-germanized-shipments' ) . '</a>' ),
			),

			array(
				'type' => 'shipment_return_reasons',
			),

			array(
				'title'   => _x( 'Days to return', 'shipments', 'woocommerce-germanized-shipments' ),
				'desc'    => '<div class="wc-gzd-shipments-additional-desc">' . sprintf( _x( 'In case one of your %s supports returns added by customers you might want to limit the number of days a customer is allowed to add returns to an order. The days are counted starting with the date the order was shipped, completed or created (by checking for existance in this order).', 'shipments', 'woocommerce-germanized-shipments' ), '<a href="' . esc_url( Settings::get_settings_url( 'shipping_provider' ) ) . '">' . _x( 'shipping providers', 'shipments', 'woocommerce-germanized-shipments' ) . '</a>' ) . '</div>',
				'css'     => 'max-width: 60px;',
				'type'    => 'number',
				'id'      => 'woocommerce_gzd_shipments_customer_return_open_days',
				'default' => '14',
			),

			array(
				'type' => 'sectionend',
				'id'   => 'shipments_return_options',
			),
		);

		return $settings;
	}

	protected function get_general_settings() {
		$settings = array(
			array(
				'title' => '',
				'type'  => 'title',
				'id'    => 'shipments_options',
			),

			array(
				'title'   => _x( 'Notification', 'shipments', 'woocommerce-germanized-shipments' ),
				'desc'    => _x( 'Send shipping notification to customers.', 'shipments', 'woocommerce-germanized-shipments' ) . '<div class="wc-gzd-shipments-additional-desc">' . sprintf( _x( 'Notify customers by email as soon as a shipment is marked as shipped. %s the notification email.', 'shipments', 'woocommerce-germanized-shipments' ), '<a href="' . esc_url( admin_url( 'admin.php?page=wc-settings&tab=email&section=wc_gzd_email_customer_shipment' ) ) . '" target="_blank">' . _x( 'Manage', 'shipments notification', 'woocommerce-germanized-shipments' ) . '</a>' ) . '</div>',
				'id'      => 'woocommerce_gzd_shipments_notify_enable',
				'default' => 'yes',
				'type'    => 'gzd_shipments_toggle',
			),

			array(
				'title'    => _x( 'Default provider', 'shipments', 'woocommerce-germanized-shipments' ),
				'desc_tip' => _x( 'Select a default shipping provider which will be selected by default in case no provider could be determined automatically.', 'shipments', 'woocommerce-germanized-shipments' ),
				'id'       => 'woocommerce_gzd_shipments_default_shipping_provider',
				'default'  => '',
				'type'     => 'select',
				'options'  => wc_gzd_get_shipping_provider_select(),
				'class'    => 'wc-enhanced-select',
			),

			array(
				'title'   => _x( 'Customer Account', 'shipments', 'woocommerce-germanized-shipments' ),
				'desc'    => _x( 'List shipments and return options, if available, within customer account.', 'shipments', 'woocommerce-germanized-shipments' ),
				'id'      => 'woocommerce_gzd_shipments_customer_account_enable',
				'default' => 'yes',
				'type'    => 'gzd_shipments_toggle',
			),

			array(
				'type' => 'sectionend',
				'id'   => 'shipments_options',
			),
		);

		return $settings;
	}

	public function get_tab_settings( $current_section = '' ) {
		$settings = array();

		if ( '' === $current_section ) {
			$settings = $this->get_general_settings();
		} elseif ( 'automation' === $current_section ) {
			$settings = $this->get_automation_settings();
		} elseif ( 'return' === $current_section ) {
			$settings = $this->get_return_settings();
		} elseif ( 'business_information' === $current_section ) {
			$settings = $this->get_business_information_settings();
		}

		return $settings;
	}
}
