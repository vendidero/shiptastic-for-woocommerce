<?php
/**
 * BulkFulfillment Factory
 *
 * The factory creates the bulk fulfillment objects.
 *
 * @version 1.0.0
 */
namespace Vendidero\Shiptastic\BulkFulfillments;

use Vendidero\Shiptastic\Package;

defined( 'ABSPATH' ) || exit;

class View {

	public static function init() {
		if ( ! current_user_can( 'edit_others_shop_orders' ) ) {
			return;
		}

		add_action( 'admin_menu', array( __CLASS__, 'admin_menus' ), 20 );
		add_action( 'admin_init', array( __CLASS__, 'render' ), 20 );

		// Load after base has registered scripts
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_scripts' ), 15 );
	}

	public static function enqueue_scripts() {
		wp_register_script_module(
			'shiptastic/fulfillments',
			Package::get_assets_url( 'static/admin-fulfillments.js' ),
			array(
				'@wordpress/interactivity',
				array(
					'id'     => '@wordpress/interactivity-router',
					'import' => 'dynamic',
				),
			),
			Package::get_version()
		);

		wp_interactivity()->add_client_navigation_support_to_script_module(
			'shiptastic/fulfillments'
		);

		wp_enqueue_script_module( 'shiptastic/fulfillments' );
	}

	/**
	 * Add admin menus/screens.
	 */
	public static function admin_menus() {
		add_submenu_page( '', _x( 'Bulk Fulfillment', 'shipments', 'shiptastic-for-woocommerce' ), _x( 'Bulk Fulfillment', 'shipments', 'shiptastic-for-woocommerce' ), 'edit_others_shop_orders', 'wc-shiptastic-fulfillment' );
	}

	private static function is_active() {
		return ( isset( $_GET['page'] ) && 'wc-shiptastic-fulfillment' === wc_clean( wp_unslash( $_GET['page'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	}

	/**
	 * Show the setup wizard.
	 */
	public static function render() {
		if ( ! self::is_active() ) {
			return;
		}

		$id          = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
		$order_id    = isset( $_GET['order_id'] ) ? absint( $_GET['order_id'] ) : 0;
		$fulfillment = Factory::get_bulk_fulfillment( $id );

		if ( ! $fulfillment ) {
			return;
		}

		$fulfillment->set_current_order_id( $order_id );

		if ( ! $fulfillment->get_current_order() ) {
			return;
		}

		set_current_screen( 'wc-shiptastic-fulfillment' );

		$next_order = $fulfillment->get_next_order();
		$prev_order = $fulfillment->get_prev_order();

		ob_start();
		?>
		<html <?php language_attributes(); ?>>
			<head>
				<meta charset="<?php bloginfo( 'charset' ); ?>">
				<meta name="viewport" content="width=device-width, initial-scale=1">
				<title><?php wp_title(); ?></title>
				<?php do_action( 'admin_enqueue_scripts' ); ?>
				<?php do_action( 'admin_print_styles' ); ?>
				<?php do_action( 'admin_print_scripts' ); ?>
				<?php do_action( 'admin_head' ); ?>
			</head>
			<body <?php body_class(); ?> data-wp-interactive="shiptastic/fulfillments">
				<main
					data-wp-router-region="shiptastic/fulfillments/fulfillment"
					data-wp-interactive="shiptastic/fulfillments"
					class="site-content"
				>
					<header class="fulfillment-header">
						<h1>Order <?php echo esc_html( $fulfillment->get_current_order_id() ); ?></h1>
						<h2>Action <?php echo esc_html( $fulfillment->get_current_action() ); ?></h2>

						<nav class="fulfillment-order-nav">
							<a
								data-wp-on--click="actions.prev"
								data-wp-on--mouseenter="actions.prefetch"
								class="<?php echo esc_attr( ! $prev_order ? 'disabled' : '' ); ?>"
								href="<?php echo esc_url( $prev_order ? $fulfillment->get_url( $prev_order ) : '#' ); ?>"
							>
								&larr; Prev
							</a>
							<a
								data-wp-on--click="actions.next"
								data-wp-on--mouseenter="actions.prefetch"
								class="<?php echo esc_attr( ! $next_order ? 'disabled' : '' ); ?>"
								href="<?php echo esc_url( $next_order ? $fulfillment->get_url( $next_order ) : '#' ); ?>"
							>
								Next &rarr;
							</a>
						</nav>
					</header>
				</main>

				<?php do_action( 'admin_footer', '' ); ?>
				<?php do_action( 'admin_print_footer_scripts' ); ?>
			</body>
		</html>
		<?php
		exit;
	}
}