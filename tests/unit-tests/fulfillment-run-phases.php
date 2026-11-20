<?php

use Vendidero\Shiptastic\BulkFulfillments\Action;
use Vendidero\Shiptastic\BulkFulfillments\Queue;
use Vendidero\Shiptastic\BulkFulfillments\QueueSync;
use Vendidero\Shiptastic\BulkFulfillments\Run;
use Vendidero\Shiptastic\BulkFulfillments\RunItems;
use Vendidero\Shiptastic\BulkFulfillments\RunPhases;
use Vendidero\Shiptastic\BulkFulfillments\RunProcessor;
use Vendidero\Shiptastic\BulkFulfillments\Runner;
use Vendidero\Shiptastic\BulkFulfillments\RunSteps;
use Vendidero\Shiptastic\BulkFulfillments\ShipmentLayout;
use Vendidero\Shiptastic\BulkFulfillments\StepResult;
use Vendidero\Shiptastic\BulkFulfillments\Workflow;

/**
 * Shipment scope background action (e.g. packing automatically).
 */
class Test_Phase_Ship_Action extends Action {
	public static $log = array();

	public static function get_name() {
		return 'test_ship';
	}

	public static function get_title() {
		return 'Test ship';
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
		self::$log[] = 'ship:' . $context->get_shipment()->get_id();

		return StepResult::done();
	}
}

/**
 * Runs after each shipment (e.g. printing its label).
 */
class Test_Phase_After_Shipment_Action extends Test_Phase_Ship_Action {
	public static function get_name() {
		return 'test_after_shipment';
	}

	public static function get_title() {
		return 'Test after shipment';
	}

	public static function get_phases() {
		return array( RunPhases::AFTER_SHIPMENT, RunPhases::ITEM );
	}

	public function run( $context ) {
		self::$log[] = 'after_shipment:' . $context->get_shipment()->get_id();

		return StepResult::done();
	}
}

/**
 * Runs after each order (e.g. sending an e-mail).
 */
class Test_Phase_After_Order_Action extends Test_Phase_Ship_Action {
	public static function get_name() {
		return 'test_after_order';
	}

	public static function get_title() {
		return 'Test after order';
	}

	public static function get_scopes() {
		return array( 'order' );
	}

	public static function get_phases() {
		return array( RunPhases::AFTER_ITEM );
	}

	public function run( $context ) {
		self::$log[] = 'after_order:' . $context->queue_item->get_id();

		return StepResult::done();
	}
}

/**
 * Runs at the start of each batch (e.g. a picking list), may be configured to fail.
 */
class Test_Phase_Batch_Action extends Action {
	public static $contexts = array();

	public static $fail = false;

	public static function get_name() {
		return 'test_batch';
	}

	public static function get_title() {
		return 'Test batch';
	}

	public static function get_description() {
		return '';
	}

	public static function get_modes() {
		return array( 'background' );
	}

	public static function get_phases() {
		return array( RunPhases::BEFORE_QUEUE );
	}

	public function run( $context ) {
		if ( self::$fail ) {
			return StepResult::error( 'Printer offline', 'printer_offline' );
		}

		self::$contexts[] = array(
			'phase'     => $context->phase,
			'batch'     => $context->batch,
			'queue_ids' => $context->queue_ids,
			// Requirements (creating shipments) ran for the batch before.
			'shipments' => count( $context->get_shipments() ),
		);

		return StepResult::done( array( 'orders' => count( $context->get_queue_items() ) ) )->add_file_content( 'picking-list.pdf', '%PDF-test' );
	}
}

/**
 * Runs at the end of the run (e.g. a shipment list).
 */
class Test_Phase_End_Action extends Test_Phase_Batch_Action {
	public static $end_contexts = array();

	public static function get_name() {
		return 'test_end';
	}

	public static function get_title() {
		return 'Test end';
	}

	public static function get_requires() {
		return array();
	}

	public static function get_phases() {
		return array( RunPhases::AFTER_QUEUE );
	}

	public function run( $context ) {
		self::$end_contexts[] = array(
			'phase'     => $context->phase,
			'queue_ids' => $context->queue_ids,
		);

		return StepResult::done();
	}
}

/**
 * Interactive only: cannot run at the start/end of a run.
 */
