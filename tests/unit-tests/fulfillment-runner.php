<?php

use Vendidero\Shiptastic\BulkFulfillments\Action;
use Vendidero\Shiptastic\BulkFulfillments\ActionRegistry;
use Vendidero\Shiptastic\BulkFulfillments\Queue;
use Vendidero\Shiptastic\BulkFulfillments\QueueSync;
use Vendidero\Shiptastic\BulkFulfillments\Run;
use Vendidero\Shiptastic\BulkFulfillments\RunExport;
use Vendidero\Shiptastic\BulkFulfillments\Runner;
use Vendidero\Shiptastic\BulkFulfillments\ShipmentLayout;
use Vendidero\Shiptastic\BulkFulfillments\StepLog;
use Vendidero\Shiptastic\BulkFulfillments\StepResult;
use Vendidero\Shiptastic\BulkFulfillments\Workflow;

/**
 * A background action (e.g. label creation) used for testing.
 */
class Test_Fulfillment_Label_Action extends Action {
	public static $runs = 0;

	public static $users = array();

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
		self::$users[] = get_current_user_id();

		// A label file, added to the export of automation runs
		return StepResult::done( array( 'shipment' => $context->get_shipment()->get_id() ) )->add_file_content( 'label.pdf', '%PDF-test' );
	}
}

/**
 * A repeatable background action (e.g. printing) which may be part of a workflow several times.
 */
class Test_Fulfillment_Print_Action extends Action {
	public static $printed = array();

	public static function get_name() {
		return 'test_print';
	}

	public static function get_title() {
		return 'Test print';
	}

	public static function get_description() {
		return '';
	}

	public static function get_modes() {
		return array( 'background' );
	}

	public static function is_repeatable() {
		return true;
	}

