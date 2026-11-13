<?php

use Vendidero\Shiptastic\BulkFulfillments\Action;
use Vendidero\Shiptastic\BulkFulfillments\ActionRegistry;
use Vendidero\Shiptastic\BulkFulfillments\Queue;
use Vendidero\Shiptastic\BulkFulfillments\QueueSync;
use Vendidero\Shiptastic\BulkFulfillments\Runner;
use Vendidero\Shiptastic\BulkFulfillments\Scheduler;
use Vendidero\Shiptastic\BulkFulfillments\ShipmentLayout;
use Vendidero\Shiptastic\BulkFulfillments\StepLog;
use Vendidero\Shiptastic\BulkFulfillments\StepResult;
use Vendidero\Shiptastic\BulkFulfillments\Workflow;

/**
 * A background action (e.g. label creation) used for testing.
 */
class Test_Fulfillment_Label_Action extends Action {
	public static $runs = 0;

	public static function get_name() {
		return 'test_label';
	}

	public static function get_title() {
		return 'Test label';
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
		++self::$runs;

		return StepResult::done( array( 'shipment' => $context->get_shipment()->get_id() ) );
	}
}

/**
 * A background action failing for every order.
 */
class Test_Fulfillment_Failing_Action extends Action {
	public static function get_name() {
		return 'test_fail';
	}

	public static function get_title() {
		return 'Test fail';
	}

	public static function get_description() {
		return '';
	}

	public static function get_modes() {
		return array( 'background' );
	}

	public static function get_requires() {
		return array();
	}

	public function run( $context ) {
		return StepResult::error( 'Carrier API unavailable', 'carrier_error' );
	}
}

/**
 * Actions API, step planning, execution, background chunks and scheduling.
 */
class FulfillmentRunnerTest extends \Vendidero\Shiptastic\Tests\Framework\UnitTestCase {

	public function setUp(): void {
		parent::setUp();

		update_option( 'woocommerce_shiptastic_enable_auto_packing', 'no' );

		add_filter( 'woocommerce_shiptastic_fulfillment_actions', array( $this, 'register_test_actions' ) );
		Test_Fulfillment_Label_Action::$runs = 0;
	}

	/**
	 * Pre-existing: creating shipment items triggers an incorrect usage notice (customs description meta).
	 */
	protected function expect_shipment_items() {
		$this->setExpectedIncorrectUsage( 'is_internal_meta_key' );
	}

	public function tearDown(): void {
		remove_filter( 'woocommerce_shiptastic_fulfillment_actions', array( $this, 'register_test_actions' ) );
		parent::tearDown();
	}

	public function register_test_actions( $actions ) {
		$actions['test_label'] = 'Test_Fulfillment_Label_Action';
		$actions['test_fail']  = 'Test_Fulfillment_Failing_Action';

		return $actions;
	}

	protected function create_workflow( $steps, $props = array() ) {
		$workflow = new Workflow();
		$workflow->set_props(
			array_merge(
				array(
					'title' => 'Test',
					'steps' => $steps,
				),
				$props
			)
		);
		$workflow->save();

		return new Workflow( $workflow->get_id() );
	}

	protected function create_order( $quantity = 2, $date_paid = null ) {
		$product = WC_Helper_Product::create_simple_product();
		$order   = wc_create_order();

		$item = new WC_Order_Item_Product();
		$item->set_props(
			array(
				'product'  => $product,
				'quantity' => $quantity,
				'subtotal' => 10,
				'total'    => 10,
			)
		);
		$order->add_item( $item );

		$shipping = new WC_Order_Item_Shipping();
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

		if ( $date_paid ) {
			$order->set_date_paid( $date_paid );
		}

		$order->save();

		QueueSync::sync_order( $order->get_id() );

		return wc_get_order( $order->get_id() );
	}

	protected function get_item_id( $order ) {
		$items = $order->get_items();

		return current( $items )->get_id();
	}