class Test_Phase_Interactive_Action extends Test_Phase_Batch_Action {
	public static function get_name() {
		return 'test_interactive_batch';
	}

	public static function get_modes() {
		return array( 'interactive' );
	}
}

/**
 * Run phases: steps at the start of each batch, per shipment block, after each order and at the end of the run.
 */
class FulfillmentRunPhasesTest extends \Vendidero\Shiptastic\Tests\Framework\UnitTestCase {

	public function setUp(): void {
		parent::setUp();

		update_option( 'woocommerce_shiptastic_enable_auto_packing', 'no' );
		add_filter( 'woocommerce_shiptastic_fulfillment_actions', array( $this, 'register_test_actions' ) );

		Test_Phase_Ship_Action::$log          = array();
		Test_Phase_Batch_Action::$contexts    = array();
		Test_Phase_Batch_Action::$fail        = false;
		Test_Phase_End_Action::$end_contexts  = array();
	}

	public function tearDown(): void {
		remove_filter( 'woocommerce_shiptastic_fulfillment_actions', array( $this, 'register_test_actions' ) );
		parent::tearDown();
	}

	public function register_test_actions( $actions ) {
		$actions['test_ship']              = 'Test_Phase_Ship_Action';
		$actions['test_after_shipment']    = 'Test_Phase_After_Shipment_Action';
		$actions['test_after_order']       = 'Test_Phase_After_Order_Action';
		$actions['test_batch']             = 'Test_Phase_Batch_Action';
		$actions['test_end']               = 'Test_Phase_End_Action';
		$actions['test_interactive_batch'] = 'Test_Phase_Interactive_Action';

		return $actions;
	}

	protected function create_workflow( $steps ) {
		$workflow = new Workflow();
		$workflow->set_props(
			array(
				'title' => 'Phases',
				'steps' => $steps,
			)
		);
		$workflow->save();

		return new Workflow( $workflow->get_id() );
	}

	protected function create_order( $quantity = 2 ) {
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
		$order->save();

		QueueSync::sync_order( $order->get_id() );

		return wc_get_order( $order->get_id() );
	}

	protected function keys( $steps ) {
		return array_map(
			function ( $step ) {
				return $step->get_key();
			},
			$steps
		);
	}

	public function test_phases_sorting_and_validation() {
		$workflow = $this->create_workflow(
			array(
				array( 'action' => 'test_end' ),
				array( 'action' => 'test_after_order' ),
				array( 'action' => 'test_ship' ),
				array( 'action' => 'test_batch' ),
			)
		);

		// Steps are sorted by phase: start of batch, per order, after each order, end of run
		$this->assertEquals( array( 'test_batch', 'test_ship', 'test_after_order', 'test_end' ), wp_list_pluck( $workflow->get_steps(), 'action' ) );
		$this->assertEquals( RunPhases::AFTER_QUEUE, Workflow::get_step_phase( 'test_end' ) );
		$this->assertEquals( RunPhases::AFTER_SHIPMENT, Workflow::get_step_phase( 'test_after_shipment' ) );
		$this->assertEquals( RunPhases::ITEM, Workflow::get_step_phase( 'test_after_shipment', array( 'phase' => 'item' ) ) );
		// Unsupported phases fall back to the default phase
		$this->assertEquals( RunPhases::AFTER_SHIPMENT, Workflow::get_step_phase( 'test_after_shipment', array( 'phase' => 'after_queue' ) ) );
		// Run level actions do not count as open steps of an order
		$this->assertEquals( array( 'test_ship', 'test_after_order' ), $workflow->get_item_actions() );
		$this->assertTrue( $workflow->validate() );

		// Run level steps need to run automatically
		$invalid = $this->create_workflow( array( 'test_ship', 'test_interactive_batch' ) );
		$this->assertWPError( $invalid->validate() );
		$this->assertContains( 'workflow_invalid_phase', $invalid->validate()->get_error_codes() );

		// Unsupported phase chosen
		$invalid = $this->create_workflow(
			array(
				'test_ship',
				array(
					'action'   => 'test_after_order',
					'settings' => array( 'phase' => RunPhases::BEFORE_QUEUE ),
				),
			)
		);
		$this->assertContains( 'workflow_invalid_phase', $invalid->validate()->get_error_codes() );

		// At least one step per order
		$this->assertContains( 'workflow_no_item_steps', $this->create_workflow( array( 'test_end' ) )->validate()->get_error_codes() );
	}