	public function run( $context ) {
		self::$printed[] = $context->get_setting( 'document', '' );

		return StepResult::done();
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
 * Actions API, step planning and execution.
 */
class FulfillmentRunnerTest extends \Vendidero\Shiptastic\Tests\Framework\UnitTestCase {

	public function setUp(): void {
		parent::setUp();

		update_option( 'woocommerce_shiptastic_enable_auto_packing', 'no' );

		add_filter( 'woocommerce_shiptastic_fulfillment_actions', array( $this, 'register_test_actions' ) );
		Test_Fulfillment_Label_Action::$runs  = 0;
		Test_Fulfillment_Label_Action::$users = array();
	}


	public function tearDown(): void {
		remove_filter( 'woocommerce_shiptastic_fulfillment_actions', array( $this, 'register_test_actions' ) );
		parent::tearDown();
	}

	public function register_test_actions( $actions ) {
		$actions['test_label'] = 'Test_Fulfillment_Label_Action';
		$actions['test_fail']  = 'Test_Fulfillment_Failing_Action';
		$actions['test_print'] = 'Test_Fulfillment_Print_Action';

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

		$errors = $this->create_workflow( array( 'pick', 'unknown' ) )->validate();
		$this->assertEquals( array( 'workflow_unknown_action' ), $errors->get_error_codes() );

		// Interactive workflows only (extensions may add modes): unknown modes fall back to interactive
		$this->assertEquals( array( 'interactive' ), array_keys( Workflow::get_modes() ) );
		$this->assertEquals( 'interactive', $this->create_workflow( array( 'pick' ), array( 'mode' => 'background' ) )->get_mode() );

		// Ranges: open bounds are fine, "from" must not exceed "to"
		$this->assertTrue( $this->create_workflow( array( 'pick' ), array( 'filters' => array( 'min_items' => 2, 'max_weight' => '1.5' ) ) )->validate() );
		$this->assertTrue( $this->create_workflow( array( 'pick' ), array( 'filters' => array( 'min_items' => 2, 'max_items' => 2 ) ) )->validate() );

		$errors = $this->create_workflow( array( 'pick' ), array( 'filters' => array( 'min_items' => 5, 'max_items' => 2, 'min_weight' => '3', 'max_weight' => '1.5' ) ) )->validate();
		$this->assertEqualsCanonicalizing( array( 'workflow_invalid_item_range', 'workflow_invalid_weight_range' ), $errors->get_error_codes() );
	}

	public function test_step_order_and_error_handling() {
		// Required actions come first, the chosen order is kept otherwise
		$this->assertEquals( array( 'create_shipments', 'pick' ), $this->create_workflow( array( 'pick', 'create_shipments' ) )->get_step_actions() );
		$this->assertEquals( array( 'create_shipments', 'pack', 'pick' ), $this->create_workflow( array( 'pack', 'pick', 'create_shipments' ) )->get_step_actions() );
		$this->assertEquals( array( 'create_shipments' ), Workflow::get_required_actions( 'mark_ready' ) );
	}

	public function test_several_steps_of_an_action() {
		$this->assertTrue( Test_Fulfillment_Print_Action::allows_multiple() );
		$this->assertFalse( \Vendidero\Shiptastic\BulkFulfillments\Actions\Pick::allows_multiple() );

		Test_Fulfillment_Print_Action::$printed = array();

		$order    = $this->create_order();
		$workflow = $this->create_workflow(
			array(
				array(
					'action'   => 'test_print',
					'settings' => array( 'document' => 'picking_list' ),
				),
				'pick',
				// Duplicates of actions not allowing several steps are dropped.
				'pick',
				array(
					'action'   => 'test_print',
					'settings' => array( 'document' => 'label' ),
				),
			)
		);

		$steps = $workflow->get_steps();
		$this->assertEquals( array( 'test_print', 'pick', 'test_print' ), wp_list_pluck( $steps, 'action' ) );
		$this->assertArrayNotHasKey( 'instance', $steps[0] );
		$this->assertNotEmpty( $steps[2]['instance'] );

		$instance = $steps[2]['instance'];

		// The instance is kept once assigned, also in case the first step of the action is removed.
		$workflow->set_steps( array( 'pick', $steps[2] ) );
		$workflow->save();
		$workflow = new Workflow( $workflow->get_id() );
		$this->assertEquals( $instance, $workflow->get_steps()[1]['instance'] );
		$this->assertContains( 'test_print--' . $instance, $workflow->get_item_actions() );

		$workflow->set_steps( $steps );
		$workflow->save();
		$workflow = new Workflow( $workflow->get_id() );

		$item  = current( Queue::claim( $workflow, array( 'user_id' => 1 ) ) );
		$token = $item->get_lock_token();

		// Both steps are planned with their own key, the first one runs before picking.
		$planned = Runner::get_steps( Queue::get( $item->get_id() ), $workflow );
		$keys    = array_map(
			function ( $step ) {
				return $step->get_key();
			},
			$planned
		);
		$this->assertContains( 'test_print', $keys );
		$this->assertContains( 'test_print--' . $instance, $keys );

		$result = Runner::execute( $item->get_id(), $workflow, $token );
		$this->assertEquals( 'pick', $result['step']->action );
		$this->assertEquals( array( 'picking_list' ), Test_Fulfillment_Print_Action::$printed );

		$result = Runner::execute(
			$item->get_id(),
			$workflow,
			$token,
			array(
				'step' => 'pick',
				'data' => array( 'picked' => array( $this->get_item_id( $order ) => 2 ) ),
			)
		);

		$this->assertEquals( 'completed', $result['status'] );
		$this->assertEquals( array( 'picking_list', 'label' ), Test_Fulfillment_Print_Action::$printed );

		$logged = wp_list_pluck( StepLog::get_entries( $item->get_id() ), 'action' );
		$this->assertContains( 'test_print', $logged );
		$this->assertContains( 'test_print--' . $instance, $logged );
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

		// Packing does not change the status (separate step)
		$shipment = wc_stc_get_shipment( $shipment_id );
		$this->assertEquals( 'processing', $shipment->get_status() );
		$this->assertEquals( $packaging->get_id(), $shipment->get_packaging_id() );

		// Done for this workflow (incl. the order scope roll-up for pack)
		$this->assertEqualsCanonicalizing( array( 'create_shipments', 'pick', 'pack' ), StepLog::get_done_actions( $item->get_id() ) );
		$this->assertEquals( array(), Queue::claim( $workflow, array( 'user_id' => 1 ) ) );
		$this->assertEquals( $order_id, $item->get_order_id() );
	}

	public function test_shipment_layout_reopens_steps() {

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
		$this->assertEquals( 'processing', wc_stc_get_shipment( $first )->get_status() );

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

	public function test_step_mode_choice() {

		// Actions supporting user interaction run interactively (extensions may allow choosing the mode per step),
		// actions without user interaction (e.g. creating shipments) run automatically
		$workflow = $this->create_workflow( array( 'pick', 'mark_ready' ) );
		$this->assertEquals( array( 'interactive' ), Workflow::get_action_modes( 'mark_ready' ) );
		$this->assertEquals( array( 'background' ), Workflow::get_action_modes( 'create_shipments' ) );
		$this->assertEquals( 'interactive', $workflow->get_step_mode( 'mark_ready' ) );
		$this->assertEquals( 'interactive', $workflow->get_step_mode( 'mark_ready', array( 'mode' => 'background' ) ) );
		$this->assertEquals( 'interactive', $workflow->get_step_mode( 'pick' ) );
		$this->assertEquals( 'background', $workflow->get_step_mode( 'create_shipments' ) );
		$this->assertStringContainsString( 'stc-confirm-step', \Vendidero\Shiptastic\BulkFulfillments\Actions\MarkReady::get_template() );

		// Confirmed within the app via the generic review screen
		$this->create_order( 1 );
		$workflow = $this->create_workflow(
			array(
				array(
					'action'   => 'mark_ready',
					'settings' => array( 'mode' => 'interactive' ),
				),
			)
		);
		$this->assertEquals( 'interactive', $workflow->get_step_mode( 'mark_ready', array( 'mode' => 'interactive' ) ) );

		$item   = current( Queue::claim( $workflow, array( 'user_id' => 1 ) ) );
		$token  = $item->get_lock_token();
		$result = Runner::execute( $item->get_id(), $workflow, $token );

		$this->assertEquals( 'needs_input', $result['status'] );
		$this->assertEquals( 'mark_ready', $result['step']->action );
		$this->assertEquals( $result['step']->shipment_id, $result['view_data']['shipmentId'] );
		$this->assertNotEmpty( $result['view_data']['description'] );

		$shipment = wc_stc_get_shipment( $result['step']->shipment_id );
		$shipment->set_shipping_provider( 'dhl' );
		$shipment->save();

		$result = Runner::execute(
			$item->get_id(),
			$workflow,
			$token,
			array(
				'step' => $result['step']->get_key(),
				'data' => array(),
			)
		);

		$this->assertEquals( 'completed', $result['status'] );
		$this->assertEquals( 'ready-for-shipping', wc_stc_get_shipment( $shipment->get_id() )->get_status() );
	}

	public function test_address_correction_reaches_packed_shipments() {

		$order    = $this->create_order( 2 );
		$workflow = $this->create_workflow( array( 'pick' ) );
		$item     = current( Queue::claim( $workflow, array( 'user_id' => 1 ) ) );
		Runner::execute( $item->get_id(), $workflow, $item->get_lock_token() );

		$shipment = current( Runner::get_package_shipments( $item ) );
		$shipment->set_status( 'ready-for-shipping' );
		$shipment->save();

		// Correct the shipping address (e.g. via the WooCommerce REST API from the fulfillment app)
		$order = wc_get_order( $order->get_id() );
		$order->set_shipping_city( 'Hamburg' );
		$order->set_shipping_postcode( '20095' );
		$order->save();

		$shipment = wc_stc_get_shipment( $shipment->get_id() );
		$this->assertEquals( 'Hamburg', $shipment->get_city() );
		$this->assertEquals( '20095', $shipment->get_postcode() );
		$this->assertEquals( 'ready-for-shipping', $shipment->get_status() );

		// Shipments with a label keep their address
		add_filter( 'woocommerce_shiptastic_fulfillment_can_edit_shipment', '__return_false' );
		$order->set_shipping_city( 'Bremen' );
		$order->save();
		remove_filter( 'woocommerce_shiptastic_fulfillment_can_edit_shipment', '__return_false' );

		$this->assertEquals( 'Hamburg', wc_stc_get_shipment( $shipment->get_id() )->get_city() );
	}
}
