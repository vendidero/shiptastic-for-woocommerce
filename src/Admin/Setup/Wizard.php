<?php

namespace Vendidero\Shiptastic\Admin\Setup;

use Vendidero\Shiptastic\Package;defined( 'ABSPATH' ) || exit;

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
			add_action( 'admin_post_wc_shiptastic_wizard', array( __CLASS__, 'save' ) );
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
			'welcome'   => array(
				'name'    => _x( 'Welcome', 'shipments', 'shiptatic-for-woocommerce' ),
				'order'   => 1,
			),
			'test'   => array(
				'name'    => _x( 'Test', 'shipments', 'shiptatic-for-woocommerce' ),
				'order'   => 10,
			),
		);

		self::$steps = $default_steps;
		uasort( self::$steps, array( __CLASS__, 'uasort_callback' ) );

		$order = 0;

		foreach ( self::$steps as $key => $step ) {
            self::$steps[ $key ] = wp_parse_args( self::$steps[ $key ], array(
                'id'               => $key,
                'view'             => $key . '.php',
                'button_next'      => _x( 'Continue', 'shipments-wizard', 'shiptastic-for-woocommerce' ),
                'button_next_link' => '',
                'handler'          => null,
            ) );

			self::$steps[ $key ]['order'] = ++$order;
		}

		self::$current_step = isset( $_REQUEST['step'] ) ? sanitize_key( wp_unslash( $_REQUEST['step'] ) ) : current( array_keys( self::$steps ) );
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

			wp_register_script( 'wc-shiptastic-admin-wizard', Package::get_assets_url( 'static/admin-wizard.js' ), array( 'wc-shiptastic-admin' ), Package::get_version() ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.NotInFooter
			wp_enqueue_script( 'wc-shiptastic-admin-wizard' );
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
		<body class="wc-shiptastic-wizard wp-core-ui wc-shiptatic-wizard-step-<?php echo esc_attr( self::$current_step ); ?>">
			<div class="wc-shiptastic-wizard-header">
                <div class="wc-shiptastic-wizard-progress-bar">
                    <div class="wc-shiptastic-wizard-progress-bar-container">
                        <div class="wc-shiptastic-wizard-progress-bar-filler" style="width: <?php echo esc_attr( $step_pct ); ?>%;"></div>
                    </div>
                </div>
                <div class="wc-shiptastic-wizard-header-nav">
                    <div class="wc-shiptastic-wizard-logo">
                        <span class="shiptastic-logo">
                            <?php include( Package::get_path( 'assets/logo.svg' ) ); ?>
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

                    <input type="hidden" name="action" value="wc_shiptastic_wizard" />
                    <input type="hidden" name="current_step" value="<?php echo esc_attr( $current_step['id'] ); ?>" />

				    <?php wp_nonce_field( 'wc-shiptastic-wizard' ); ?>

                    <div class="wc-shiptastic-wizard-links">
					    <?php if ( ! empty( $current_step['button_next_link'] ) ) : ?>
                            <a class="button button-primary" href="<?php echo esc_url( $current_step['button_next_link'] ); ?>"><?php echo esc_attr( $current_step['button_next'] ); ?></a>
					    <?php else : ?>
                            <button class="button button-primary" type="submit"><?php echo esc_attr( $current_step['button_next'] ); ?></button>
					    <?php endif; ?>
                    </div>
                </div>
		    </form>
            <div class="wwc-shiptastic-wizard-footer">
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
            if ( file_exists( Package::get_path( 'includes/admin/views/wizard/' . $step['view'] ) ) ) {
                include Package::get_path( 'includes/admin/views/wizard/' . $step['view'] );
            }
        }
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
		if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( wp_unslash( $_POST['_wpnonce'] ), 'wc-shiptastic-wizard' ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			wp_die();
		} elseif ( ! current_user_can( 'manage_options' ) ) {
			wp_die();
		}

		$current_step = isset( $_POST['step'] ) ? sanitize_key( wp_unslash( $_POST['step'] ) ) : self::get_current_step()['id'];

		if ( ! $step = self::get_step( $current_step ) ) {
			wp_die();
		}

        if ( ! is_null( $step['handler'] ) ) {
            call_user_func( $step['handler'] );
        }
	}
}
