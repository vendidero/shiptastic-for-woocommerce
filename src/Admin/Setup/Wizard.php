<?php

namespace Vendidero\Shiptastic\Admin\Setup;

use Vendidero\Shiptastic\Admin\Admin;
use Vendidero\Shiptastic\Package;

defined( 'ABSPATH' ) || exit;

class Wizard {

	/**
	 * Current step
	 *
	 * @var string
	 */
	private static $current_step = '';

	/**
	 * Steps for the setup wizard
	 *
	 * @var array
	 */
	private static $steps = null;

	public static function init() {
		if ( current_user_can( 'manage_options' ) ) {
			add_action( 'admin_menu', array( __CLASS__, 'admin_menus' ), 20 );
			add_action( 'admin_init', array( __CLASS__, 'render' ), 20 );

			// Load after base has registered scripts
			add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_scripts' ), 15 );

			add_action( 'wp_ajax_woocommerce_stc_next_wizard_step', array( 'Vendidero\Shiptastic\Ajax', 'suppress_errors' ), 5 );
			add_action( 'wp_ajax_woocommerce_stc_next_wizard_step', array( __CLASS__, 'save' ) );
		}
	}

	public static function get_steps() {
		if ( is_null( self::$steps ) ) {
			self::setup();
		}

		return self::$steps;
	}

