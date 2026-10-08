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
use Vendidero\Shiptastic\Packaging\Helper;

defined( 'ABSPATH' ) || exit;

class View {

	public static function init() {
		if ( ! current_user_can( 'edit_others_shop_orders' ) ) {
			return;
		}

		add_action(
			'after_setup_theme',
			function () {
				if ( self::is_active() ) {
					remove_action( 'after_setup_theme', array( wp_script_modules(), 'add_hooks' ) );
				}
			},
			1
		);

		add_action( 'admin_menu', array( __CLASS__, 'admin_menus' ), 20 );
		add_action( 'admin_init', array( __CLASS__, 'render' ), 20 );

		add_action( 'woocommerce_shiptastic_fulfillment_enqueue_scripts', array( __CLASS__, 'enqueue_scripts' ), 15 );
	}

	public static function enqueue_scripts() {
		Scripts::enqueue_script( 'wp-api-fetch' );

		Scripts::register_script_module(
			'binpackingjs',
			Package::get_assets_url( 'static/binpackingjs/index.js' ),
			array(),
			Package::get_version()
		);

		Scripts::register_script_module(
			'shiptastic/fulfillments',
			Package::get_assets_url( 'static/admin-fulfillments.js' ),
			array(
				'binpackingjs',
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

		Scripts::enqueue_script_module( 'shiptastic/fulfillments' );
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
	 * @param BulkFulfillment $fulfillment
	 *
	 * @return string
	 */
	public static function get_fulfillment_html( $fulfillment ) {
		ob_start();
		Scripts::init();

		set_current_screen( 'wc-shiptastic-fulfillment' );

		$next_order = $fulfillment->get_next_order();
		$prev_order = $fulfillment->get_prev_order();

		$locale         = localeconv();
		$decimal_point  = isset( $locale['decimal_point'] ) ? $locale['decimal_point'] : '.';
		$decimal        = ( ! empty( wc_get_price_decimal_separator() ) ) ? wc_get_price_decimal_separator() : $decimal_point;
		$packaging_list = array();

		foreach ( Helper::get_all_packaging() as $packaging ) {
			$packaging_list[] = array(
				'id'                        => $packaging->get_id(),
				'title'                     => $packaging->get_title(),
				'weightUnit'                => wc_stc_get_packaging_weight_unit(),
				'weight'                    => (float) $packaging->get_weight(),
				'maxWeight'                 => (float) $packaging->get_max_content_weight(),
				'dimensionUnit'             => wc_stc_get_packaging_dimension_unit(),
				'length'                    => (float) $packaging->get_length(),
				'width'                     => (float) $packaging->get_width(),
				'height'                    => (float) $packaging->get_height(),
				'innerLength'               => (float) $packaging->get_inner_length(),
				'innerWidth'                => (float) $packaging->get_inner_width(),
				'innerHeight'               => (float) $packaging->get_inner_height(),
				'availableShippingProvider' => $packaging->get_available_shipping_provider(),
			);
		}

		wp_interactivity_config(
			'shiptastic/fulfillments',
			array(
				'baseUrl'              => $fulfillment->get_url(),
				'ajaxUrl'              => admin_url( 'admin-ajax.php' ),
				'shipmentsEndpointUrl' => get_rest_url( null, 'my-plugin/v1/' ),
				'saveNonce'            => wp_create_nonce( 'save-bulk-fulfillment' ),
				'translations'         => array(),
				'decimalPoint'         => $decimal,
				'numberOfDecimals'     => 3,
				'weightUnit'           => get_option( 'woocommerce_weight_unit' ),
				'dimensionUnit'        => get_option( 'woocommerce_dimension_unit' ),
				'packagingOptions'     => $packaging_list,
			)
		);

		$shipment_order      = $fulfillment->get_current_order()->get_shipment_order();
		$current_shipment_id = $fulfillment->get_current_order()->get_current_action()->get_shipment() ? $fulfillment->get_current_order()->get_current_action()->get_shipment()->get_id() : 0;
		$items               = array();
		$shipments           = array();
		$latest_modified     = null;

		foreach ( $shipment_order->get_shippable_items() as $item ) {
			$weight           = 0.0;
			$width            = 0.0;
			$length           = 0.0;
			$height           = 0.0;
			$sku              = '';
			$image            = '';
			$global_unique_id = '';
			$permalink        = '';
			$quantity         = $shipment_order->get_shippable_item_quantity( $item );

			if ( $quantity <= 0 ) {
				continue;
			}

			if ( is_callable( array( $item, 'get_product' ) ) ) {
				if ( $product = $shipment_order->get_order_item_product( $item ) ) {
					$weight           = $product->get_shipping_weight();
					$length           = $product->get_shipping_length();
					$width            = $product->get_shipping_width();
					$height           = $product->get_shipping_height();
					$image            = $product->get_image_url();
					$sku              = $product->get_sku();
					$permalink        = $product->get_permalink();
					$global_unique_id = $product->get_global_unique_id();
				}
			}

			$name       = html_entity_decode( wc_clean( $item->get_name() ), ENT_QUOTES, get_bloginfo( 'charset' ) );
			$attributes = array();

			foreach ( $item->get_formatted_meta_data() as $meta_id => $meta ) {
				$value = wp_kses_post( make_clickable( trim( $meta->value ) ) );
				$label = wp_kses_post( $meta->display_key );

				$attributes[] = array(
					'id'    => $meta_id,
					'value' => $value,
					'label' => $label,
				);
			}

			$items[] = array(
				'name'           => $name,
				'sku'            => $sku,
				'globalUniqueId' => $global_unique_id,
				'attributes'     => $attributes,
				'image'          => $image,
				'permalink'      => $permalink,
				'quantity'       => $quantity,
				'maxQuantity'    => $quantity,
				'id'             => $item->get_id(),
				'itemId'         => 0,
				'weight'         => $weight,
				'length'         => $length,
				'width'          => $width,
				'height'         => $height,
			);
		}

		$shipments_item_count        = 0;
		$shipments_unique_item_count = 0;
		$shipment_count              = 0;

		foreach ( $shipment_order->get_simple_shipments() as $shipment ) {
			$shipment_items       = array();
			$shipment_last_edited = $shipment->get_date_modified() ? $shipment->get_date_modified()->getTimestamp() : $shipment->get_date_created()->getTimestamp();

			if ( is_null( $latest_modified ) ) {
				$latest_modified = $shipment_last_edited;
			} elseif ( $shipment_last_edited > $latest_modified ) {
				$latest_modified = $shipment_last_edited;
			}

			foreach ( $shipment->get_items() as $item_id => $item ) {
				$shipments_item_count += $item->get_quantity();
				++$shipments_unique_item_count;

				$shipment_items[] = array(
					'name'           => $item->get_name(),
					'sku'            => $item->get_sku(),
					'globalUniqueId' => $item->get_global_unique_id(),
					'attributes'     => $item->get_attributes(),
					'image'          => $item->get_image_url(),
					'permalink'      => $item->get_permalink(),
					'quantity'       => $item->get_quantity(),
					'maxQuantity'    => $item->get_quantity(),
					'id'             => $item->get_order_item_id(),
					'itemId'         => $item->get_id(),
					'weight'         => wc_get_weight( $item->get_weight(), Package::get_current_weight_unit(), $shipment->get_weight_unit() ),
					'length'         => wc_get_dimension( $item->get_length(), Package::get_current_dimension_unit(), $shipment->get_dimension_unit() ),
					'width'          => wc_get_dimension( $item->get_width(), Package::get_current_dimension_unit(), $shipment->get_dimension_unit() ),
					'height'         => wc_get_dimension( $item->get_height(), Package::get_current_dimension_unit(), $shipment->get_dimension_unit() ),
				);
			}

			$weight = wc_get_weight( $shipment->get_weight(), Package::get_current_weight_unit(), $shipment->get_weight_unit() );
			$length = wc_get_dimension( $shipment->get_length(), Package::get_current_dimension_unit(), $shipment->get_dimension_unit() );
			$width  = wc_get_dimension( $shipment->get_width(), Package::get_current_dimension_unit(), $shipment->get_dimension_unit() );
			$height = wc_get_dimension( $shipment->get_height(), Package::get_current_dimension_unit(), $shipment->get_dimension_unit() );

			$shipments[] = array(
				'id'               => $shipment->get_id(),
				'status'           => $shipment->get_status(),
				'weightUnit'       => $shipment->get_weight_unit(),
				'editable'         => $shipment->is_editable(),
				'weight'           => $weight,
				'dimensionUnit'    => $shipment->get_dimension_unit(),
				'length'           => $length,
				'width'            => $width,
				'height'           => $height,
				'shippingProvider' => $shipment->get_shipping_provider(),
				'packagingId'      => $shipment->get_packaging_id(),
				'bestPackagingId'  => 0,
				'items'            => $shipment_items,
			);

			++$shipment_count;
		}

		wp_interactivity_state(
			'shiptastic/fulfillments',
			array(
				'fulfillmentId'            => $fulfillment->get_id(),
				'orderId'                  => $fulfillment->get_current_order_id(),
				'orderNumber'              => $fulfillment->get_current_order()->get_order_number(),
				'currentAction'            => $fulfillment->get_current_order()->get_current_action()::get_name(),
				'currentActionId'          => $fulfillment->get_current_order()->get_current_action_id(),
				'nextOrderId'              => $next_order ? $next_order->get_id() : 0,
				'prevOrderId'              => $prev_order ? $prev_order->get_id() : 0,
				'nextOrderNumber'          => $next_order ? $next_order->get_order_number() : 0,
				'prevOrderNumber'          => $prev_order ? $prev_order->get_order_number() : 0,
				'orderItemCount'           => $shipment_order->get_shippable_item_count(),
				'orderUniqueItemCount'     => $shipment_order->get_shippable_unique_item_count(),
				'shipmentsItemCount'       => $shipments_item_count,
				'shipmentsUniqueItemCount' => $shipments_unique_item_count,
				'shipmentCount'            => $shipment_count,
				'shipments'                => $shipments,
				'currentShipmentId'        => $current_shipment_id,
				'currentShipmentNumber'    => 1,
				'allItemsAvailableToShip'  => $items,
				'shipmentsSavedAt'         => $latest_modified,
			)
		);
		ob_start();
		?>
		<html <?php language_attributes(); ?>>
		<head>
			<meta charset="<?php bloginfo( 'charset' ); ?>">
			<meta name="viewport" content="width=device-width, initial-scale=1">
			<title><?php wp_title(); ?></title>
			<?php do_action( 'woocommerce_shiptastic_fulfillment_enqueue_scripts' ); ?>
			<?php do_action( 'woocommerce_shiptastic_fulfillment_print_styles' ); ?>
			<?php do_action( 'woocommerce_shiptastic_fulfillment_print_scripts' ); ?>
			<?php do_action( 'woocommerce_shiptastic_fulfillment_head' ); ?>
		</head>
		<body <?php body_class(); ?>>
		<?php echo wp_interactivity_process_directives( self::get_fulfillment_body( $fulfillment ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>

		<?php do_action( 'woocommerce_shiptastic_fulfillment_footer', '' ); ?>
		<?php do_action( 'woocommerce_shiptastic_fulfillment_print_footer_scripts' ); ?>
		</body>
		</html>
		<?php
		$html = ob_get_clean();

		return $html;
	}

	/**
	 * Show the setup wizard.
	 */
	public static function render() {
		if ( ! self::is_active() ) {
			return;
		}

		$id       = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$order_id = isset( $_GET['order_id'] ) ? absint( $_GET['order_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$action   = isset( $_GET['action'] ) ? wc_clean( wp_unslash( $_GET['action'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$fulfillment = Factory::get_bulk_fulfillment( $id );

		if ( ! $fulfillment ) {
			return;
		}

		$fulfillment->set_current_order_id( $order_id );

		if ( ! $fulfillment->get_current_order() ) {
			return;
		}

		if ( ! empty( $action ) ) {
			$fulfillment->get_current_order()->set_current_action_id( $action );
		}

		echo self::get_fulfillment_html( $fulfillment ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		exit;
	}

	/**
	 * @param BulkFulfillment $fulfillment
	 *
	 * @return false|string
	 */
	protected static function get_fulfillment_body( $fulfillment ) {
		ob_start();
		?>
		<div
			data-wp-router-region="shiptastic/fulfillments/fulfillment"
			data-wp-interactive="shiptastic/fulfillments"
			class="site-content"
		>
			<div
				data-wp-watch---init="callbacks.onInit"
				data-wp-watch---update="callbacks.onUpdate"
			>
			<header class="fulfillment-header">
				<h1>Order <span data-wp-text="state.orderNumber"></span></h1>
				<?php if ( 'shipment' === $fulfillment->get_current_order()->get_current_context() ) : ?>
					<span>Shipment <span data-wp-text="state.currentShipmentNumber"></span>/<span data-wp-text="state.shipmentCount"></span> <span data-wp-text="state.currentShipmentItemCount"></span>/<span data-wp-text="state.shipmentsItemCount"></span> items (<span data-wp-text="state.currentShipmentUniqueItemCount"></span>/<span data-wp-text="state.shipmentsUniqueItemCount"></span> unique)</span></span>
				<?php else : ?>
					<span>Currently shipping <span data-wp-text="state.shipmentsItemCount"></span>/<span data-wp-text="state.orderItemCount"></span> items (<span data-wp-text="state.shipmentsUniqueItemCount"></span>/<span data-wp-text="state.orderUniqueItemCount"></span> unique)</span>
				<?php endif; ?>

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
					<?php foreach ( $fulfillment->get_current_order()->get_action_loop() as $action ) : ?>
						<a
							data-wp-on--click="actions.goToAction"
							data-wp-on--mouseenter="actions.prefetch"
							href="<?php echo esc_url( $action->get_url() ); ?>"
						>
							<?php echo esc_html( $action::get_title() ); ?>
						</a>
					<?php endforeach; ?>
				</nav>
			</header>

			<main
				data-wp-router-region="shiptastic/fulfillments/action"
				data-wp-interactive="shiptastic/fulfillments"
			>
				<?php if ( $current_action = $fulfillment->get_current_order()->get_current_action() ) : ?>
					<div
						id="<?php echo esc_attr( $current_action->get_name() ); ?>"
						data-wp-interactive="shiptastic/fulfillments/<?php echo esc_attr( $current_action->get_name() ); ?>"
						data-wp-watch---init="callbacks.onInit"
						data-wp-watch---update="callbacks.onUpdate"
					>
						<?php
						ob_start();
						$current_action->render();
						$html = ob_get_clean();
						echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
						?>
						<footer>
							<button data-wp-on--click="actions.prev">Prev</button>
							<button data-wp-on--click="actions.done">Done & continue</button>
						</footer>
					</div>
				<?php endif; ?>
			</main>
			</div>
		</div>
		<?php
		return ob_get_clean();
	}
}