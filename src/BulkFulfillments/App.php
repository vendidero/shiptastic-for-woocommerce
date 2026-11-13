<?php

namespace Vendidero\Shiptastic\BulkFulfillments;

use Vendidero\Shiptastic\Package;
use Vendidero\Shiptastic\Rest\FulfillmentController;

defined( 'ABSPATH' ) || exit;

/**
 * The fulfillment app: a standalone single page (no wp-admin chrome, styles or scripts) rendered once per load.
 * The shell and all action templates are rendered server-side (Interactivity API directives, filterable via PHP),
 * afterwards navigating between screens, orders and steps is client-side state only. Data is loaded via the REST API.
 */
class App {

	const PAGE = 'wc-shiptastic-fulfillment';

	const NAMESPACE = 'shiptastic/fulfillment';

	public static function init() {
		add_action(
			'after_setup_theme',
			function () {
				// The app prints its own script modules.
				if ( self::is_active() ) {
					remove_action( 'after_setup_theme', array( wp_script_modules(), 'add_hooks' ) );
				}
			},
			1
		);

		add_action( 'admin_menu', array( __CLASS__, 'add_page' ), 60 );
		add_action( 'admin_init', array( __CLASS__, 'render' ), 20 );
	}

	public static function is_active() {
		return isset( $_GET['page'] ) && self::PAGE === wc_clean( wp_unslash( $_GET['page'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	}

	public static function get_url() {
		return admin_url( 'admin.php?page=' . self::PAGE );
	}

	public static function add_page() {
		add_submenu_page( 'woocommerce', _x( 'Fulfillment', 'shipments', 'shiptastic-for-woocommerce' ), _x( 'Fulfillment', 'shipments', 'shiptastic-for-woocommerce' ), 'edit_shop_orders', self::PAGE, '__return_null' );
	}

	protected static function get_module_url( $file ) {
		return Package::get_url( 'assets/js/fulfillment/' . $file );
	}

	protected static function register_modules() {
		Scripts::init();

		// Creating the scripts instance registers the default (script) modules, e.g. @wordpress/interactivity.
		Scripts::scripts();

		$version = Package::get_version();

		Scripts::register_script_module( self::NAMESPACE, self::get_module_url( 'app.js' ), array( '@wordpress/interactivity' ), $version );
		Scripts::enqueue_script_module( self::NAMESPACE );

		foreach ( ActionRegistry::get_all() as $name => $action ) {
			$module = $action::get_script_module();

			if ( is_array( $module ) && ! empty( $module['id'] ) && ! empty( $module['src'] ) ) {
				Scripts::register_script_module( $module['id'], $module['src'], array( '@wordpress/interactivity', self::NAMESPACE ), isset( $module['version'] ) ? $module['version'] : $version );
				Scripts::enqueue_script_module( $module['id'] );
			}
		}

		do_action( 'woocommerce_shiptastic_fulfillment_app_register_modules' );
	}

	/**
	 * @return array
	 */
	protected static function get_config() {
		return apply_filters(
			'woocommerce_shiptastic_fulfillment_app_config',
			array(
				'restUrl'           => esc_url_raw( rest_url( 'wc/v3/shiptastic/fulfillment' ) ),
				'nonce'             => wp_create_nonce( 'wp_rest' ),
				'nonceUrl'          => esc_url_raw( admin_url( 'admin-ajax.php?action=rest-nonce' ) ),
				'adminUrl'          => esc_url_raw( admin_url() ),
				'orderUrl'          => esc_url_raw( admin_url( 'post.php?action=edit&post=' ) ),
				'userId'            => get_current_user_id(),
				'canManage'         => current_user_can( 'manage_woocommerce' ),
				'heartbeatInterval' => 120,
				'refillAt'          => 5,
				'weightUnit'        => get_option( 'woocommerce_weight_unit', 'kg' ),
				'dimensionUnit'     => get_option( 'woocommerce_dimension_unit', 'cm' ),
				'i18n'              => array(
					'title'             => _x( 'Fulfillment', 'shipments', 'shiptastic-for-woocommerce' ),
					'continue'          => _x( 'Continue', 'shipments', 'shiptastic-for-woocommerce' ),
					/* translators: %d number of items */
					'items'             => _x( '%d item(s)', 'shipments', 'shiptastic-for-woocommerce' ),
					'defaultLocation'   => _x( 'Default location', 'shipments', 'shiptastic-for-woocommerce' ),
					/* translators: %d quantity */
					'backordered'       => _x( '%d on backorder', 'shipments', 'shiptastic-for-woocommerce' ),
					'offline'           => _x( 'You are offline.', 'shipments', 'shiptastic-for-woocommerce' ),
					'error'             => _x( 'Something went wrong. Please try again.', 'shipments', 'shiptastic-for-woocommerce' ),
					'lockLost'          => _x( 'This order is no longer reserved for you and has been removed from your list.', 'shipments', 'shiptastic-for-woocommerce' ),
					'noItems'           => _x( 'No orders left for this workflow.', 'shipments', 'shiptastic-for-woocommerce' ),
					'confirmStop'       => _x( 'Stop? Unfinished orders are put back into the queue.', 'shipments', 'shiptastic-for-woocommerce' ),
					'unknownCode'       => _x( 'No item matches the scanned code.', 'shipments', 'shiptastic-for-woocommerce' ),
					/* translators: 1: current order 2: number of orders */
					'orderProgress'     => _x( 'Order %1$d of %2$d', 'shipments', 'shiptastic-for-woocommerce' ),
					/* translators: 1: current step 2: number of steps */
					'stepCount'         => _x( 'Step %1$d of %2$d', 'shipments', 'shiptastic-for-woocommerce' ),
					/* translators: 1: checked items 2: total items */
					'checklistProgress' => _x( '%1$d/%2$d items', 'shipments', 'shiptastic-for-woocommerce' ),
					/* translators: %s list of items */
					'notPicked'         => _x( 'Not picked yet: %s', 'shipments', 'shiptastic-for-woocommerce' ),
					/* translators: %s list of items */
					'notPacked'         => _x( 'Not packed yet: %s. Remove missing items from the shipment in case they are not available.', 'shipments', 'shiptastic-for-woocommerce' ),
					/* translators: %d number of orders */
					'openOrders'        => _x( '%d order(s) waiting', 'shipments', 'shiptastic-for-woocommerce' ),
					/* translators: 1: first item 2: last item 3: total */
					'queueRange'        => _x( '%1$d–%2$d of %3$d', 'shipments', 'shiptastic-for-woocommerce' ),
					/* translators: %d number of shipments */
					'shipmentsCount'    => _x( 'Shipments (%d)', 'shipments', 'shiptastic-for-woocommerce' ),
					/* translators: %d shipment number */
					'shipmentTitle'     => _x( 'Shipment %d', 'shipments', 'shiptastic-for-woocommerce' ),
					/* translators: %s weight incl. unit */
					'totalWeight'       => _x( 'Total weight incl. packaging: %s', 'shipments', 'shiptastic-for-woocommerce' ),
					'discardChanges'    => _x( 'Discard your changes to the shipments?', 'shipments', 'shiptastic-for-woocommerce' ),
				),
			)
		);
	}

	/**
	 * @return array
	 */
	protected static function get_initial_state() {
		global $wpdb;

		$workflows = array();

		foreach ( $wpdb->get_col( "SELECT workflow_id FROM {$wpdb->stc_fulfillment_workflows} WHERE workflow_status = 'active' ORDER BY workflow_priority DESC, workflow_id ASC" ) as $workflow_id ) {
			$workflows[] = FulfillmentController::prepare_workflow( new Workflow( $workflow_id ) );
		}

		return apply_filters(
			'woocommerce_shiptastic_fulfillment_app_state',
			array(
				'screen'         => 'overview',
				'workflows'      => $workflows,
				'counts'         => Queue::count_by_status(),
				'queue'          => array(
					'status'     => 'pending',
					'items'      => array(),
					'page'       => 1,
					'pages'      => 0,
					'total'      => 0,
					'perPage'    => 25,
					'loading'    => true,
					'workflowId' => 0,
				),
				'run'            => array(
					'workflowId' => 0,
					'items'      => array(),
					'total'      => 0,
					'done'       => 0,
					'exhausted'  => false,
					'skipped'    => array(),
				),
				'currentKey'     => '',
				'showValidation' => false,
				'stepListOpen'   => false,
				'packaging'      => ShipmentLayout::get_packaging_data(),
				'providers'      => ShipmentLayout::get_provider_data(),
				'overlay'        => array(
					'open'      => false,
					'saving'    => false,
					'dirty'     => false,
					'activeKey' => '',
					'items'     => array(),
					'shipments' => array(),
				),
				'view'           => new \stdClass(),
				'form'           => new \stdClass(),
				'busy'           => false,
				'online'         => true,
				'notice'         => array(
					'type'    => '',
					'message' => '',
				),
			)
		);
	}

	/**
	 * @return string The action templates, each shown while its action is the current step.
	 */
	protected static function get_action_templates() {
		$html = '';

		foreach ( ActionRegistry::get_all() as $name => $action ) {
			/**
			 * Filter to adjust the template (markup with Interactivity API directives) of a fulfillment action.
			 *
			 * @param string $template
			 * @param string $name
			 *
			 * @package Vendidero\Shiptastic
			 */
			$template = apply_filters( 'woocommerce_shiptastic_fulfillment_action_template', $action::get_template(), $name );

			// E.g. background-only actions.
			if ( empty( $template ) ) {
				continue;
			}

			$html .= sprintf(
				'<section class="stc-step stc-step-%1$s" data-wp-context=\'%2$s\' data-wp-bind--hidden="!state.isCurrentAction">%3$s</section>',
				esc_attr( $name ),
				wp_json_encode( array( 'action' => $name ) ),
				$template
			);
		}

		return $html;
	}

	public static function render_view( $view, $args = array() ) {
		$file = Package::get_path() . '/includes/admin/views/fulfillment/' . $view . '.php';

		if ( ! file_exists( $file ) ) {
			return '';
		}

		ob_start();
		extract( $args ); // phpcs:ignore WordPress.PHP.DontExtract.extract_extract
		include $file;

		return ob_get_clean();
	}

	public static function render() {
		if ( ! self::is_active() ) {
			return;
		}

		if ( ! current_user_can( 'edit_shop_orders' ) ) {
			wp_die( esc_html_x( 'Sorry, you are not allowed to fulfill orders.', 'shipments', 'shiptastic-for-woocommerce' ), 403 );
		}

		self::register_modules();

		wp_interactivity_config( self::NAMESPACE, self::get_config() );
		wp_interactivity_state( self::NAMESPACE, self::get_initial_state() );

		$body = self::render_view( 'app', array( 'action_templates' => self::get_action_templates() ) );

		nocache_headers();
		header( 'Content-Type: text/html; charset=' . get_bloginfo( 'charset' ) );
		?>
		<!DOCTYPE html>
		<html <?php language_attributes(); ?>>
		<head>
			<meta charset="<?php bloginfo( 'charset' ); ?>">
			<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
			<meta name="theme-color" content="#1e1e1e">
			<title><?php echo esc_html_x( 'Fulfillment', 'shipments', 'shiptastic-for-woocommerce' ); ?></title>
			<link rel="stylesheet" href="<?php echo esc_url( Package::get_assets_url( 'static/fulfillment-styles.css' ) . '?ver=' . Package::get_version() ); ?>" /><?php // phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedStylesheet ?>
			<?php do_action( 'woocommerce_shiptastic_fulfillment_head' ); ?>
		</head>
		<body class="stc-fulfillment">
			<?php echo wp_interactivity_process_directives( $body ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			<?php do_action( 'woocommerce_shiptastic_fulfillment_print_footer_scripts' ); ?>
		</body>
		</html>
		<?php
		exit;
	}
}