	public function test_registry_and_validation() {
		$this->assertInstanceOf( '\Vendidero\Shiptastic\BulkFulfillments\Actions\CreateShipments', ActionRegistry::get( 'create_shipments' ) );
		$this->assertNull( ActionRegistry::get( 'does_not_exist' ) );
		$this->assertTrue( ActionRegistry::exists( 'test_label' ) );

		$this->assertTrue( $this->create_workflow( array( 'pick', 'pack' ) )->validate() );
		$this->assertTrue( $this->create_workflow( array( 'test_label' ), array( 'mode' => 'background' ) )->validate() );

		$errors = $this->create_workflow( array( 'pick', 'unknown' ), array( 'mode' => 'background' ) )->validate();
		$this->assertEqualsCanonicalizing( array( 'workflow_unknown_action', 'workflow_action_requires_interaction' ), $errors->get_error_codes() );

		$errors = $this->create_workflow( array( 'pick' ), array( 'schedule' => array( 'type' => 'daily' ) ) )->validate();
		$this->assertEqualsCanonicalizing( array( 'workflow_schedule_requires_background', 'workflow_schedule_no_times' ), $errors->get_error_codes() );
	}

	public function test_step_plan_injects_dependencies() {
		$order    = $this->create_order();
		$item     = current( Queue::get_by_order( $order->get_id() ) );
		$workflow = $this->create_workflow( array( 'pick', 'pack' ) );

		$steps = Runner::get_steps( $item, $workflow );
		$this->assertEquals( array( 'create_shipments', 'pick', 'pack' ), wp_list_pluck( array_map( function ( $s ) { return $s->to_array(); }, $steps ), 'action' ) );
		$this->assertTrue( $steps[0]->injected );
		$this->assertEquals( 'background', $steps[0]->mode );
		$this->assertEquals( 'interactive', $steps[1]->mode );

		// Shipments are edited via the overlay - create_shipments always runs in the background
		$explicit = $this->create_workflow( array( array( 'action' => 'create_shipments', 'settings' => array( 'mode' => 'interactive' ) ), 'pick' ) );
		$steps    = Runner::get_steps( $item, $explicit );
		$this->assertFalse( $steps[0]->injected );
		$this->assertEquals( 'background', $steps[0]->mode );
	}

	public function test_interactive_run() {
		$this->expect_shipment_items();

		$order    = $this->create_order( 2 );
		$order_id = $order->get_id();
		$workflow = $this->create_workflow( array( 'pick', 'pack' ) );
		$item     = current( Queue::claim( $workflow, array( 'user_id' => 1 ) ) );
		$token    = $item->get_lock_token();

		// Shipments are created automatically, picking needs input
		$result = Runner::execute( $item->get_id(), $workflow, $token );
		$this->assertEquals( 'needs_input', $result['status'] );
		$this->assertEquals( 'pick', $result['step']->action );
		$this->assertEquals( 2, $result['view_data']['items'][0]['quantity'] );
		$this->assertCount( 1, Runner::get_package_shipments( Queue::get( $item->get_id() ) ) );

		// Short pick fails
		$order_item_id = $this->get_item_id( $order );
		$result        = Runner::execute(
			$item->get_id(),
			$workflow,
			$token,
			array(
				'step' => 'pick',
				'data' => array( 'picked' => array( $order_item_id => 1 ) ),
			)
		);
		$this->assertEquals( 'error', $result['status'] );
		$this->assertEquals( 'short_pick', $result['code'] );

		// Complete pick, pack needs input per shipment
		$result = Runner::execute(
			$item->get_id(),
			$workflow,
			$token,
			array(
				'step' => 'pick',
				'data' => array( 'picked' => array( $order_item_id => 2 ) ),
			)
		);
		$this->assertEquals( 'needs_input', $result['status'] );
		$this->assertEquals( 'pack', $result['step']->action );
		$shipment_id = $result['step']->shipment_id;
		$this->assertGreaterThan( 0, $shipment_id );

		$packaging = current( wc_stc_get_packaging_list() );

		// Short pack: all items of the shipment need to be packed
		$result = Runner::execute(
			$item->get_id(),
			$workflow,
			$token,
			array(
				'step' => 'pack:' . $shipment_id,
				'data' => array( 'packed' => array( $order_item_id => 1 ) ),
			)
		);
		$this->assertEquals( 'error', $result['status'] );
		$this->assertEquals( 'short_pack', $result['code'] );

		$result = Runner::execute(
			$item->get_id(),
			$workflow,
			$token,
			array(
				'step' => 'pack:' . $shipment_id,
				'data' => array(
					'packed'       => array( $order_item_id => 2 ),
					'packaging_id' => $packaging->get_id(),
					'weight'       => '1.5',
				),
			)
		);

		// Workflow completed, item released
		$this->assertEquals( 'completed', $result['status'] );
		$this->assertEquals( 'pending', Queue::get( $item->get_id() )->get_status() );

		$shipment = wc_stc_get_shipment( $shipment_id );
		$this->assertEquals( 'ready-for-shipping', $shipment->get_status() );
		$this->assertEquals( $packaging->get_id(), $shipment->get_packaging_id() );

		// Done for this workflow (incl. the order scope roll-up for pack)
		$this->assertEqualsCanonicalizing( array( 'create_shipments', 'pick', 'pack' ), StepLog::get_done_actions( $item->get_id() ) );
		$this->assertEquals( array(), Queue::claim( $workflow, array( 'user_id' => 1 ) ) );
		$this->assertEquals( $order_id, $item->get_order_id() );
	}