	public function test_shipments_are_processed_one_after_another() {
		$order         = $this->create_order( 4 );
		$order_item_id = current( $order->get_items() )->get_id();
		$workflow      = $this->create_workflow( array( 'test_after_order', 'test_after_shipment', 'test_ship' ) );
		$item          = current( Queue::claim( $workflow, array( 'user_id' => 1 ) ) );
		$token         = $item->get_lock_token();

		// Create the shipments (injected dependency), then split into two shipments
		Runner::run_step( $item->get_id(), $workflow, $token, Runner::get_next_step( $item, $workflow ) );
		$first   = current( Runner::get_package_shipments( $item ) )->get_id();
		$applied = ShipmentLayout::apply(
			$item,
			array(
				array(
					'id'    => $first,
					'items' => array( $order_item_id => 3 ),
				),
				array(
					'id'    => 'new-1',
					'items' => array( $order_item_id => 1 ),
				),
			)
		);
		$second  = $applied['id_map']['new-1'];

		$this->assertEquals(
			array( 'create_shipments', 'test_ship:' . $first, 'test_after_shipment:' . $first, 'test_ship:' . $second, 'test_after_shipment:' . $second, 'test_after_order' ),
			$this->keys( Runner::get_steps( Queue::get( $item->get_id() ), $workflow ) )
		);

		$result = Runner::execute( $item->get_id(), $workflow, $token );

		$this->assertEquals( 'completed', $result['status'] );
		$this->assertEquals( array( 'ship:' . $first, 'after_shipment:' . $first, 'ship:' . $second, 'after_shipment:' . $second, 'after_order:' . $item->get_id() ), Test_Phase_Ship_Action::$log );
		$this->assertEquals( array(), Queue::claim( $workflow, array( 'user_id' => 1 ) ) );

		// The phase is exposed with the steps
		$this->assertEquals( RunPhases::AFTER_SHIPMENT, Runner::get_steps( Queue::get( $item->get_id() ), $workflow )[2]->to_array()['phase'] );
	}

	public function test_batch_start_and_run_end() {
		$first    = $this->create_order( 1 );
		$second   = $this->create_order( 1 );
		$workflow = $this->create_workflow( array( 'test_batch', 'test_ship', 'test_end' ) );
		$run      = RunProcessor::start_interactive( $workflow, 1 );
		$items    = Queue::claim(
			$workflow,
			array(
				'user_id' => 1,
				'token'   => $run->lock_token,
			)
		);

		$this->assertCount( 2, $items );

		$result = RunSteps::start_batch( $run, $items, $run->lock_token );

		$this->assertEquals( 'done', $result['status'] );
		$this->assertEquals( 1, $result['batch'] );
		$this->assertCount( 1, Test_Phase_Batch_Action::$contexts );
		$this->assertEquals( RunPhases::BEFORE_QUEUE, Test_Phase_Batch_Action::$contexts[0]['phase'] );
		$queue_ids = array_map(
			function ( $item ) {
				return $item->get_id();
			},
			$items
		);

		$this->assertEqualsCanonicalizing( $queue_ids, Test_Phase_Batch_Action::$contexts[0]['queue_ids'] );
		// Shipments were created for the batch before (requirement of the batch step)
		$this->assertEquals( 2, Test_Phase_Batch_Action::$contexts[0]['shipments'] );
		// The file is part of the run's export
		$this->assertEquals( 1, Run::get( $run->get_id() )->export_files );

		// Never runs twice for the same batch
		RunSteps::run_phase( Run::get( $run->get_id() ), RunPhases::BEFORE_QUEUE, 1, $queue_ids );
		$this->assertCount( 1, Test_Phase_Batch_Action::$contexts );

		// Complete the first order, put back the second one
		$completed = Runner::execute( $items[0]->get_id(), $workflow, $run->lock_token, array( 'run' => Run::get( $run->get_id() ) ) );
		$this->assertEquals( 'completed', $completed['status'] );
		Queue::release( $items[1]->get_id(), $run->lock_token );
		RunItems::set_status( $run->get_id(), $items[1]->get_id(), 'released' );

		$this->assertEquals( array( $items[0]->get_id() ), RunItems::get_queue_ids( $run->get_id(), array( 'status' => 'completed' ) ) );

		// End of the run: for the completed orders only
		$run = RunProcessor::finish_interactive( Run::get( $run->get_id() ) );

		$this->assertEquals( 'completed', $run->status );
		$this->assertEquals( array( array( 'phase' => RunPhases::AFTER_QUEUE, 'queue_ids' => array( $items[0]->get_id() ) ) ), Test_Phase_End_Action::$end_contexts );

		$entries = RunSteps::prepare( $run );
		$this->assertEquals( array( 'test_batch', 'test_end' ), wp_list_pluck( $entries, 'action' ) );
		$this->assertEquals( array( 'done', 'done' ), wp_list_pluck( $entries, 'status' ) );
		$this->assertEquals( 'Start of batch', $entries[0]['phase_title'] );
	}

