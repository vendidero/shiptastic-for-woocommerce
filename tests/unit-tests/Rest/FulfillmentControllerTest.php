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
					'title'  => 'Pick & Pack',
					'steps'  => $steps,
					'run_as' => $this->admin,
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
		$this->assertEquals( $this->admin, $workflow['run_as'] );
		$this->assertEquals( array( 'dhl' ), $workflow['filters']['shipping_providers'] );
		$this->assertEquals( array( 'pick', 'pack' ), wp_list_pluck( $workflow['steps'], 'action' ) );
		$this->assertEquals( 'interactive', $workflow['steps'][0]['mode'] );

		// Invalid configuration is rejected and not saved
		$response = $this->request(
			'PUT',
			'/workflows/' . $workflow['id'],
			array(
				'mode' => 'background',
			)
		);
		$this->assertEquals( 400, $response->get_status() );
		$this->assertEquals( 'interactive', ( new Workflow( $workflow['id'] ) )->get_mode() );

		$response = $this->request( 'PUT', '/workflows/' . $workflow['id'], array( 'title' => 'Renamed' ) );
		$this->assertEquals( 'Renamed', $response->get_data()['title'] );

		$this->assertEquals( 'Renamed', $this->request( 'GET', '/workflows/' . $workflow['id'] )->get_data()['title'] );
		$this->assertEquals( 200, $this->request( 'DELETE', '/workflows/' . $workflow['id'] )->get_status() );
		$this->assertEquals( 404, $this->request( 'GET', '/workflows/' . $workflow['id'] )->get_status() );
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
							'data'            => array( 'weight' => '2' ),
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
