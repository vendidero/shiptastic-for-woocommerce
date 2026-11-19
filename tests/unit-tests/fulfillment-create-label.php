<?php

use Vendidero\Shiptastic\BulkFulfillments\Actions\CreateLabel;
use Vendidero\Shiptastic\BulkFulfillments\ActionRegistry;
use Vendidero\Shiptastic\BulkFulfillments\Queue;
use Vendidero\Shiptastic\BulkFulfillments\QueueSync;
use Vendidero\Shiptastic\BulkFulfillments\Runner;
use Vendidero\Shiptastic\BulkFulfillments\Workflow;

/**
 * Create label step: checks before creating labels (provider selection, missing provider), the review data and
 * shipments staying unshipped while they are fulfilled.
 */
class FulfillmentCreateLabelTest extends \Vendidero\Shiptastic\Tests\Framework\UnitTestCase {

	public function setUp(): void {
		parent::setUp();

		update_option( 'woocommerce_shiptastic_enable_auto_packing', 'no' );
		CreateLabel::reset_created_labels();
	}

	protected function create_workflow( $steps ) {
		$workflow = new Workflow();
		$workflow->set_props(
			array(
				'title' => 'Labels',
				'steps' => $steps,
			)
		);
		$workflow->save();

		return new Workflow( $workflow->get_id() );
	}

	protected function create_order() {
		$product = WC_Helper_Product::create_simple_product();
		$order   = wc_create_order();

		$item = new WC_Order_Item_Product();
		$item->set_props(
			array(
				'product'  => $product,
				'quantity' => 1,
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

	public function test_action() {
		$action = ActionRegistry::get( 'create_label' );

		$this->assertInstanceOf( '\Vendidero\Shiptastic\BulkFulfillments\Actions\CreateLabel', $action );
		$this->assertEquals( array( 'shipment' ), CreateLabel::get_scopes() );
		$this->assertTrue( CreateLabel::requires_network() );
		$this->assertEquals( array( 'create_shipments' ), Workflow::get_required_actions( 'create_label' ) );
		$this->assertArrayHasKey( 'shipping_providers', CreateLabel::get_settings_schema() );
		$this->assertStringContainsString( 'stc-label-step', CreateLabel::get_template() );
	}

	public function test_checks() {
		$this->create_order();

		// Shipment without shipping provider: needs fixing.
		$workflow = $this->create_workflow( array( 'create_label' ) );
		$item     = current( Queue::claim( $workflow, array( 'user_id' => 1 ) ) );
		$token    = $item->get_lock_token();
		$result   = Runner::execute( $item->get_id(), $workflow, $token );

		$this->assertEquals( 'needs_input', $result['status'] );
		$this->assertEquals( 'create_label', $result['step']->action );
		$this->assertEquals( $result['step']->shipment_id, $result['view_data']['shipmentId'] );
		$this->assertNotEmpty( $result['view_data']['notice'] );
		$this->assertFalse( $result['view_data']['canEdit'] );

		$result = Runner::execute(
			$item->get_id(),
			$workflow,
			$token,
			array(
				'step' => $result['step']->get_key(),
				'data' => array(),
			)
		);

		$this->assertEquals( 'error', $result['status'] );
		$this->assertEquals( 'missing_shipping_provider', $result['code'] );
		$this->assertEmpty( CreateLabel::get_created_labels() );
		Queue::release( $item->get_id(), $token );

		// Shipments of other providers than the chosen ones are skipped.
		$workflow = $this->create_workflow(
			array(
				array(
					'action'   => 'create_label',
					'settings' => array( 'shipping_providers' => array( 'dhl' ) ),
				),
			)
		);
		$item     = current( Queue::claim( $workflow, array( 'user_id' => 1 ) ) );
		$token    = $item->get_lock_token();
		$result   = Runner::execute( $item->get_id(), $workflow, $token );

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
	}

	public function test_shipments_stay_unshipped_while_fulfilled() {
		$order    = $this->create_order();
		$workflow = $this->create_workflow( array( 'create_label' ) );
		$item     = current( Queue::claim( $workflow, array( 'user_id' => 1 ) ) );
		$token    = $item->get_lock_token();

		Runner::execute( $item->get_id(), $workflow, $token );

		$shipment = current( Runner::get_package_shipments( Queue::get( $item->get_id() ) ) );

		$this->assertTrue( QueueSync::is_in_fulfillment( $shipment ) );
		$this->assertFalse( apply_filters( 'woocommerce_shiptastic_shipment_mark_shipped_after_label_creation', true, $shipment ) );

		Queue::release( $item->get_id(), $token );

		$this->assertFalse( QueueSync::is_in_fulfillment( $shipment ) );
		$this->assertTrue( apply_filters( 'woocommerce_shiptastic_shipment_mark_shipped_after_label_creation', true, $shipment ) );
	}
}