	protected static function setup() {
		$default_steps = array(
			'welcome'           => array(
				'name'     => _x( 'Welcome', 'shipments', 'shiptastic-for-woocommerce' ),
				'order'    => 1,
				'settings' => function () {
					$fields                   = wc_stc_get_shipment_setting_address_fields();
					$default_fields           = wc_stc_get_shipment_setting_default_address_fields();
					$settings                 = array();
					$address_fields_to_render = array(
						'company',
						'address_1',
						'address_2',
						'city',
						'postcode',
						'country',
					);

					foreach ( $address_fields_to_render as $field ) {
						$settings[] = array(
							'title'     => $default_fields[ $field ],
							'type'      => 'country' === $field ? 'shipments_country_select' : 'text',
							'id'        => "woocommerce_shiptastic_shipper_address_{$field}",
							'value'     => 'country' === $field ? $fields[ $field ] . ':' . $fields['state'] : $fields[ $field ],
							'default'   => 'country' === $field ? $fields[ $field ] . ':' . $fields['state'] : $fields[ $field ],
							'row_class' => in_array( $field, array( 'city', 'postcode' ), true ) ? 'half' : '',
						);
					}

					return $settings;
				},
			),
			'packaging'         => array(
				'name'     => _x( 'Packaging', 'shipments', 'shiptastic-for-woocommerce' ),
				'order'    => 10,
				'settings' => function () {
					return array(
						array(
							'type' => 'packaging_list',
						),
					);
				},
				'handler'  => function () {
					Admin::save_packaging_list( array(), '' );
				},
			),
			'shipping_provider' => array(
				'name'     => _x( 'Shipping Service Provider', 'shipments', 'shiptastic-for-woocommerce' ),
				'order'    => 20,
				'settings' => function () {
					return array();
				},
			),
		);

		self::$steps = $default_steps;
		uasort( self::$steps, array( __CLASS__, 'uasort_callback' ) );

		$order = 0;

		foreach ( self::$steps as $key => $step ) {
			self::$steps[ $key ] = wp_parse_args(
				self::$steps[ $key ],
				array(
					'id'               => $key,
					'view'             => $key . '.php',
					'button_next'      => _x( 'Continue', 'shipments-wizard', 'shiptastic-for-woocommerce' ),
					'button_next_link' => '',
					'settings'         => null,
					'handler'          => null,
				)
			);

			self::$steps[ $key ]['order'] = ++$order;
		}

		self::$current_step = isset( $_REQUEST['step'] ) ? sanitize_key( wp_unslash( $_REQUEST['step'] ) ) : current( array_keys( self::$steps ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	}

	protected static function uasort_callback( $step1, $step2 ) {
		if ( $step1['order'] === $step2['order'] ) {
			return 0;
		}

		return ( $step1['order'] < $step2['order'] ) ? -1 : 1;
	}

	/**
	 * Add admin menus/screens.
	 */
	public static function admin_menus() {
		add_submenu_page( '', _x( 'Setup', 'shipments', 'shiptastic-for-woocommerce' ), _x( 'Setup', 'shipments', 'shiptastic-for-woocommerce' ), 'manage_options', 'wc-shiptastic-setup' );
	}

	/**
	 * Register/enqueue scripts and styles for the Setup Wizard.
	 *
	 * Hooked onto 'admin_enqueue_scripts'.
	 */
	public static function enqueue_scripts() {
		if ( self::is_active() ) {
			wp_register_style( 'woocommerce_shiptastic_wizard', Package::get_assets_url( 'static/admin-wizard-styles.css' ), array( 'wp-admin', 'dashicons', 'buttons', 'woocommerce_shiptastic_admin' ), Package::get_version() );
			wp_enqueue_style( 'woocommerce_shiptastic_wizard' );

			wp_register_script( 'wc-shiptastic-admin-wizard', Package::get_assets_url( 'static/admin-wizard.js' ), array( 'wc-shiptastic-admin', 'wc-shiptastic-admin-settings' ), Package::get_version() ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.NotInFooter
			wp_enqueue_script( 'wc-shiptastic-admin-wizard' );

			wp_localize_script(
				'wc-shiptastic-admin-wizard',
				'wc_shiptastic_admin_wizard_params',
				array(
					'ajax_url' => admin_url( 'admin-ajax.php' ),
				)
			);
		}
	}

	private static function is_active() {
		return ( isset( $_GET['page'] ) && 'wc-shiptastic-setup' === wc_clean( wp_unslash( $_GET['page'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	}

	public function get_error_message( $step = false ) {
		if ( isset( $_GET['error'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$error_key    = sanitize_key( wp_unslash( $_GET['error'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$current_step = $this->get_current_step( $step );

			if ( isset( $current_step['errors'][ $error_key ] ) ) {
				return $current_step['errors'][ $error_key ];
			}
		}

		return false;
	}

	/**
	 * Show the setup wizard.
	 */
	public static function render() {
		if ( ! self::is_active() ) {
			return;
		}

		if ( ! $current_step = self::get_current_step() ) {
			return;
		}

		$steps    = self::get_steps();
		$step_pct = ceil( $current_step['order'] / count( $steps ) * 100 );

		set_current_screen( 'wc-shiptastic-setup' );

		ob_start();
		?>
		<!DOCTYPE html>
		<html <?php language_attributes(); ?>>
		<head>
			<meta name="viewport" content="width=device-width" />
			<meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
			<title><?php echo esc_html_x( 'Shiptastic &rsaquo; Setup Wizard', 'shipments', 'shiptastic-for-woocommerce' ); ?></title>
			<?php do_action( 'admin_enqueue_scripts' ); ?>
			<?php do_action( 'admin_print_styles' ); ?>
			<?php do_action( 'admin_print_scripts' ); ?>
			<?php do_action( 'admin_head' ); ?>
		</head>
		<body class="wc-shiptastic-wizard wp-core-ui wc-shiptastic-wizard-step-<?php echo esc_attr( self::$current_step ); ?>">
			<div class="wc-shiptastic-wizard-header">
				<div class="wc-shiptastic-wizard-progress-bar">
					<div class="wc-shiptastic-wizard-progress-bar-container">
						<div class="wc-shiptastic-wizard-progress-bar-filler" style="width: <?php echo esc_attr( $step_pct ); ?>%;"></div>
					</div>
				</div>
				<div class="wc-shiptastic-wizard-header-nav">
					<div class="wc-shiptastic-wizard-logo">
						<span class="shiptastic-logo">
							<?php include Package::get_path( 'assets/logo.svg' ); ?>
						</span>
					</div>
					<?php if ( $current_step['order'] < count( $steps ) ) : ?>
						<a class="wc-shiptastic-wizard-link wc-shiptastic-wizard-link-skip" href="<?php echo esc_url( wp_nonce_url( add_query_arg( array( 'skip' => esc_attr( $current_step['id'] ) ), self::get_step_url( self::get_next_step() ) ), 'wc-shiptastic-wizard-skip' ) ); ?>"><?php echo esc_html_x( 'Skip Step', 'shipments', 'shiptastic-for-woocommerce' ); ?></a>
					<?php endif; ?>
				</div>
			</div>
			<form class="wc-shiptastic-wizard-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<div class="wc-shiptastic-wizard-content">
					<?php self::view( $current_step['id'] ); ?>

					<input type="hidden" name="action" value="woocommerce_stc_next_wizard_step" />
					<input type="hidden" name="step" value="<?php echo esc_attr( $current_step['id'] ); ?>" />

					<?php wp_nonce_field( 'wc-shiptastic-wizard' ); ?>
				</div>
			</form>
			<div class="wc-shiptastic-wizard-footer">
				<div class="escape">
					<a href="<?php echo esc_url( admin_url() ); ?>"><?php echo esc_html_x( 'Return to Dashboard', 'shipments', 'shiptastic-for-woocommerce' ); ?></a>
				</div>
			</div>
			<?php do_action( 'admin_footer', '' ); ?>
			<?php do_action( 'admin_print_footer_scripts' ); ?>
		</body>
		</html>
		<?php
		exit;
	}

	protected static function view( $step_key ) {
		if ( $step = self::get_step( $step_key ) ) {
			$view_file = str_replace( '_', '-', sanitize_file_name( $step['view'] ) );

			if ( file_exists( Package::get_path( 'includes/admin/views/wizard/' . $view_file ) ) ) {
				include Package::get_path( 'includes/admin/views/wizard/' . $view_file );
			}
		}
	}

	public static function get_settings( $key ) {
		$settings = array();

		if ( ! $step = self::get_step( $key ) ) {
			return $settings;
		}

		$settings = is_null( $step['settings'] ) ? array() : call_user_func( $step['settings'] );

		return (array) $settings;
	}

	public static function has_settings( $key ) {
		$settings = self::get_settings( $key );

		return ! empty( $settings );
	}

	public static function get_current_step( $key = false ) {
		$steps = self::get_steps();

		if ( ! $key ) {
			$key = self::$current_step;
		}

		return self::get_step( $key );
	}

	public static function get_step( $key ) {
		$steps = self::get_steps();

		return ( isset( $steps[ $key ] ) ? $steps[ $key ] : false );
	}

	public static function get_step_url( $key ) {
		if ( ! $step = self::get_current_step( $key ) ) {
			return false;
		}

		return admin_url( 'admin.php?page=wc-shiptastic-setup&step=' . $key );
	}

	public static function get_next_step() {
		$current = self::get_current_step();
		$next    = self::$current_step;

		if ( $current['order'] < count( self::$steps ) ) {
			$order_next = $current['order'] + 1;

			foreach ( self::$steps as $step_key => $step ) {
				if ( $step['order'] === $order_next ) {
					$next = $step_key;
				}
			}
		}

		return $next;
	}

	public static function save() {
		check_ajax_referer( 'wc-shiptastic-wizard' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die();
		}

		$current_step = isset( $_POST['step'] ) ? sanitize_key( wp_unslash( $_POST['step'] ) ) : self::get_current_step()['id'];

		if ( ! $step = self::get_step( $current_step ) ) {
			wp_die();
		}

		$result    = true;
		$next_step = self::get_next_step();

		if ( ! is_null( $step['handler'] ) ) {
			$result = call_user_func( $step['handler'] );
		} else {
			$settings = self::get_settings( $current_step );

			if ( ! empty( $settings ) ) {
				\WC_Admin_Settings::save_fields( $settings );
			}
		}

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $result, 500 );
		} else {
			wp_send_json(
				array(
					'redirect' => self::get_step_url( $next_step ),
				)
			);
		}

		exit();
	}
}