	public function test_done_steps_shared_across_workflows() {
		$this->expect_shipment_items();

		$order    = $this->create_order();
		$packing  = $this->create_workflow( array( 'pack' ) );
		$labels   = $this->create_workflow( array( 'pack', 'test_label' ), array( 'mode' => 'background' ) );
		$item     = current( Queue::claim( $packing, array( 'user_id' => 1 ) ) );
		$result   = Runner::execute( $item->get_id(), $packing, $item->get_lock_token() );
		$shipment = $result['step']->shipment_id;

		$this->assertEquals( 'completed', Runner::execute( $item->get_id(), $packing, $item->get_lock_token(), array( 'step' => 'pack:' . $shipment, 'data' => array( 'packed' => array( $this->get_item_id( $order ) => 2 ) ) ) )['status'] );

		// pack (and create_shipments) are done - the background workflow only creates the label
		$chunk = Runner::process_chunk( $labels, array( 'source' => 'scheduled', 'user_id' => 1 ) );
		$this->assertEquals( 1, $chunk['claimed'] );
		$this->assertEquals( 1, $chunk['completed'] );
		$this->assertEquals( 1, Test_Fulfillment_Label_Action::$runs );
		$this->assertContains( 'test_label', StepLog::get_done_actions( $item->get_id() ) );
		$this->assertEquals( $order->get_id(), $item->get_order_id() );
	}

	public function test_shipment_layout_reopens_steps() {
		$this->expect_shipment_items();

		$order         = $this->create_order( 4 );
		$order_item_id = $this->get_item_id( $order );
		$workflow      = $this->create_workflow( array( 'pick', 'pack' ) );
		$item          = current( Queue::claim( $workflow, array( 'user_id' => 1 ) ) );
		$token         = $item->get_lock_token();

		// create_shipments (background) creates one shipment containing all units
		$result = Runner::execute( $item->get_id(), $workflow, $token );
		$this->assertEquals( 'pick', $result['step']->action );

		$layout = ShipmentLayout::get_data( $item );
		$this->assertEquals( 4, $layout['items'][0]['total'] );
		$this->assertCount( 1, $layout['shipments'] );
		$first = $layout['shipments'][0]['id'];

		Runner::execute( $item->get_id(), $workflow, $token, array( 'step' => 'pick', 'data' => array( 'picked' => array( $order_item_id => 4 ) ) ) );
		$result = Runner::execute( $item->get_id(), $workflow, $token, array( 'step' => 'pack:' . $first, 'data' => array( 'packed' => array( $order_item_id => 4 ) ) ) );
		$this->assertEquals( 'completed', $result['status'] );
		$this->assertEquals( 'ready-for-shipping', wc_stc_get_shipment( $first )->get_status() );

		// Lock the item again (a workflow with open steps), e.g. a user editing the shipments while creating labels
		$item = current( Queue::claim( $this->create_workflow( array( 'test_label' ) ), array( 'user_id' => 1 ) ) );

		// Too many units
		$this->assertWPError( ShipmentLayout::apply( $item, array( array( 'id' => $first, 'items' => array( $order_item_id => 5 ) ) ) ) );
		// No shipment left
		$this->assertWPError( ShipmentLayout::apply( $item, array() ) );

		// Split: the packed shipment is changed (back to processing) and a new one is created
		$applied = ShipmentLayout::apply(
			$item,
			array(
				array(
					'id'    => $first,
					'items' => array( $order_item_id => 3 ),
					'props' => array( 'weight' => '2.5' ),
				),
				array(
					'id'    => 'new-1',
					'items' => array(
						$order_item_id => 1,
						// Not part of the order/package: ignored
						999999         => 5,
					),
				),
			)
		);

		$this->assertEqualsCanonicalizing( array( $first, $applied['id_map']['new-1'] ), $applied['changed'] );

		$first_shipment = wc_stc_get_shipment( $first );
		$new_shipment   = wc_stc_get_shipment( $applied['id_map']['new-1'] );

		$this->assertEquals( 'processing', $first_shipment->get_status() );
		$this->assertEquals( 3, $first_shipment->get_item_count() );
		$this->assertEquals( 2.5, (float) $first_shipment->get_weight() );
		$this->assertEquals( 1, $new_shipment->get_item_count() );
		$this->assertEquals( 'flat_rate:1', $new_shipment->get_shipping_method() );

		// Total quantities unchanged: pick stays done, pack is reopened (per shipment + roll-up)
		$done = StepLog::get_done_actions( $item->get_id() );
		$this->assertContains( 'pick', $done );
		$this->assertNotContains( 'pack', $done );

		$steps = Runner::get_steps( $item, $workflow );
		$this->assertEquals( array( 'pack:' . $first, 'pack:' . $new_shipment->get_id() ), array_values( array_map( fn( $s ) => $s->get_key(), array_filter( $steps, fn( $s ) => ! $s->is_done ) ) ) );

		// Removing a unit changes the totals: pick is reopened as well
		ShipmentLayout::apply(
			$item,
			array(
				array(
					'id'    => $first,
					'items' => array( $order_item_id => 3 ),
				),
			)
		);

		$this->assertNotContains( 'pick', StepLog::get_done_actions( $item->get_id() ) );
		$this->assertEquals( array( $first ), array_keys( Runner::get_package_shipments( $item ) ) );

		// Shipped shipments cannot be changed
		$first_shipment = wc_stc_get_shipment( $first );
		$this->assertTrue( ShipmentLayout::can_edit( $first_shipment ) );
		$first_shipment->set_status( 'shipped' );
		$this->assertFalse( ShipmentLayout::can_edit( $first_shipment ) );
	}

