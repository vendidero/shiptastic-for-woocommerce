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
		wp_enqueue_script( 'wp-api-fetch' );
		wp_enqueue_script( 'wp-url' );

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

		$next_order = $fulfillment->get_next_order();
		$prev_order = $fulfillment->get_prev_order();

		wp_interactivity_config(
			'shiptastic/fulfillments',
			array(
				'baseUrl'              => $fulfillment->get_url(),
				'shipmentsEndpointUrl' => get_rest_url( null, 'my-plugin/v1/' ),
				'nonce'                => wp_create_nonce( 'my_plugin_action' ),
				'translations'         => array(),
			)
		);

		$shipment_order = $fulfillment->get_current_order()->get_shipment_order();
		$items          = array();
		$shipments      = array();

		foreach ( $shipment_order->get_available_items_for_shipment() as $item_id => $item ) {
			$order_item = $shipment_order->get_order()->get_item( $item_id, false );
			$weight     = 0.0;
			$width      = 0.0;
			$length     = 0.0;
			$height     = 0.0;

			if ( $order_item && is_callable( array( $order_item, 'get_product' ) ) ) {
				if ( $product = $shipment_order->get_order_item_product( $order_item ) ) {
					$weight = $product->get_shipping_weight();
					$length = $product->get_shipping_length();
					$width  = $product->get_shipping_width();
					$height = $product->get_shipping_height();
				}
			}

			$items[] = array(
				'name'        => $item['name'],
				'maxQuantity' => $item['max_quantity'],
				'quantity'    => $item['max_quantity'],
				'weight'      => $weight,
				'length'      => $length,
				'width'       => $width,
				'height'      => $height,
				'id'          => $item_id,
			);
		}

		$shipments_item_count        = 0;
		$shipments_unique_item_count = 0;

		foreach ( $shipment_order->get_simple_shipments() as $shipment ) {
			$shipment_items = array();

			foreach ( $shipment->get_items() as $item_id => $item ) {
				$shipments_item_count += $item->get_quantity();
				++$shipments_unique_item_count;

				$shipment_items[] = array(
					'name'        => $item->get_name(),
					'quantity'    => $item->get_quantity(),
					'maxQuantity' => $item->get_quantity(),
					'id'          => $item->get_order_item_id(),
					'itemId'      => $item->get_id(),
					'weight'      => wc_get_weight( $item->get_weight(), Package::get_current_weight_unit(), $shipment->get_weight_unit() ),
					'length'      => wc_get_dimension( $item->get_length(), Package::get_current_dimension_unit(), $shipment->get_dimension_unit() ),
					'width'       => wc_get_dimension( $item->get_width(), Package::get_current_dimension_unit(), $shipment->get_dimension_unit() ),
					'height'      => wc_get_dimension( $item->get_height(), Package::get_current_dimension_unit(), $shipment->get_dimension_unit() ),
				);
			}

			$shipments[] = array(
				'id'               => $shipment->get_id(),
				'status'           => $shipment->get_status(),
				'weight'           => wc_get_weight( $shipment->get_weight(), Package::get_current_weight_unit(), $shipment->get_weight_unit() ),
				'length'           => wc_get_dimension( $shipment->get_length(), Package::get_current_dimension_unit(), $shipment->get_dimension_unit() ),
				'width'            => wc_get_dimension( $shipment->get_width(), Package::get_current_dimension_unit(), $shipment->get_dimension_unit() ),
				'height'           => wc_get_dimension( $shipment->get_height(), Package::get_current_dimension_unit(), $shipment->get_dimension_unit() ),
				'shippingProvider' => $shipment->get_shipping_provider(),
				'packagingId'      => $shipment->get_packaging_id(),
				'items'            => $shipment_items,
			);
		}

		wp_interactivity_state(
			'shiptastic/fulfillments',
			array(
				'fulfillmentId'            => $fulfillment->get_id(),
				'orderId'                  => $fulfillment->get_current_order_id(),
				'orderNumber'              => $fulfillment->get_current_order()->get_order_number(),
				'shipmentId'               => $fulfillment->get_current_order()->get_current_shipment_id(),
				'currentAction'            => $fulfillment->get_current_order()->get_current_action_name(),
				'nextOrderId'              => $next_order ? $next_order->get_id() : 0,
				'prevOrderId'              => $prev_order ? $prev_order->get_id() : 0,
				'nextOrderNumber'          => $next_order ? $next_order->get_order_number() : 0,
				'prevOrderNumber'          => $prev_order ? $prev_order->get_order_number() : 0,
				'orderItemCount'           => $shipment_order->get_shippable_item_count(),
				'orderUniqueItemCount'     => $shipment_order->get_shippable_unique_item_count(),
				'shipmentsItemCount'       => $shipments_item_count,
				'shipmentsUniqueItemCount' => $shipments_unique_item_count,
				'shipments'                => $shipments,
				'itemsAvailableToShip'     => $items,
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
		ob_start();
		?>
		<div
			data-wp-router-region="shiptastic/fulfillments/fulfillment"
			data-wp-interactive="shiptastic/fulfillments"
			data-wp-watch="callbacks.onUpdateState"
			class="site-content"
		>
			<header class="fulfillment-header">
				<h1>Order <span data-wp-text="state.orderNumber"></span></h1>

				<span>Currently shipping <span data-wp-text="state.shipmentsItemCount"></span>/<span data-wp-text="state.orderItemCount"></span> items (<span data-wp-text="state.shipmentsUniqueItemCount"></span>/<span data-wp-text="state.orderUniqueItemCount"></span> unique)</span>

				<nav class="fulfillment-order-nav">
					<a
						data-wp-on--click="actions.prevOrder"
						data-wp-class--disabled="!state.prevOrderId"
						data-wp-bind--href="callbacks.getPrevOrderUrl"
						href="#"
					>
						&larr; <span data-wp-text="state.prevOrderNumber"></span>
					</a>
					<a
						data-wp-on--click="actions.nextOrder"
						data-wp-class--disabled="!state.nextOrderId"
						data-wp-bind--href="callbacks.getNextOrderUrl"
						href="#"
					>
						<span data-wp-text="state.nextOrderNumber"></span> &rarr;
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
					<div id="<?php echo esc_attr( $current_action->get_name() ); ?>">
						<?php
						ob_start();
						$current_action->render();
						$html = ob_get_clean();
						echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
						?>
					</div>
				<?php endif; ?>
			</main>

			<footer>
				<button data-wp-on--click="actions.save">Save</button>
			</footer>
		</div>
		<?php
		return ob_get_clean();
	}
}