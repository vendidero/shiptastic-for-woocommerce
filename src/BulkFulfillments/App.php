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

		// Service worker (offline support): served below /wp-admin/ so that it may control the app page.
		add_action( 'wp_ajax_stc_fulfillment_sw', array( __CLASS__, 'service_worker' ) );
		add_action( 'wp_ajax_nopriv_stc_fulfillment_sw', array( __CLASS__, 'service_worker' ) );
	}

	/**
	 * Removes the data the app stored in the browser after logging out (login screen with `loggedout`): the cached
	 * app page (service worker) and the stored runs (IndexedDB). Changes not synced yet (outbox) are kept, they are
	 * synced once the user opens the app again.
	 */
	public static function maybe_clear_local_data() {
		if ( ! isset( $_GET['loggedout'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		/**
		 * Filter to keep the data of the fulfillment app stored in the browser after logging out.
		 *
		 * @param bool $clear
		 *
		 * @package Vendidero/Shiptastic
		 */
		if ( ! apply_filters( 'woocommerce_shiptastic_fulfillment_clear_local_data_on_logout', true ) ) {
			return;
		}

		$script = <<<'JS'
( function() {
	try {
		if ( window.caches ) {
			window.caches.keys().then( function( keys ) {
				keys.filter( function( key ) { return 0 === key.indexOf( 'stc-fulfillment-' ); } ).forEach( function( key ) { window.caches.delete( key ); } );
			} ).catch( function() {} );
		}

		if ( ! window.indexedDB ) {
			return;
		}

		var request = window.indexedDB.open( 'shiptastic-fulfillment', 1 );

		request.onupgradeneeded = function() {
			if ( ! request.result.objectStoreNames.contains( 'kv' ) ) {
				request.result.createObjectStore( 'kv' );
			}
		};

		request.onsuccess = function() {
			var db = request.result;
			var transaction = db.transaction( 'kv', 'readwrite' );
			var cursorRequest = transaction.objectStore( 'kv' ).openCursor();

			cursorRequest.onsuccess = function() {
				var cursor = cursorRequest.result;

				if ( ! cursor ) {
					return;
				}

				var key = String( cursor.key );

				if ( 0 === key.indexOf( 'run-' ) || ( 0 === key.indexOf( 'outbox-' ) && ( ! Array.isArray( cursor.value ) || 0 === cursor.value.length ) ) ) {
					cursor.delete();
				}

				cursor.continue();
			};

			transaction.oncomplete = function() {
				db.close();
			};
		};
	} catch ( e ) {}
} )();
JS;

		wp_print_inline_script_tag( $script );
	}

	/**
	 * Whether the app registers its service worker (offline support, see assets/js/fulfillment/service-worker.js).
	 * Browsers only support service workers in secure contexts (HTTPS, localhost).
	 *
	 * @return bool
	 */
	public static function has_service_worker() {
		/**
		 * Filter to disable the service worker of the fulfillment app.
		 *
		 * @param bool $enabled
		 *
		 * @package Vendidero/Shiptastic
		 */
		return (bool) apply_filters( 'woocommerce_shiptastic_fulfillment_service_worker', true );
	}

	/**
	 * @return array|null url, scope
	 */
	protected static function get_service_worker_config() {
		if ( ! self::has_service_worker() ) {
			return null;
		}

		return array(
			'url'   => esc_url_raw(
				add_query_arg(
					array(
						'action' => 'stc_fulfillment_sw',
						'v'      => Package::get_version(),
					),
					admin_url( 'admin-ajax.php' )
				)
			),
			'scope' => esc_url_raw( self::get_url() ),
		);
	}

	/**
	 * Outputs the service worker script. Not user specific (also served without login, e.g. update checks after
	 * the session expired).
	 */
	public static function service_worker() {
		$file = Package::get_path( 'assets/js/fulfillment/service-worker.js' );

		if ( ! self::has_service_worker() || ! file_exists( $file ) ) {
			status_header( 404 );
			exit();
		}

		nocache_headers();
		header( 'Content-Type: application/javascript; charset=utf-8' );
		header( 'X-Content-Type-Options: nosniff' );

		readfile( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
		exit();
	}

	public static function is_active() {
		return isset( $_GET['page'] ) && self::PAGE === wc_clean( wp_unslash( $_GET['page'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	}

	public static function get_url() {
		return admin_url( 'admin.php?page=' . self::PAGE );
	}

	/**
	 * Whether fulfillment automations (background workflows, steps running automatically) are available, i.e. an
	 * extension (Shiptastic Pro) added the background workflow mode. Otherwise the automation options are presented
	 * as pro features.
	 *
	 * @return bool
	 */
	public static function has_automation() {
		return array_key_exists( 'background', Workflow::get_modes() );
	}

	/**
	 * Pro marker (badge linking to Shiptastic Pro), like the pro markers within the settings.
	 *
	 * @param array $attributes Additional HTML attributes.
	 *
	 * @return string
	 */
	public static function get_pro_badge( $attributes = array() ) {
		$attributes = array_merge(
			array(
				'class'  => 'stc-pro-badge',
				'href'   => self::get_pro_url(),
				'target' => '_blank',
				'rel'    => 'noopener noreferrer',
				'title'  => _x( 'Available with Shiptastic Pro', 'shipments', 'shiptastic-for-woocommerce' ),
			),
			$attributes
		);

		$html = '';

		foreach ( $attributes as $name => $value ) {
			$html .= ' ' . esc_attr( $name ) . '="' . ( 'href' === $name ? esc_url( $value ) : esc_attr( $value ) ) . '"';
		}

		return '<a' . $html . '>' . esc_html_x( 'pro', 'shipments', 'shiptastic-for-woocommerce' ) . '</a>';
	}

	/**
	 * @return string URL to learn more about/upgrade to Shiptastic Pro.
	 */
	public static function get_pro_url() {
		/**
		 * Filter to adjust the URL of the pro markers shown for pro features within the fulfillment app.
		 *
		 * @param string $url
		 *
		 * @package Vendidero/Shiptastic
		 */
		return apply_filters( 'woocommerce_shiptastic_fulfillment_pro_url', 'https://vendidero.com/shiptastic-pro' );
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
				'restRoot'          => esc_url_raw( rest_url() ),
				'nonce'             => wp_create_nonce( 'wp_rest' ),
				'nonceUrl'          => esc_url_raw( admin_url( 'admin-ajax.php?action=rest-nonce' ) ),
				'adminUrl'          => esc_url_raw( admin_url() ),
				'orderUrl'          => esc_url_raw( admin_url( 'post.php?action=edit&post=' ) ),
				'userId'            => get_current_user_id(),
				'canManage'         => current_user_can( 'manage_woocommerce' ),
				'heartbeatInterval' => 120,
				'refillAt'          => 5,
				'serviceWorker'     => self::get_service_worker_config(),
				'weightUnit'        => get_option( 'woocommerce_weight_unit', 'kg' ),
				'dimensionUnit'     => get_option( 'woocommerce_dimension_unit', 'cm' ),
				// Packaging suggestions (packing.js): default packaging (setting), units packed in 3D at most.
				'packing'           => array(
					'defaultPackaging' => absint( Package::get_setting( 'default_packaging' ) ),
					/**
					 * Filter to adjust the number of units packed in 3D for packaging suggestions within the fulfillment
					 * app, above only volume and weight are checked.
					 *
					 * @param int $max_items
					 *
					 * @package Vendidero/Shiptastic
					 */
					'maxItems'         => absint( apply_filters( 'woocommerce_shiptastic_fulfillment_packing_max_items', 100 ) ),
					'dimensionUnit'    => wc_stc_get_packaging_dimension_unit(),
				),
				// Pro features (automations) are presented with a pro marker in case they are not available.
				'pro'               => array(
					'automation' => self::has_automation(),
					'url'        => esc_url_raw( self::get_pro_url() ),
				),
				'i18n'              => array(
					'title'             => _x( 'Fulfillment', 'shipments', 'shiptastic-for-woocommerce' ),
					'continue'          => _x( 'Continue', 'shipments', 'shiptastic-for-woocommerce' ),
					/* translators: %d number of items */
					'items'             => _x( '%d item(s)', 'shipments', 'shiptastic-for-woocommerce' ),
					'defaultLocation'   => _x( 'Default location', 'shipments', 'shiptastic-for-woocommerce' ),
					/* translators: %d quantity */
					'backordered'       => _x( '%d on backorder', 'shipments', 'shiptastic-for-woocommerce' ),
					'offline'           => _x( 'You are offline.', 'shipments', 'shiptastic-for-woocommerce' ),
					/* translators: %s order number */
					'savedOffline'      => _x( 'Order #%s is saved on this device and will be synced once you are back online.', 'shipments', 'shiptastic-for-woocommerce' ),
					/* translators: %s order number */
					'movedWaiting'      => _x( 'Order #%s needs a connection for its next step and continues once you are back online.', 'shipments', 'shiptastic-for-woocommerce' ),
					/* translators: %d number of changes */
					'syncPending'       => _x( '%d change(s) waiting to sync', 'shipments', 'shiptastic-for-woocommerce' ),
					'syncRunning'       => _x( 'Syncing…', 'shipments', 'shiptastic-for-woocommerce' ),
					'syncDone'          => _x( 'All changes are synced.', 'shipments', 'shiptastic-for-woocommerce' ),
					'syncAuth'          => _x( 'Your session expired. Please log in again in another tab, your changes are kept on this device.', 'shipments', 'shiptastic-for-woocommerce' ),
					/* translators: %s order number */
					'syncAttention'     => _x( 'Order #%s is ready to finish.', 'shipments', 'shiptastic-for-woocommerce' ),
					'syncConflict'      => _x( 'Your changes could not be saved: the order is no longer reserved for you (e.g. the reservation expired while you were offline and someone else took it).', 'shipments', 'shiptastic-for-woocommerce' ),
					'requiresNetwork'   => _x( 'This requires a connection.', 'shipments', 'shiptastic-for-woocommerce' ),
					'stopPending'       => _x( 'Some changes are not synced yet. Please wait until you are back online before ending the run.', 'shipments', 'shiptastic-for-woocommerce' ),
					/* translators: %d number of orders */
					'waitingOrders'     => _x( '%d order(s) waiting for the connection', 'shipments', 'shiptastic-for-woocommerce' ),
					/* translators: %d number of orders */
					'readyOrders'       => _x( '%d order(s) ready to finish', 'shipments', 'shiptastic-for-woocommerce' ),
					'waitingStatus'     => _x( 'Waiting for the connection', 'shipments', 'shiptastic-for-woocommerce' ),
					/* translators: %s step title */
					'readyStatus'       => _x( 'Ready to finish: %s', 'shipments', 'shiptastic-for-woocommerce' ),
					'error'             => _x( 'Something went wrong. Please try again.', 'shipments', 'shiptastic-for-woocommerce' ),
					'lockLost'          => _x( 'This order is no longer reserved for you and has been removed from your list.', 'shipments', 'shiptastic-for-woocommerce' ),
					'noItems'           => _x( 'No orders left for this workflow.', 'shipments', 'shiptastic-for-woocommerce' ),
					'confirmStop'       => _x( 'Stop? Unfinished orders are put back into the queue.', 'shipments', 'shiptastic-for-woocommerce' ),
					'unknownCode'       => _x( 'No item matches the scanned code.', 'shipments', 'shiptastic-for-woocommerce' ),
					/* translators: 1: current order 2: number of orders */
					'orderProgress'     => _x( 'Order %1$d of %2$d', 'shipments', 'shiptastic-for-woocommerce' ),
					/* translators: 1: current step 2: number of steps */
					'stepCount'         => _x( 'Step %1$d of %2$d', 'shipments', 'shiptastic-for-woocommerce' ),
					'autoNext'          => _x( 'Then automatically: %s', 'shipments', 'shiptastic-for-woocommerce' ),
					'autoDone'          => _x( 'Done automatically: %s', 'shipments', 'shiptastic-for-woocommerce' ),
					'autoRunTitle'      => _x( 'Running automatically…', 'shipments', 'shiptastic-for-woocommerce' ),
					'autoRunDone'       => _x( 'Done', 'shipments', 'shiptastic-for-woocommerce' ),
					'autoPaused'        => _x( 'Paused: a step failed', 'shipments', 'shiptastic-for-woocommerce' ),
					'autoOpenStep'      => _x( 'Open step', 'shipments', 'shiptastic-for-woocommerce' ),
					'autoShowError'     => _x( 'Show details', 'shipments', 'shiptastic-for-woocommerce' ),
					/* translators: %s error message */
					'manualStep'        => _x( 'This step failed while running automatically: %s Check it and continue, the remaining steps run automatically afterwards.', 'shipments', 'shiptastic-for-woocommerce' ),
					'backReleases'      => _x( 'End and back to overview (unfinished orders are put back into the queue)', 'shipments', 'shiptastic-for-woocommerce' ),
					/* translators: %d number of files */
					'downloadFiles'     => _x( 'Download files (%d)', 'shipments', 'shiptastic-for-woocommerce' ),
					'workflowEditor'    => array(
						'newTitle'       => _x( 'New workflow', 'shipments', 'shiptastic-for-woocommerce' ),
						'editTitle'      => _x( 'Edit workflow', 'shipments', 'shiptastic-for-woocommerce' ),
						'saved'          => _x( 'Workflow saved.', 'shipments', 'shiptastic-for-woocommerce' ),
						'deleted'        => _x( 'Workflow deleted.', 'shipments', 'shiptastic-for-woocommerce' ),
						'confirmDelete'  => _x( 'Delete this workflow? Orders are not affected.', 'shipments', 'shiptastic-for-woocommerce' ),
						'discardChanges' => _x( 'Discard your changes to the workflow?', 'shipments', 'shiptastic-for-woocommerce' ),
						'inactive'       => _x( 'Inactive', 'shipments', 'shiptastic-for-woocommerce' ),
						'rangeAny'       => _x( 'All orders, no limit.', 'shipments', 'shiptastic-for-woocommerce' ),
						/* translators: %s minimum incl. unit */
						'rangeMin'       => _x( 'Orders with at least %s.', 'shipments', 'shiptastic-for-woocommerce' ),
						/* translators: %s maximum incl. unit */
						'rangeMax'       => _x( 'Orders with at most %s.', 'shipments', 'shiptastic-for-woocommerce' ),
						/* translators: 1: minimum 2: maximum incl. unit */
						'rangeBetween'   => _x( 'Orders with %1$s to %2$s.', 'shipments', 'shiptastic-for-woocommerce' ),
						/* translators: %s list of steps */
						'requires'       => _x( 'Requires %s (added automatically).', 'shipments', 'shiptastic-for-woocommerce' ),
						'modes'          => array(
							'background'  => _x( 'Automatically', 'shipments', 'shiptastic-for-woocommerce' ),
							'interactive' => _x( 'Interactive', 'shipments', 'shiptastic-for-woocommerce' ),
						),
						'modeHints'      => array(
							'background'  => _x( 'Automatically', 'shipments', 'shiptastic-for-woocommerce' ),
							'interactive' => _x( 'Interactive', 'shipments', 'shiptastic-for-woocommerce' ),
						),
						'proMode'        => _x( 'Available with Shiptastic Pro', 'shipments', 'shiptastic-for-woocommerce' ),
						'scopes'         => array(
							'order'    => _x( 'Once per order', 'shipments', 'shiptastic-for-woocommerce' ),
							'shipment' => _x( 'Once per shipment', 'shipments', 'shiptastic-for-woocommerce' ),
						),
					),
					'orderDetails'      => array(
						'saved'          => _x( 'Address saved.', 'shipments', 'shiptastic-for-woocommerce' ),
						'discardChanges' => _x( 'Discard your changes to the address?', 'shipments', 'shiptastic-for-woocommerce' ),
						/* translators: %s list of fields */
						'required'       => _x( 'Please fill in: %s', 'shipments', 'shiptastic-for-woocommerce' ),
						'emailLabel'     => _x( 'Email', 'shipments', 'shiptastic-for-woocommerce' ),
						'selectOption'   => _x( 'Select an option…', 'shipments', 'shiptastic-for-woocommerce' ),
						'statuses'       => array_combine(
							array_map(
								function ( $status ) {
									return 'wc-' === substr( $status, 0, 3 ) ? substr( $status, 3 ) : $status;
								},
								array_keys( wc_get_order_statuses() )
							),
							array_values( wc_get_order_statuses() )
						),
					),
					// Runs (e.g. the report of a finished interactive run with files).
					'runs'              => array(
						'listTitle'         => _x( 'Recent runs', 'shipments', 'shiptastic-for-woocommerce' ),
						'backOverview'      => _x( 'Back to overview', 'shipments', 'shiptastic-for-woocommerce' ),
						/* translators: %s scope, e.g. a step title */
						'screenTitle'       => _x( 'Run: %s', 'shipments', 'shiptastic-for-woocommerce' ),
						'sessionTitle'      => _x( 'Session', 'shipments', 'shiptastic-for-woocommerce' ),
						'startSession'      => _x( 'Start', 'shipments', 'shiptastic-for-woocommerce' ),
						'continueSession'   => _x( 'Continue', 'shipments', 'shiptastic-for-woocommerce' ),
						/* translators: %d number of files */
						'files'             => _x( '%d file(s)', 'shipments', 'shiptastic-for-woocommerce' ),
						/* translators: 1: processed orders 2: total orders */
						'progress'          => _x( '%1$d of %2$d orders', 'shipments', 'shiptastic-for-woocommerce' ),
						/* translators: 1: succeeded orders 2: failed orders */
						'counts'            => _x( '%1$d done, %2$d failed', 'shipments', 'shiptastic-for-woocommerce' ),
						/* translators: %d number of files */
						'exportFiles'       => _x( '%d file(s) created by the steps.', 'shipments', 'shiptastic-for-woocommerce' ),
						/* translators: %d number of files */
						'exportFilesActive' => _x( '%d file(s) created so far, more are added while the run continues.', 'shipments', 'shiptastic-for-woocommerce' ),
						/* translators: %d number of errors */
						'errors'            => _x( '%d error(s)', 'shipments', 'shiptastic-for-woocommerce' ),
						'status'            => array(
							'running'   => _x( 'Running', 'shipments-run-status', 'shiptastic-for-woocommerce' ),
							'paused'    => _x( 'Paused', 'shipments-run-status', 'shiptastic-for-woocommerce' ),
							'stopped'   => _x( 'Stopped on an error', 'shipments-run-status', 'shiptastic-for-woocommerce' ),
							'completed' => _x( 'Finished', 'shipments-run-status', 'shiptastic-for-woocommerce' ),
							'cancelled' => _x( 'Cancelled', 'shipments-run-status', 'shiptastic-for-woocommerce' ),
						),
						'modes'             => array(
							'interactive' => _x( 'Worked in the app', 'shipments', 'shiptastic-for-woocommerce' ),
						),
					),
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
					// Packaging suggestions
					'packaging'         => array(
						'suggested'         => _x( 'Suggested', 'shipments-packaging', 'shiptastic-for-woocommerce' ),
						'none'              => _x( 'No packaging', 'shipments', 'shiptastic-for-woocommerce' ),
						/* translators: 1: packaging title 2: fill rate, e.g. 72 % */
						'option'            => _x( '%1$s · %2$s full', 'shipments-packaging', 'shiptastic-for-woocommerce' ),
						/* translators: %s packaging title */
						'doesNotFit'        => _x( '%s (does not fit)', 'shipments-packaging', 'shiptastic-for-woocommerce' ),
						/* translators: 1: packaging title 2: packaging title */
						'changed'           => _x( '%1$s no longer fits, changed to %2$s.', 'shipments-packaging', 'shiptastic-for-woocommerce' ),
						'noneFits'          => _x( 'No packaging fits the items.', 'shipments-packaging', 'shiptastic-for-woocommerce' ),
						'chooseManually'    => _x( 'No packaging fits the items. Please choose a packaging.', 'shipments-packaging', 'shiptastic-for-woocommerce' ),
						/* translators: %s list of items */
						'tooLarge'          => _x( 'Item too large for all packaging: %s', 'shipments-packaging', 'shiptastic-for-woocommerce' ),
						/* translators: %s list of items */
						'missingDimensions' => _x( 'Without dimensions (checked by weight only): %s', 'shipments-packaging', 'shiptastic-for-woocommerce' ),
						'proposed'          => _x( 'Suggested shipments based on the packaging. Adjust them if needed and save.', 'shipments-packaging', 'shiptastic-for-woocommerce' ),
					),
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

		// Managers see (and edit) inactive workflows as well.
		$where = current_user_can( 'manage_woocommerce' ) ? '' : "WHERE workflow_status = 'active'";

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Fixed condition.
		foreach ( $wpdb->get_col( "SELECT workflow_id FROM {$wpdb->stc_fulfillment_workflows} {$where} ORDER BY workflow_priority DESC, workflow_id ASC" ) as $workflow_id ) {
			$workflows[] = FulfillmentController::prepare_workflow( new Workflow( $workflow_id ) );
		}

		return apply_filters(
			'woocommerce_shiptastic_fulfillment_app_state',
			array(
				'screen'          => 'overview',
				// Active interactive runs of the user (continued instead of started anew).
				'sessions'        => array_map(
					function ( $run ) {
						return array(
							'id'          => $run->get_id(),
							'workflow_id' => $run->workflow_id,
						);
					},
					Run::query(
						array(
							'user_id' => get_current_user_id(),
							'source'  => 'interactive',
							'status'  => array( 'running' ),
						)
					)
				),
				'workflows'       => $workflows,
				'counts'          => Queue::count_by_status(),
				'queue'           => array(
					'status'     => 'pending',
					'items'      => array(),
					'page'       => 1,
					'pages'      => 0,
					'total'      => 0,
					'perPage'    => 25,
					'loading'    => true,
					'workflowId' => 0,
				),
				'run'             => array(
					'workflowId' => 0,
					'items'      => array(),
					'total'      => 0,
					'done'       => 0,
					'exhausted'  => false,
					'skipped'    => array(),
					// Orders done offline, waiting for the outbox to be synced (outbox.js).
					'waiting'    => array(),
				),
				// Offline outbox (outbox.js): pending mutations, replay state, items which could not be synced.
				'sync'            => array(
					'pending'   => 0,
					'running'   => false,
					'authError' => false,
					'conflicts' => array(),
				),
				'currentKey'      => '',
				'showValidation'  => false,
				'stepListOpen'    => false,
				// Progress panel of the automatic steps (app.js actions.runAutomaticSteps()).
				'autoSteps'       => array(
					'active' => false,
					'paused' => false,
					'slow'   => false,
					'steps'  => array(),
				),
				// An automatic step handled interactively after it failed (key + error message).
				'manualStep'      => array(
					'key'     => '',
					'message' => '',
				),
				'workflowEditor'  => array(
					'open'    => false,
					'loading' => false,
					'saving'  => false,
					'dirty'   => false,
					'error'   => '',
					'limited' => new \stdClass(),
					'form'    => array(
						'id'       => 0,
						'title'    => '',
						'status'   => 'active',
						'priority' => 0,
						'mode'     => 'interactive',
						'steps'    => array(),
						'filters'  => Workflow::sanitize_filters( array() ),
					),
				),
				// Loaded on demand (workflow editor).
				'workflowChoices' => array(
					'loaded'    => false,
					'actions'   => array(),
					'locations' => array(),
					'providers' => array(),
					'methods'   => array(),
					'modes'     => array(),
				),
				// Loaded on demand (order details).
				'countries'       => array(),
				'orderDetails'    => array(
					'open'    => false,
					'loading' => false,
					'saving'  => false,
					'dirty'   => false,
					'error'   => '',
					'data'    => null,
					'form'    => new \stdClass(),
				),
				// Runs listed in the overview, the run shown on the report screen.
				'runs'            => array(),
				'report'          => array(
					'run'     => null,
					'loading' => false,
				),
				'packaging'       => ShipmentLayout::get_packaging_data(),
				'providers'       => ShipmentLayout::get_provider_data(),
				'overlay'         => array(
					'open'      => false,
					'saving'    => false,
					'dirty'     => false,
					'activeKey' => '',
					'items'     => array(),
					'shipments' => array(),
					// Shipments proposed for items assigned for the first time (packaging suggestions).
					'proposed'  => false,
				),
				'view'            => new \stdClass(),
				'form'            => new \stdClass(),
				'busy'            => false,
				'online'          => true,
				'notice'          => array(
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