	public function test_shipment_props_keep_dimensions_without_packaging() {
		$this->expect_shipment_items();

		$order    = $this->create_order( 1 );
		$workflow = $this->create_workflow( array( 'pick' ) );
		$item     = current( Queue::claim( $workflow, array( 'user_id' => 1 ) ) );
		Runner::execute( $item->get_id(), $workflow, $item->get_lock_token() );

		$shipment  = current( Runner::get_package_shipments( $item ) );
		$packaging = current( wc_stc_get_packaging_list() );

		ShipmentLayout::apply_props( $shipment, array( 'packaging_id' => $packaging->get_id() ) );
		$shipment->save();

		$shipment = wc_stc_get_shipment( $shipment->get_id() );
		$length   = $shipment->get_length( 'edit' );
		$this->assertNotEmpty( $length );

		// Packaging removed, the (former packaging) dimensions are kept, the length is changed
		ShipmentLayout::apply_props(
			$shipment,
			array(
				'packaging_id' => 0,
				'length'       => '99',
				'width'        => $shipment->get_width( 'edit' ),
				'height'       => $shipment->get_height( 'edit' ),
			)
		);
		$width = $shipment->get_width( 'edit' );
		$shipment->save();

		$shipment = wc_stc_get_shipment( $shipment->get_id() );
		$this->assertEquals( 0, $shipment->get_packaging_id() );
		$this->assertEquals( 99, (float) $shipment->get_length( 'edit' ) );
		$this->assertEquals( (float) $width, (float) $shipment->get_width( 'edit' ) );
		$this->assertNotEquals( '', $shipment->get_width( 'edit' ) );
	}

