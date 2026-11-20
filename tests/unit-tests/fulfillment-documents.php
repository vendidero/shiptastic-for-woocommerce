<?php

use Vendidero\Shiptastic\BulkFulfillments\ActionRegistry;
use Vendidero\Shiptastic\BulkFulfillments\Documents;
use Vendidero\Shiptastic\BulkFulfillments\Queue;
use Vendidero\Shiptastic\BulkFulfillments\QueueSync;
use Vendidero\Shiptastic\BulkFulfillments\Runner;
use Vendidero\Shiptastic\BulkFulfillments\Workflow;

/**
 * Shipping documents step (per shipment): optional uploads within the app, export declarations above a goods value.
 */
class FulfillmentDocumentsTest extends \Vendidero\Shiptastic\Tests\Framework\UnitTestCase {

	protected $previous_country = '';

	public function setUp(): void {
		parent::setUp();

		update_option( 'woocommerce_shiptastic_enable_auto_packing', 'no' );

		// A German shop: exports leave the EU customs area, export declarations (ABD) are supported.
		add_filter( 'woocommerce_shiptastic_shipment_base_country', array( $this, 'get_base_country' ) );
		add_filter( 'woocommerce_shiptastic_shipment_sender_country', array( $this, 'get_base_country' ) );
		$this->previous_country = get_option( 'woocommerce_default_country' );
		update_option( 'woocommerce_default_country', 'DE:BE' );
	}

	public function tearDown(): void {
		remove_filter( 'woocommerce_shiptastic_shipment_base_country', array( $this, 'get_base_country' ) );
		remove_filter( 'woocommerce_shiptastic_shipment_sender_country', array( $this, 'get_base_country' ) );
		update_option( 'woocommerce_default_country', $this->previous_country );

		parent::tearDown();
	}

	public function get_base_country() {
		return 'DE';
	}

	protected function create_workflow( $steps ) {
		$workflow = new Workflow();
		$workflow->set_props(
			array(
				'title' => 'Documents',
				'steps' => $steps,
			)
		);
		$workflow->save();

		return new Workflow( $workflow->get_id() );
	}

	protected function create_order( $country = 'DE' ) {
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
		$order->set_shipping_country( $country );
		$order->set_shipping_first_name( 'Max' );
		$order->set_shipping_last_name( 'Mustermann' );
		$order->set_shipping_address_1( 'Musterstr. 12' );
		$order->set_shipping_postcode( '8000' );
		$order->set_shipping_city( 'Zürich' );
		$order->set_status( 'processing' );
		$order->save();

		QueueSync::sync_order( $order->get_id() );

		return wc_get_order( $order->get_id() );
	}

	protected function attach( $shipment, $type ) {
		$attachment = wc_stc_create_shipment_attachment( $type );
		$path       = wc_shiptastic_upload_data( $type . '-' . $shipment->get_id() . '-test.pdf', '%PDF-1.4 test', true, false );

		$attachment->set_relative_path( $path );
		$attachment->set_extension( 'application/pdf' );
		$shipment->add_attachment( $attachment );
		$shipment->save();
	}

	protected function start( $workflow ) {
		$item  = current( Queue::claim( $workflow, array( 'user_id' => 1 ) ) );
		$token = $item->get_lock_token();

		return array( $item, $token, Runner::execute( $item->get_id(), $workflow, $token ) );
	}

	public function test_actions() {
		// Invoices are a Shiptastic Pro step.
		$this->assertNull( ActionRegistry::get( 'create_invoice' ) );
		$this->assertInstanceOf( '\Vendidero\Shiptastic\BulkFulfillments\Actions\CreateShippingDocuments', ActionRegistry::get( 'create_shipping_documents' ) );
		$this->assertEquals( array( 'shipment' ), \Vendidero\Shiptastic\BulkFulfillments\Actions\CreateShippingDocuments::get_scopes() );
		$this->assertStringContainsString( 'stc-document-list', \Vendidero\Shiptastic\BulkFulfillments\Actions\CreateShippingDocuments::get_template() );
	}

	public function test_shipping_documents_for_exports() {
		$this->create_order( 'CH' );

		$workflow = $this->create_workflow(
			array(
				array(
					'action'   => 'create_shipping_documents',
					// Low threshold: the shipment's goods value (10) exceeds it.
					'settings' => array( 'abd_threshold' => 5 ),
				),
			)
		);

		list( $item, $token, $result ) = $this->start( $workflow );

		$this->assertEquals( 'needs_input', $result['status'] );

		$rows = array_column( $result['view_data']['documents'], null, 'type' );
		$this->assertEquals( array( 'packing_slip', 'commercial_invoice', 'abd' ), array_keys( $rows ) );
		// Documents are optional.
		foreach ( $rows as $row ) {
			$this->assertFalse( $row['required'] );
		}

		$this->assertNotEmpty( $rows['abd']['hint'] );

		$shipment = wc_stc_get_shipment( $result['step']->shipment_id );
		$this->assertTrue( Documents::needs_export_declaration( $shipment, 5 ) );
		$this->assertFalse( Documents::needs_export_declaration( $shipment, 1000 ) );

		// Uploaded documents are part of the step result (run files), missing ones do not block.
		$this->attach( $shipment, 'commercial_invoice' );

		$result = Runner::execute( $item->get_id(), $workflow, $token, array( 'step' => $result['step']->get_key(), 'data' => array() ) );
		$this->assertEquals( 'completed', $result['status'] );
	}

	public function test_shipping_documents_domestic() {
		$this->create_order( 'DE' );

		list( , , $result ) = $this->start( $this->create_workflow( array( 'create_shipping_documents' ) ) );

		// No commercial invoice/export declaration within the EU.
		$this->assertEquals( array( 'packing_slip' ), wp_list_pluck( $result['view_data']['documents'], 'type' ) );
	}

	public function test_upload_requires_pdf() {
		$result = Documents::store_upload( array(), 'packing_slip', array( 'tmp_name' => __FILE__, 'name' => 'invoice.pdf', 'error' => 0 ) );
		$this->assertWPError( $result );
	}
}
