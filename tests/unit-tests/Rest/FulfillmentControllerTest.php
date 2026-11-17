<?php

namespace Rest;

use Vendidero\Shiptastic\BulkFulfillments\Queue;
use Vendidero\Shiptastic\BulkFulfillments\QueueSync;
use Vendidero\Shiptastic\BulkFulfillments\StepLog;
use Vendidero\Shiptastic\BulkFulfillments\Workflow;
use WP_REST_Request;

/**
 * Bulk fulfillment REST API.
 */
if ( ! class_exists( __NAMESPACE__ . '\Test_Rest_Document_Action' ) ) {
	/**
	 * A background action creating a file per shipment (e.g. a delivery note).
	 */
	class Test_Rest_Document_Action extends \Vendidero\Shiptastic\BulkFulfillments\Action {
		public static function get_name() {
			return 'test_document';
		}

		public static function get_title() {
			return 'Test document';
		}

		public static function get_description() {
			return '';
		}

		public static function get_modes() {
			return array( 'background' );
		}

		public static function get_scopes() {
			return array( 'shipment' );
		}

		public function run( $context ) {
			return \Vendidero\Shiptastic\BulkFulfillments\StepResult::done()->add_file_content( 'document.pdf', '%PDF-document' );
		}
	}
}

class FulfillmentControllerTest extends \Vendidero\Shiptastic\Tests\Framework\UnitRestTestCase {

	const BASE = '/wc/v3/shiptastic/fulfillment';

	protected $admin;

	/**
	 * A user who may fulfill orders but not manage workflows.
	 */
	protected $picker;

	public function setUp(): void {
		parent::setUp();

		update_option( 'woocommerce_shiptastic_enable_auto_packing', 'no' );

		$this->admin  = $this->factory->user->create( array( 'role' => 'administrator' ) );
		$this->picker = $this->factory->user->create( array( 'role' => 'subscriber' ) );

		get_user_by( 'id', $this->picker )->add_cap( 'edit_shop_orders' );
	}

	protected function request( $method, $path, $params = array(), $headers = array() ) {
		$request = new WP_REST_Request( $method, self::BASE . $path );

		if ( 'GET' === $method ) {
			$request->set_query_params( $params );
		} else {
			$request->set_body_params( $params );
		}

		foreach ( $headers as $name => $value ) {
			$request->set_header( $name, $value );
		}

		return $this->server->dispatch( $request );
	}

	protected function create_workflow( $steps = array( 'pick', 'pack' ), $props = array() ) {
		$workflow = new Workflow();
		$workflow->set_props(
			array_merge(
				array(
					'title' => 'Pick & Pack',
					'steps' => $steps,
				),
				$props
			)
		);
		$workflow->save();

		return $workflow;
	}

	protected function create_order( $quantity = 2 ) {
		$product = \WC_Helper_Product::create_simple_product();
		$order   = wc_create_order();

		$item = new \WC_Order_Item_Product();
		$item->set_props(
			array(
				'product'  => $product,
				'quantity' => $quantity,
				'subtotal' => 10,
				'total'    => 10,
			)
		);
		$order->add_item( $item );

		$shipping = new \WC_Order_Item_Shipping();
		$shipping->set_props(
			array(
				'method_id'   => 'flat_rate',
				'instance_id' => 1,
				'total'       => '5',
			)
		);
		$order->add_item( $shipping );
		$order->set_shipping_country( 'DE' );
		$order->set_shipping_first_name( 'Max' );
		$order->set_shipping_last_name( 'Mustermann' );
		$order->set_shipping_address_1( 'Musterstr. 12' );
		$order->set_shipping_postcode( '12222' );
		$order->set_shipping_city( 'Berlin' );
		$order->set_status( 'processing' );
		$order->save();

		QueueSync::sync_order( $order->get_id() );

		return wc_get_order( $order->get_id() );
	}