	public function test_scheduled_chunk_continue_and_stop() {
		$orders   = array( $this->create_order( 1, strtotime( '2026-01-01' ) ), $this->create_order( 1, strtotime( '2026-01-02' ) ) );
		$workflow = $this->create_workflow( array( 'test_fail' ), array( 'mode' => 'background' ) );

		// continue: all items go to the exception inbox
		$chunk = Runner::process_chunk( $workflow, array( 'source' => 'scheduled', 'user_id' => 1 ) );
		$this->assertEquals( 2, $chunk['errors'] );
		$this->assertFalse( $chunk['stopped'] );

		foreach ( $orders as $order ) {
			$item = current( Queue::get_by_order( $order->get_id() ) );
			$this->assertEquals( 'error', $item->get_status() );
			$this->assertEquals( 'Carrier API unavailable', $item->get_last_error() );
			Queue::reset( $item->get_id() );
		}

		// stop: the first failure pauses the workflow, the other item is released
		reset_phpmailer_instance();
		$workflow->set_on_error( 'stop' );
		$workflow->save();

		$chunk = Runner::process_chunk( $workflow, array( 'source' => 'scheduled', 'user_id' => 1 ) );
		$this->assertTrue( $chunk['stopped'] );
		$this->assertEquals( 1, $chunk['processed'] );

		$workflow = new Workflow( $workflow->get_id() );
		$this->assertTrue( $workflow->is_paused() );
		$this->assertStringContainsString( 'Carrier API unavailable', $workflow->get_paused_reason() );
		$this->assertEquals( 'pending', current( Queue::get_by_order( $orders[1]->get_id() ) )->get_status() );
		$this->assertNotFalse( tests_retrieve_phpmailer_instance()->get_sent() );

		// background run started by a user: stops and keeps the failed item locked
		Queue::reset( current( Queue::get_by_order( $orders[0]->get_id() ) )->get_id() );
		$chunk = Runner::process_chunk( $this->create_workflow( array( 'test_fail' ), array( 'mode' => 'background' ) ), array( 'source' => 'background', 'user_id' => 1 ) );
		$this->assertTrue( $chunk['stopped'] );

		$failed = Queue::get( $chunk['failed'] );
		$this->assertTrue( $failed->is_locked() );
		$this->assertEquals( $chunk['token'], $failed->get_lock_token() );

		$this->assertTrue( Runner::skip( $failed->get_id(), $chunk['token'], 'Skipped' ) );
		$this->assertEquals( 'error', Queue::get( $failed->get_id() )->get_status() );
	}

	public function test_scheduler() {
		$this->expect_shipment_items();

		update_option( 'timezone_string', 'Europe/Berlin' );

		$workflow = $this->create_workflow(
			array( 'test_label' ),
			array(
				'mode'     => 'background',
				'schedule' => array(
					'type'  => 'daily',
					'times' => array( '13:30', '8:05', 'invalid' ),
				),
			)
		);

		$this->assertEquals( array( '13:30', '08:05' ), $workflow->get_schedule()['times'] );

		// 2026-03-28 12:00 Berlin (CET, UTC+1) -> next run today 13:30 Berlin
		$now = ( new DateTimeImmutable( '2026-03-28 12:00:00', new DateTimeZone( 'Europe/Berlin' ) ) )->getTimestamp();
		$this->assertEquals( ( new DateTimeImmutable( '2026-03-28 13:30:00', new DateTimeZone( 'Europe/Berlin' ) ) )->getTimestamp(), Scheduler::get_next_run( $workflow, $now ) );

		// After the last time of the day: next day (after the DST change) 08:05 Berlin
		$now = ( new DateTimeImmutable( '2026-03-28 14:00:00', new DateTimeZone( 'Europe/Berlin' ) ) )->getTimestamp();
		$this->assertEquals( ( new DateTimeImmutable( '2026-03-29 08:05:00', new DateTimeZone( 'Europe/Berlin' ) ) )->getTimestamp(), Scheduler::get_next_run( $workflow, $now ) );

		$workflow->set_schedule(
			array(
				'type'    => 'interval',
				'minutes' => 15,
			)
		);
		$this->assertEquals( 1000 + 15 * 60, Scheduler::get_next_run( $workflow, 1000 ) );

		// Not scheduled while interactive or paused
		$workflow->set_paused( true );
		$this->assertNull( Scheduler::get_next_run( $workflow ) );

		// Missing "run as" user pauses the workflow
		$workflow->set_paused( false );
		$workflow->set_run_as( 0 );
		$workflow->save();

		$this->assertNull( Scheduler::run_chunk( $workflow->get_id() ) );
		$this->assertTrue( ( new Workflow( $workflow->get_id() ) )->is_paused() );

		// Runs as the configured admin
		$this->create_order();
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );

		$workflow = new Workflow( $workflow->get_id() );
		$workflow->set_run_as( $admin );
		Scheduler::resume( $workflow );

		$users = array();
		add_action(
			'woocommerce_shiptastic_fulfillment_chunk_processed',
			function () use ( &$users ) {
				$users[] = get_current_user_id();
			}
		);

		$result = Scheduler::run_chunk( $workflow->get_id() );
		$this->assertEquals( 1, $result['completed'] );
		$this->assertEquals( array( $admin ), $users );
	}
}