	public function test_failed_batch_start_blocks_orders_until_skipped() {
		$this->create_order( 1 );

		Test_Phase_Batch_Action::$fail = true;

		$workflow = $this->create_workflow( array( 'test_batch', 'test_ship' ) );
		$run      = RunProcessor::start_interactive( $workflow, 1 );
		$items    = Queue::claim(
			$workflow,
			array(
				'user_id' => 1,
				'token'   => $run->lock_token,
			)
		);
		$result   = RunSteps::start_batch( $run, $items, $run->lock_token );

		$this->assertEquals( 'error', $result['status'] );
		$this->assertEquals( 'Printer offline', $result['message'] );
		$this->assertEquals( 'error', $result['entry']['status'] );

		// The batch's orders are blocked
		$blocked = Runner::execute( $items[0]->get_id(), $workflow, $run->lock_token, array( 'run' => $run ) );
		$this->assertEquals( 'error', $blocked['status'] );
		$this->assertEquals( 'run_step_pending', $blocked['code'] );
		$this->assertEquals( array(), Test_Phase_Ship_Action::$log );

		// Retry fails again
		$this->assertEquals( 'error', RunSteps::retry( $run, $result['entry']['id'] )['status'] );

		// Skip: the orders continue
		$skipped = RunSteps::skip( $run, $result['entry']['id'] );
		$this->assertEquals( 'done', $skipped['status'] );
		$this->assertEquals( 'skipped', RunSteps::get_entry_by_id( $result['entry']['id'] )['status'] );

		$this->assertEquals( 'completed', Runner::execute( $items[0]->get_id(), $workflow, $run->lock_token, array( 'run' => $run ) )['status'] );
	}

	public function test_run_end_without_completed_orders_is_skipped() {
		$this->create_order( 1 );

		$workflow = $this->create_workflow( array( 'test_ship', 'test_end' ) );
		$run      = RunProcessor::start_interactive( $workflow, 1 );
		$items    = Queue::claim(
			$workflow,
			array(
				'user_id' => 1,
				'token'   => $run->lock_token,
			)
		);

		RunSteps::start_batch( $run, $items, $run->lock_token );
		$run = RunProcessor::finish_interactive( Run::get( $run->get_id() ) );

		$this->assertEquals( array(), Test_Phase_End_Action::$end_contexts );
		$this->assertEquals( 'skipped', RunSteps::prepare( $run )[0]['status'] );
		// The claimed order was released
		$this->assertEquals( 'pending', Queue::get( $items[0]->get_id() )->get_status() );
		$this->assertEquals( array( $items[0]->get_id() ), RunItems::get_queue_ids( $run->get_id(), array( 'status' => 'released' ) ) );
	}

	protected function request( $method, $path, $params = array() ) {
		$request = new WP_REST_Request( $method, '/wc/v3/shiptastic/fulfillment' . $path );

		if ( 'GET' === $method ) {
			$request->set_query_params( $params );
		} else {
			$request->set_body_params( $params );
		}

		return rest_get_server()->dispatch( $request );
	}

	public function test_rest_claim_and_run_steps() {
		$admin = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );

		$this->create_order( 1 );
		Test_Phase_Batch_Action::$fail = true;