	public function test_workflow_crud_and_permissions() {
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'subscriber' ) ) );
		$this->assertEquals( 403, $this->request( 'GET', '/workflows' )->get_status() );

		// May read workflows (to choose one), but not manage them
		wp_set_current_user( $this->picker );
		$this->assertEquals( 200, $this->request( 'GET', '/workflows' )->get_status() );
		$this->assertEquals( 403, $this->request( 'POST', '/workflows', array( 'title' => 'X' ) )->get_status() );

		wp_set_current_user( $this->admin );

		$response = $this->request(
			'POST',
			'/workflows',
			array(
				'title'   => 'DHL Pick & Pack',
				'steps'   => array( 'pick', 'pack' ),
				'filters' => array( 'shipping_providers' => array( 'dhl' ) ),
			)
		);

		$this->assertEquals( 201, $response->get_status() );
		$workflow = $response->get_data();
		$this->assertEquals( 'DHL Pick & Pack', $workflow['title'] );
		$this->assertArrayNotHasKey( 'run_as', $workflow );
		$this->assertArrayNotHasKey( 'schedule', $workflow );
		$this->assertEquals( array( 'dhl' ), $workflow['filters']['shipping_providers'] );
		$this->assertEquals( array( 'pick', 'pack' ), wp_list_pluck( $workflow['steps'], 'action' ) );
		$this->assertEquals( 'interactive', $workflow['steps'][0]['mode'] );

		// Invalid configuration is rejected and not saved
		$response = $this->request(
			'PUT',
			'/workflows/' . $workflow['id'],
			array(
				'steps' => array( 'pick', 'unknown' ),
			)
		);
		$this->assertEquals( 400, $response->get_status() );
		$this->assertEquals( array( 'pick', 'pack' ), ( new Workflow( $workflow['id'] ) )->get_step_actions() );

		// Interactive workflows only (extensions may add modes): unknown modes fall back to interactive
		$this->assertEquals( 'interactive', $this->request( 'PUT', '/workflows/' . $workflow['id'], array( 'mode' => 'background' ) )->get_data()['mode'] );

		$response = $this->request( 'PUT', '/workflows/' . $workflow['id'], array( 'title' => 'Renamed' ) );
		$this->assertEquals( 'Renamed', $response->get_data()['title'] );

		$this->assertEquals( 'Renamed', $this->request( 'GET', '/workflows/' . $workflow['id'] )->get_data()['title'] );
		$this->assertEquals( 200, $this->request( 'DELETE', '/workflows/' . $workflow['id'] )->get_status() );
		$this->assertEquals( 404, $this->request( 'GET', '/workflows/' . $workflow['id'] )->get_status() );
	}

	public function test_queue_pagination_and_workflow_filter() {
		$warehouse = new \Vendidero\Shiptastic\Warehouse();
		$warehouse->set_title( 'Berlin' );
		$warehouse->save();

		for ( $i = 0; $i < 3; $i++ ) {
			$this->create_order( 1 );
		}

		$workflow = $this->create_workflow( array( 'pick' ) );
		$other    = $this->create_workflow( array( 'pick' ), array( 'filters' => array( 'locations' => array( $warehouse->get_name() ) ) ) );
		wp_set_current_user( $this->picker );

		$page = $this->request(
			'GET',
			'/queue',
			array(
				'status'   => 'pending',
				'per_page' => 2,
				'page'     => 2,
			)
		);
		$data = $page->get_data();

		$this->assertCount( 1, $data['items'] );
		$this->assertEquals( 3, $data['total'] );
		$this->assertEquals( 2, $data['pages'] );
		$this->assertEquals( 3, $page->get_headers()['X-WP-Total'] );

		// Items of a workflow only (its filters)
		$this->assertEquals( 3, $this->request( 'GET', '/queue', array( 'status' => 'pending', 'workflow_id' => $workflow->get_id() ) )->get_data()['total'] );
		$this->assertEquals( 0, $this->request( 'GET', '/queue', array( 'status' => 'pending', 'workflow_id' => $other->get_id() ) )->get_data()['counts']['pending'] );

		wp_set_current_user( $this->admin );
		$workflows = wp_list_pluck( $this->request( 'GET', '/workflows' )->get_data(), 'open_count', 'id' );
		$this->assertEquals( 3, $workflows[ $workflow->get_id() ] );
		$this->assertEquals( 0, $workflows[ $other->get_id() ] );
	}

	public function test_reopen_step() {
		$this->setExpectedIncorrectUsage( 'is_internal_meta_key' );

		$order         = $this->create_order( 2 );
		$order_item_id = current( $order->get_items() )->get_id();
		$workflow      = $this->create_workflow();
		wp_set_current_user( $this->picker );

		$claim = $this->request( 'POST', '/workflows/' . $workflow->get_id() . '/claim' )->get_data();
		$item  = $claim['items'][0];

		// Pick is not done yet
		$this->assertEquals( 400, $this->request( 'POST', '/queue/' . $item['id'] . '/steps/pick/reopen', array( 'token' => $claim['token'] ) )->get_status() );

		$data = $this->request(
			'POST',
			'/queue/' . $item['id'] . '/steps/pick',
			array(
				'token' => $claim['token'],
				'data'  => array( 'picked' => array( $order_item_id => 2 ) ),
			)
		)->get_data();
		$this->assertEquals( 'pack', $data['current']['step']['action'] );
		// The packaging + dimensions to use are shown when packing
		$this->assertArrayHasKey( 'packaging', $data['current']['view_data'] );
		$this->assertNotEmpty( $data['current']['view_data']['dimensions'] );

		// Back to pick: the picked quantities prefill the form
		$data = $this->request( 'POST', '/queue/' . $item['id'] . '/steps/pick/reopen', array( 'token' => $claim['token'] ) )->get_data();
		$this->assertEquals( 'pick', $data['current']['step']['key'] );
		$this->assertEquals( array( $order_item_id => 2 ), $data['current']['view_data']['previous']['picked'] );
		$this->assertFalse( wp_list_pluck( $data['steps'], 'is_done', 'key' )['pick'] );

		// Background steps cannot be reopened
		$this->assertEquals( 400, $this->request( 'POST', '/queue/' . $item['id'] . '/steps/create_shipments/reopen', array( 'token' => $claim['token'] ) )->get_status() );
	}

	public function test_order_address_fields_via_core_api() {
		$callback_values = array();

		// Custom fields, like plugins add them to the edit order page
		$add_fields = function ( $fields ) use ( &$callback_values ) {
			$fields['title']       = array(
				'label'   => 'Title',
				'type'    => 'select',
				'options' => array(
					''   => 'None',
					'mr' => 'Mr.',
					'ms' => 'Ms.',
				),
			);
			$fields['post_number'] = array(
				'label'           => 'Post number',
				'update_callback' => function ( $id, $value, $order ) use ( &$callback_values ) {
					$callback_values[ $id ] = $value;
					$order->update_meta_data( 'custom_post_number', $value );
				},
				'value'           => 'from-callback',
			);

			return $fields;
		};
		add_filter( 'woocommerce_admin_shipping_fields', $add_fields );

		$order = $this->create_order( 1 );
		$order->update_meta_data( '_shipping_title', 'ms' );
		$order->save();

		wp_set_current_user( $this->admin );

		// Without the flag, the core response is unchanged
		$request = new WP_REST_Request( 'GET', '/wc/v3/orders/' . $order->get_id() );
		$this->assertArrayNotHasKey( 'stc_shipping_fields', $this->server->dispatch( $request )->get_data() );

		$request = new WP_REST_Request( 'GET', '/wc/v3/orders/' . $order->get_id() );
		$request->set_query_params( array( 'stc_address_fields' => 1 ) );
		$fields = array_column( $this->server->dispatch( $request )->get_data()['stc_shipping_fields'], null, 'key' );

		$this->assertEquals( 'Max', $fields['first_name']['value'] );
		$this->assertEquals( 'country', $fields['country']['type'] );
		$this->assertEquals( 'tel', $fields['phone']['type'] );
		$this->assertEquals( 'select', $fields['title']['type'] );
		$this->assertEquals( 'ms', $fields['title']['value'] );
		$this->assertCount( 3, $fields['title']['options'] );
		$this->assertEquals( 'from-callback', $fields['post_number']['value'] );

		// Save like the edit order page: setter, meta, update callback
		$request = new WP_REST_Request( 'PUT', '/wc/v3/orders/' . $order->get_id() );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body(
			wp_json_encode(
				array(
					'stc_address_fields' => 1,
					'stc_shipping'       => array(
						'first_name'  => 'Erika',
						'city'        => 'Hamburg',
						'title'       => 'mr',
						'post_number' => '12345',
					),
					'billing'            => array( 'email' => 'fulfillment@example.org' ),
				)
			)
		);
		$response = $this->server->dispatch( $request );
		$this->assertEquals( 200, $response->get_status() );
		$this->assertArrayHasKey( 'stc_shipping_fields', $response->get_data() );

		$order = wc_get_order( $order->get_id() );
		$this->assertEquals( 'Erika', $order->get_shipping_first_name() );
		$this->assertEquals( 'Hamburg', $order->get_shipping_city() );
		$this->assertEquals( 'mr', $order->get_meta( '_shipping_title' ) );
		$this->assertEquals( '12345', $order->get_meta( 'custom_post_number' ) );
		$this->assertEquals( array( '_shipping_post_number' => '12345' ), $callback_values );
		$this->assertEquals( 'fulfillment@example.org', $order->get_billing_email() );

		remove_filter( 'woocommerce_admin_shipping_fields', $add_fields );
	}

	public function test_workflow_editor_data() {
		$group = new \Vendidero\Shiptastic\Warehouse();
		$group->set_props(
			array(
				'title' => 'North',
				'type'  => 'group',
			)
		);
		$group->save();

		$grouped = new \Vendidero\Shiptastic\Warehouse();
		$grouped->set_props(
			array(
				'title' => 'Hamburg',
				'group' => $group->get_name(),
			)
		);
		$grouped->save();

		$single = new \Vendidero\Shiptastic\Warehouse();
		$single->set_title( 'Berlin' );
		$single->save();

		\Vendidero\Shiptastic\Warehouse\Helper::clear_cache();

		// Managers only
		wp_set_current_user( $this->picker );
		$this->assertEquals( 403, $this->request( 'GET', '/workflows/editor' )->get_status() );

		wp_set_current_user( $this->admin );
		$data = $this->request( 'GET', '/workflows/editor' )->get_data();

		$actions = array_column( $data['actions'], null, 'name' );
		// Steps of actions supporting user interaction run interactively, others (e.g. creating shipments) automatically
		$this->assertEquals( array( 'interactive' ), $actions['mark_ready']['modes'] );
		$this->assertEquals( 'interactive', $actions['mark_ready']['default_mode'] );
		$this->assertEquals( array( 'background' ), $actions['create_shipments']['modes'] );
		$this->assertEquals( array( 'interactive' ), wp_list_pluck( $data['modes'], 'value' ) );
		// Running steps supporting user interaction automatically requires Shiptastic Pro (presented with a pro marker)
		$this->assertFalse( \Vendidero\Shiptastic\BulkFulfillments\App::has_automation() );
		$this->assertEquals( array( 'background' ), $actions['mark_ready']['pro_modes'] );
		$this->assertEquals( array(), $actions['create_shipments']['pro_modes'] );
		$this->assertEquals( array(), $actions['pick']['pro_modes'] );
		$this->assertEquals( 'interactive', $actions['pick']['default_mode'] );
		$this->assertEquals( 'shipment', $actions['pack']['default_scope'] );
		$this->assertEquals( array( 'shipment' ), $actions['pack']['scopes'] );
		$this->assertEmpty( $actions['pack']['settings'] );
		$this->assertEquals( 'checkbox', array_column( $actions['mark_ready']['settings'], null, 'key' )['require_shipping_provider']['type'] );

		// Locations: default, groups and warehouses without group
		$locations = wp_list_pluck( $data['locations'], 'value' );
		$this->assertContains( '', $locations );
		$this->assertContains( $group->get_name(), $locations );
		$this->assertContains( $single->get_name(), $locations );
		$this->assertNotContains( $grouped->get_name(), $locations );

		$this->assertArrayNotHasKey( 'users', $data );
	}

	public function test_update_shipments() {
		$this->setExpectedIncorrectUsage( 'is_internal_meta_key' );

		$order         = $this->create_order( 3 );
		$order_item_id = current( $order->get_items() )->get_id();
		$workflow      = $this->create_workflow();
		wp_set_current_user( $this->picker );

		$claim = $this->request( 'POST', '/workflows/' . $workflow->get_id() . '/claim' )->get_data();
		$item  = $claim['items'][0];

		// The layout is part of the run payload (shipments overlay)
		$this->assertEquals( 3, $item['layout']['items'][0]['total'] );
		$this->assertCount( 1, $item['layout']['shipments'] );

		$first     = $item['layout']['shipments'][0]['id'];
		$packaging = current( wc_stc_get_packaging_list() );
		$response  = $this->request(
			'POST',
			'/queue/' . $item['id'] . '/shipments',
			array(
				'token'           => $claim['token'],
				'idempotency_key' => 'layout-1',
				'shipments'       => array(
					array(
						'id'    => $first,
						'items' => array( $order_item_id => 2 ),
						'props' => array(
							'packaging_id' => $packaging->get_id(),
							// Ignored: the packaging defines the dimensions
							'length'       => '99',
						),
					),
					array(
						'id'    => 'new-1',
						'items' => array( $order_item_id => 1 ),
						'props' => array(
							// No packaging: dimensions may be set
							'packaging_id' => 0,
							'weight'       => '0.7',
							'length'       => '30',
						),
					),
				),
			)
		);

		$this->assertEquals( 200, $response->get_status() );
		$data = $response->get_data();

		$new_id = $data['id_map']->{'new-1'};
		$this->assertEquals( array( $first, $new_id ), wp_list_pluck( $data['layout']['shipments'], 'id' ) );
		$this->assertEquals( 'pick', $data['current']['step']['key'] );
		$this->assertEquals( array( 'pick', 'pack:' . $first, 'pack:' . $new_id ), array_values( array_diff( wp_list_pluck( $data['steps'], 'key' ), array( 'create_shipments' ) ) ) );

		$first_shipment = wc_stc_get_shipment( $first );
		$new_shipment   = wc_stc_get_shipment( $new_id );

		$this->assertEquals( $packaging->get_id(), $first_shipment->get_packaging_id() );
		$this->assertNotEquals( 99, (float) $first_shipment->get_length() );
		$this->assertEquals( 0.7, (float) $new_shipment->get_weight() );
		$this->assertEquals( 30, (float) $new_shipment->get_length() );

		// Invalid layout
		$invalid = $this->request(
			'POST',
			'/queue/' . $item['id'] . '/shipments',
			array(
				'token'     => $claim['token'],
				'shipments' => array(
					array(
						'id'    => $first,
						'items' => array( $order_item_id => 4 ),
					),
				),
			)
		);

		$this->assertEquals( 400, $invalid->get_status() );

		// Wrong token
		$this->assertEquals( 409, $this->request( 'POST', '/queue/' . $item['id'] . '/shipments', array( 'token' => 'invalid', 'shipments' => array() ) )->get_status() );
	}

	public function register_document_action( $actions ) {
		$actions['test_document'] = Test_Rest_Document_Action::class;

		return $actions;
	}

	public function test_interactive_run() {
		$this->setExpectedIncorrectUsage( 'is_internal_meta_key' );
		add_filter( 'woocommerce_shiptastic_fulfillment_actions', array( $this, 'register_document_action' ) );

		$order    = $this->create_order( 2 );
		$workflow = $this->create_workflow( array( 'pick', 'test_document' ) );
		wp_set_current_user( $this->picker );

		// Starting twice continues the active run
		$response = $this->request( 'POST', '/runs', array( 'workflow_id' => $workflow->get_id(), 'mode' => 'interactive' ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$this->assertEquals( 201, $response->get_status() );
		$run = $response->get_data();
		$this->assertTrue( $run['is_interactive'] );
		$this->assertEquals( 'running', $run['status'] );
		$this->assertEquals( $run['id'], $this->request( 'POST', '/runs', array( 'workflow_id' => $workflow->get_id(), 'mode' => 'interactive' ) )->get_data()['id'] ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound

		// Claims share the run's token, the run's orders can be loaded again (reload, other device)
		$claim = $this->request( 'POST', '/workflows/' . $workflow->get_id() . '/claim', array( 'run_id' => $run['id'] ) )->get_data();
		$this->assertCount( 1, $claim['items'] );
		$this->assertEquals( 1, $claim['run']['total'] );
		$item  = $claim['items'][0];
		$items = $this->request( 'GET', '/runs/' . $run['id'] . '/items' )->get_data();
		$this->assertEquals( array( $item['id'] ), wp_list_pluck( $items['items'], 'id' ) );
		$this->assertEquals( $claim['token'], $items['items'][0]['lock_token'] );

		// Listed as interactive run (default), not within runs of other sources (e.g. automations of extensions)
		$this->assertContains( $run['id'], wp_list_pluck( $this->request( 'GET', '/runs' )->get_data(), 'id' ) );
		$this->assertNotContains( $run['id'], wp_list_pluck( $this->request( 'GET', '/runs', array( 'source' => 'automation' ) )->get_data(), 'id' ) );

		// Other run modes are not supported without extensions
		$this->assertEquals( 400, $this->request( 'POST', '/runs', array( 'workflow_id' => $workflow->get_id(), 'mode' => 'autonomous' ) )->get_status() ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound

		// Picking completes the order: the document step's file is added to the run's export
		$order_item_id = current( $order->get_items() )->get_id();
		$data          = $this->request(
			'POST',
			'/queue/' . $item['id'] . '/steps/pick',
			array(
				'token'           => $claim['token'],
				'version'         => $item['version'],
				'idempotency_key' => 'interactive-pick',
				'data'            => array( 'picked' => array( $order_item_id => 2 ) ),
			)
		)->get_data();

		$this->assertEquals( 'completed', $data['current']['status'] );
		// The step running automatically after picking is reported (shown in the app)
		$this->assertEquals( array( 'test_document' ), wp_list_pluck( $data['current']['automated'], 'action' ) );
		$this->assertEquals( 1, $data['run']['export_files'] );
		$this->assertNotEmpty( $data['run']['export_url'] );

		// Ending the run keeps the export
		$finished = $this->request( 'POST', '/runs/' . $run['id'] . '/finish' )->get_data();
		$this->assertEquals( 'completed', $finished['status'] );
		$this->assertEquals( 1, $finished['succeeded'] );
		$this->assertEquals( 1, $finished['export_files'] );
		$this->assertEquals( 409, $this->request( 'POST', '/workflows/' . $workflow->get_id() . '/claim', array( 'run_id' => $run['id'] ) )->get_status() );

		// Another user cannot use the run
		wp_set_current_user( $this->admin );
		$this->assertEquals( 404, $this->request( 'GET', '/runs/' . $run['id'] . '/items' )->get_status() );

		// Idle runs are finished by the daily cleanup, their orders released
		wp_set_current_user( $this->picker );
		$this->create_order( 1 );
		$idle  = \Vendidero\Shiptastic\BulkFulfillments\RunProcessor::start_interactive( $workflow, $this->picker );
		$claim = $this->request( 'POST', '/workflows/' . $workflow->get_id() . '/claim', array( 'run_id' => $idle->get_id() ) )->get_data();
		$this->assertCount( 1, $claim['items'] );

		global $wpdb;
		$wpdb->update( $wpdb->stc_fulfillment_runs, array( 'run_date_modified_gmt' => gmdate( 'Y-m-d H:i:s', time() - 13 * HOUR_IN_SECONDS ) ), array( 'run_id' => $idle->get_id() ) );
		\Vendidero\Shiptastic\BulkFulfillments\RunProcessor::finish_idle_interactive();

		$this->assertEquals( 'completed', \Vendidero\Shiptastic\BulkFulfillments\Run::get( $idle->get_id() )->status );
		$this->assertEquals( 'pending', \Vendidero\Shiptastic\BulkFulfillments\Queue::get( $claim['items'][0]['id'] )->get_status() );

		remove_filter( 'woocommerce_shiptastic_fulfillment_actions', array( $this, 'register_document_action' ) );
	}

	/**
	 * Steps running automatically (actions without user interaction) run one by one within the app (progress).
	 */
	public function test_automatic_steps_one_by_one() {
		$this->setExpectedIncorrectUsage( 'is_internal_meta_key' );
		add_filter( 'woocommerce_shiptastic_fulfillment_actions', array( $this, 'register_document_action' ) );

		$order    = $this->create_order( 1 );
		$workflow = $this->create_workflow( array( 'pick', 'test_document' ) );
		wp_set_current_user( $this->picker );

		$claim         = $this->request( 'POST', '/workflows/' . $workflow->get_id() . '/claim' )->get_data();
		$item          = $claim['items'][0];
		$order_item_id = current( $order->get_items() )->get_id();

		// Submitting the interactive step returns the next automatic step instead of running it
		$data = $this->request(
			'POST',
			'/queue/' . $item['id'] . '/steps/pick',
			array(
				'token'           => $claim['token'],
				'version'         => $item['version'],
				'idempotency_key' => 'one-by-one-pick',
				'data'            => array( 'picked' => array( $order_item_id => 1 ) ),
				'auto_steps'      => 0,
			)
		)->get_data();
		$this->assertEquals( 'automatic', $data['current']['status'] );
		$this->assertEquals( 0, strpos( $data['current']['step']['key'], 'test_document' ) );
		$this->assertEquals( array( 'background' ), $data['current']['step']['modes'] );

		// One step per request
		$data = $this->request(
			'POST',
			'/queue/' . $item['id'] . '/run',
			array(
				'token'      => $claim['token'],
				'auto_steps' => 1,
			)
		)->get_data();
		$this->assertEquals( 'completed', $data['current']['status'] );
		$this->assertEquals( array( 'test_document' ), wp_list_pluck( $data['current']['automated'], 'action' ) );

		remove_filter( 'woocommerce_shiptastic_fulfillment_actions', array( $this, 'register_document_action' ) );
	}

	public function test_claim_steps_idempotency_and_batch() {
		// Pre-existing: creating shipment items triggers an incorrect usage notice (customs description meta).
		$this->setExpectedIncorrectUsage( 'is_internal_meta_key' );

		$order    = $this->create_order( 2 );
		$workflow = $this->create_workflow();
		wp_set_current_user( $this->picker );

		// Claim: background steps ran (shipments created), first interactive step is prefetched
		$claim = $this->request( 'POST', '/workflows/' . $workflow->get_id() . '/claim' )->get_data();
		$this->assertNotEmpty( $claim['token'] );
		$this->assertCount( 1, $claim['items'] );

		$item = $claim['items'][0];
		$this->assertEquals( $claim['token'], $item['lock_token'] );
		$this->assertEquals( 'needs_input', $item['current']['status'] );
		$this->assertEquals( 'pick', $item['current']['step']['key'] );
		$this->assertEquals( 2, $item['current']['view_data']['items'][0]['quantity'] );
		$this->assertEquals( array( 'create_shipments', 'pick', 'pack' ), wp_list_pluck( $item['steps'], 'action' ) );

		$order_item_id = current( $order->get_items() )->get_id();
		$pick          = array(
			'token'           => $claim['token'],
			'version'         => $item['version'],
			'idempotency_key' => 'pick-1',
			'data'            => array( 'picked' => array( $order_item_id => 2 ) ),
		);

		// Outdated version
		$response = $this->request( 'POST', '/queue/' . $item['id'] . '/steps/pick', array_merge( $pick, array( 'version' => $item['version'] - 1 ) ) );
		$this->assertEquals( 409, $response->get_status() );
		$this->assertEquals( 'queue_version_conflict', $response->get_data()['code'] );

		$response = $this->request( 'POST', '/queue/' . $item['id'] . '/steps/pick', $pick );
		$this->assertEquals( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertEquals( 'done', $data['step_result']['status'] );
		$this->assertEquals( 'pack', $data['current']['step']['action'] );
		$pack_key = $data['current']['step']['key'];

		// Retry with the same key: stored response, no second execution
		$retry = $this->request( 'POST', '/queue/' . $item['id'] . '/steps/pick', $pick );
		$this->assertEquals( 'true', $retry->get_headers()['Idempotent-Replayed'] );
		$this->assertEquals( $data, $retry->get_data() );

		// Batch (offline outbox): pack + an invalid request
		$batch = $this->request(
			'POST',
			'/batch',
			array(
				'requests' => array(
					array(
						'path' => 'queue/' . $item['id'] . '/steps/' . $pack_key,
						'body' => array(
							'token'           => $claim['token'],
							'idempotency_key' => 'pack-1',
							'data'            => array(
								'weight' => '2',
								'packed' => array( $order_item_id => 2 ),
							),
						),
					),
					array(
						'path' => 'workflows',
						'body' => array( 'title' => 'Not allowed' ),
					),
				),
			)
		)->get_data();

		$this->assertEquals( 200, $batch['responses'][0]['status'] );
		$this->assertEquals( 'completed', $batch['responses'][0]['body']['current']['status'] );
		$this->assertEquals( 400, $batch['responses'][1]['status'] );

		$this->assertEquals( 'pending', Queue::get( $item['id'] )->get_status() );
		$this->assertEqualsCanonicalizing( array( 'create_shipments', 'pick', 'pack' ), StepLog::get_done_actions( $item['id'] ) );

		// The lock was released - further writes with the token fail
		$this->assertEquals( 409, $this->request( 'POST', '/queue/' . $item['id'] . '/run', array( 'token' => $claim['token'] ) )->get_status() );
	}

	public function test_heartbeat_release_skip_reset() {
		$this->create_order();
		$workflow = $this->create_workflow();
		wp_set_current_user( $this->picker );

		$claim = $this->request(
			'POST',
			'/workflows/' . $workflow->get_id() . '/claim',
			array( 'run' => false )
		)->get_data();
		$item  = $claim['items'][0];

		$this->assertArrayNotHasKey( 'current', $item );
		$this->assertEquals( 1, $this->request( 'POST', '/queue/heartbeat', array( 'token' => $claim['token'] ) )->get_data()['extended'] );

		// Another user may not write to the item
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'subscriber' ) ) );
		get_user_by( 'id', get_current_user_id() )->add_cap( 'edit_shop_orders' );
		$this->assertEquals( 403, $this->request( 'POST', '/queue/' . $item['id'] . '/run', array( 'token' => $claim['token'] ) )->get_status() );

		wp_set_current_user( $this->picker );
		$this->assertEquals( 409, $this->request( 'POST', '/queue/' . $item['id'] . '/release', array( 'token' => 'invalid' ) )->get_status() );
		$this->assertEquals( 'pending', $this->request( 'POST', '/queue/' . $item['id'] . '/release', array( 'token' => $claim['token'] ) )->get_data()['status'] );

		$claim = $this->request( 'POST', '/workflows/' . $workflow->get_id() . '/claim', array( 'run' => false ) )->get_data();
		$skip  = $this->request(
			'POST',
			'/queue/' . $item['id'] . '/skip',
			array(
				'token'   => $claim['token'],
				'message' => 'Item damaged',
			)
		)->get_data();
		$this->assertEquals( 'error', $skip['status'] );
		$this->assertEquals( 'Item damaged', $skip['last_error'] );

		// Only managers may reset items from the inbox
		$this->assertEquals( 403, $this->request( 'POST', '/queue/' . $item['id'] . '/reset' )->get_status() );

		wp_set_current_user( $this->admin );
		$this->assertEquals( 'pending', $this->request( 'POST', '/queue/' . $item['id'] . '/reset' )->get_data()['status'] );

		$queue = $this->request( 'GET', '/queue', array( 'status' => 'pending' ) )->get_data();
		$this->assertEquals( array( $item['id'] ), wp_list_pluck( $queue['items'], 'id' ) );
		$this->assertEquals( 1, $queue['counts']['pending'] );
	}
}
