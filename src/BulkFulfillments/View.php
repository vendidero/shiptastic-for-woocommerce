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

		$id          = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$order_id    = isset( $_GET['order_id'] ) ? absint( $_GET['order_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$action      = isset( $_GET['action'] ) ? wc_clean( wp_unslash( $_GET['action'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$shipment_id = isset( $_GET['shipment_id'] ) ? absint( $_GET['shipment_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$fulfillment = Factory::get_bulk_fulfillment( $id );

		if ( ! $fulfillment ) {
			return;
		}

		$fulfillment->set_current_order_id( $order_id );

		if ( ! $fulfillment->get_current_order() ) {
			return;
		}

		if ( ! empty( $action ) ) {
			$fulfillment->get_current_order()->set_current_action_name( $action );
		}

		if ( ! empty( $shipment_id ) ) {
			$fulfillment->get_current_order()->set_current_shipment_id( $shipment_id );
		}

		set_current_screen( 'wc-shiptastic-fulfillment' );

		wp_interactivity_state(
			'shiptastic/fulfillments',
			array(
				'counter' => 5,
			)
		);
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
			<body <?php body_class(); ?>>
				<?php echo wp_interactivity_process_directives( self::get_html( $fulfillment ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>

				<?php do_action( 'admin_footer', '' ); ?>
				<?php do_action( 'admin_print_footer_scripts' ); ?>
			</body>
		</html>
		<?php
		exit;
	}

	/**
	 * @param BulkFulfillment $fulfillment
	 *
	 * @return false|string
	 */
	protected static function get_html( $fulfillment ) {
		$next_order = $fulfillment->get_next_order();
		$prev_order = $fulfillment->get_prev_order();
		ob_start();
		?>
		<div
			data-wp-router-region="shiptastic/fulfillments/fulfillment"
			data-wp-interactive="shiptastic/fulfillments"
			class="site-content"
			data-wp-watch="callbacks.updateContext"
		>
			<header class="fulfillment-header">
				<h1>Order <?php echo esc_html( $fulfillment->get_current_order_id() ); ?></h1>

				<nav class="fulfillment-order-nav">
					<a
						data-wp-on--click="actions.prevOrder"
						class="<?php echo esc_attr( ! $prev_order ? 'disabled' : '' ); ?>"
						href="<?php echo esc_url( $prev_order ? $fulfillment->get_url( $prev_order ) : '#' ); ?>"
					>
						&larr; Prev
					</a>
					<a
						data-wp-on--click="actions.nextOrder"
						class="<?php echo esc_attr( ! $next_order ? 'disabled' : '' ); ?>"
						href="<?php echo esc_url( $next_order ? $fulfillment->get_url( $next_order ) : '#' ); ?>"
					>
						Next &rarr;
					</a>
				</nav>

				<nav class="fulfillment-order-actions-nav">
					<?php foreach ( $fulfillment->get_current_order()->get_action_loop( 'order' ) as $action ) : ?>
						<a
							data-wp-on--click="actions.goToAction"
							data-wp-on--mouseenter="actions.prefetch"
							href="<?php echo esc_url( $fulfillment->get_url( $fulfillment->get_current_order_id(), $fulfillment->get_current_order()->is_default_action( $action::get_name(), 'order' ) ? '' : $action::get_name() ) ); ?>"
						>
							<?php echo esc_html( $action::get_title() ); ?>
						</a>
					<?php endforeach; ?>
					<?php
					$shipment_count  = 0;
					$shipment_loop   = $fulfillment->get_current_order()->get_action_loop( 'shipment' );
					$total_shipments = count( $shipment_loop );
					foreach ( $shipment_loop as $shipment_id => $actions ) :
						++$shipment_count;
						?>
						<a
							data-wp-on--click="actions.goToAction"
							data-wp-on--mouseenter="actions.prefetch"
							href="<?php echo esc_url( $fulfillment->get_url( $fulfillment->get_current_order_id(), '', $shipment_id ) ); ?>"
						>
							<?php echo esc_html( sprintf( _x( 'Shipment %1$s/%2$s', 'shipments', 'shiptastic-for-woocommerce' ), $shipment_count, $total_shipments ) ); ?>
						</a>
					<?php endforeach; ?>
				</nav>

				<?php if ( $current_shipment_id = $fulfillment->get_current_order()->get_current_shipment_id() ) : ?>
					<nav class="fulfillment-shipment-actions-nav">
						<?php foreach ( $fulfillment->get_current_order()->get_action_loop( 'shipment', $current_shipment_id ) as $action ) : ?>
							<a
								data-wp-on--click="actions.goToAction"
								data-wp-on--mouseenter="actions.prefetch"
								href="<?php echo esc_url( $fulfillment->get_url( $fulfillment->get_current_order_id(), $fulfillment->get_current_order()->is_default_action( $action::get_name(), 'shipment' ) ? '' : $action::get_name(), $current_shipment_id ) ); ?>"
							>
								<?php echo esc_html( $action::get_title() ); ?>
							</a>
						<?php endforeach; ?>
					</nav>
				<?php endif; ?>
			</header>

			<main
				data-wp-router-region="shiptastic/fulfillments/action"
				data-wp-interactive="shiptastic/fulfillments"
			>
				<?php if ( $current_action = $fulfillment->get_current_order()->get_current_action() ) : ?>
					<?php
					ob_start();
					$current_action->render();
					$html = ob_get_clean();
					echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					?>
				<?php endif; ?>
			</main>
		</div>
		<?php
		return ob_get_clean();
	}
}