		$workflow = $this->create_workflow( array( 'test_batch', 'test_ship', 'test_end' ) );
		$run      = $this->request( 'POST', '/runs', array( 'workflow_id' => $workflow->get_id(), 'mode' => 'interactive' ) )->get_data(); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$claim    = $this->request( 'POST', '/workflows/' . $workflow->get_id() . '/claim', array( 'run_id' => $run['id'], 'run' => true ) )->get_data(); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound

		// The batch start failed: reported with the claim, the order is blocked
		$this->assertEquals( 'error', $claim['batch']['status'] );
		$this->assertEquals( 'Printer offline', $claim['batch']['message'] );
		$this->assertEquals( 'test_batch', $claim['batch']['entry']['action'] );
		$this->assertEquals( 'run_step_pending', $claim['items'][0]['current']['code'] );

		$entry = $claim['batch']['entry']['id'];
		$path  = '/runs/' . $run['id'] . '/run-steps/' . $entry;

		// Retry fails again
		$retry = $this->request( 'POST', $path . '/retry' )->get_data();
		$this->assertEquals( 'error', $retry['result']['status'] );

		// Skip: the order continues and completes
		$skip = $this->request( 'POST', $path . '/skip' )->get_data();
		$this->assertEquals( 'done', $skip['result']['status'] );
		$this->assertEquals( 'skipped', $skip['run']['run_steps'][0]['status'] );

		$item = $this->request( 'POST', '/queue/' . $claim['items'][0]['id'] . '/run', array( 'token' => $claim['token'] ) )->get_data();
		$this->assertEquals( 'completed', $item['current']['status'] );

		// End of the run when finishing
		$finished = $this->request( 'POST', '/runs/' . $run['id'] . '/finish' )->get_data();
		$this->assertEquals( array( 'test_batch', 'test_end' ), wp_list_pluck( $finished['run_steps'], 'action' ) );
		$this->assertEquals( 'done', $finished['run_steps'][1]['status'] );
		$this->assertEquals( 'End of run', $finished['run_steps'][1]['phase_title'] );

		// Unknown run step
		$this->assertEquals( 404, $this->request( 'POST', '/runs/' . $run['id'] . '/run-steps/999999/retry' )->get_status() );

		// Editor data: phases per action
		$editor  = $this->request( 'GET', '/workflows/editor' )->get_data();
		$actions = array_column( $editor['actions'], null, 'name' );
		$this->assertEquals( array( 'after_shipment', 'item' ), wp_list_pluck( $actions['test_after_shipment']['phases'], 'value' ) );
		// When a step runs: phase + scope combined, after_shipment is not offered (per shipment steps run shipment by shipment).
		$this->assertEquals( array( 'item:shipment' ), wp_list_pluck( $actions['update_shipment_status']['when'], 'value' ) );
		$this->assertEquals( 'Per shipment', $actions['update_shipment_status']['when'][0]['label'] );
		$this->assertEquals( 'item:shipment', $actions['create_label']['default_when'] );
		$this->assertEquals( array( 'item:shipment' ), wp_list_pluck( $actions['test_after_shipment']['when'], 'value' ) );
		$this->assertEquals( 'item:order', $actions['pick']['default_when'] );
	}

	public function test_when_choices_and_after_shipment_migration() {
		// Per order + per shipment + run level choices, in execution order.
		$choices = \Vendidero\Shiptastic\BulkFulfillments\RunPhases::get_choices( 'Test_Phase_Batch_Action' );
		$this->assertNotEmpty( $choices );

		foreach ( $choices as $choice ) {
			$this->assertNotEquals( 'after_shipment', $choice['phase'] );
			$this->assertNotEmpty( $choice['label'] );
			$this->assertNotEmpty( $choice['description'] );
		}

		// Steps saved with the after_shipment phase become per shipment steps.
		$workflow = new Workflow();
		$workflow->set_steps(
			array(
				'pick',
				array(
					'action'   => 'update_shipment_status',
					'settings' => array( 'phase' => 'after_shipment' ),
				),
			)
		);

		$steps = array_column( $workflow->get_steps(), 'settings', 'action' );
		$this->assertEquals( 'item', $steps['update_shipment_status']['phase'] );
		$this->assertEquals( 'shipment', $steps['update_shipment_status']['scope'] );
		$this->assertEquals( 'item', Workflow::get_step_phase( 'update_shipment_status', array( 'phase' => 'after_shipment' ) ) );
	}
}
