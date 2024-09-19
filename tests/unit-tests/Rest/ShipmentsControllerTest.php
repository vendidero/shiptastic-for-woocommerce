<?php

namespace Rest;

use Vendidero\Shiptastic\Rest\ShipmentsController;
use Vendidero\Shiptastic\Tests\Helpers\ShipmentHelper;
use WP_REST_Request;

/**
 * Class ShipmentsControllerTest
 *
 * Implements unit tests for shipments controller (rest api)
 *
 * @package Rest
 */
class ShipmentsControllerTest extends \Vendidero\Shiptastic\Tests\Framework\UnitRestTestCase {

	/**
	 * Endpoint to test
	 *
	 * @var ShipmentsController
	 */
	private $endpoint;

	/**
	 * @var int
	 */
	private $user;

	/**
	 * Setup our test server, endpoints, and user info.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->endpoint = new ShipmentsController();
		$this->user     = $this->factory->user->create( array(
			'role' => 'administrator',
		) );
	}

	/**
	 * Tests reading one shipment from rest api.
	 */
	function test_get_shipment() {
		wp_set_current_user( $this->user );

		$shipment_initial = ShipmentHelper::create_simple_shipment();
		$shipment_initial->set_packaging_id( 0 );
		$shipment_initial->set_props( array( 'length' => 12, 'width' => 10, 'height' => 5 ) );
		$shipment_initial->save();

		$response = $this->server->dispatch( new WP_REST_Request( 'GET', '/wc/v3/shipments/' . $shipment_initial->get_id() ) );
		$shipment_response = $response->get_data();

		$this->assertEquals( 200, $response->get_status() );

		$this->check_single_shipment( $shipment_response );
	}

	/**
	 * Tests reading one shipment from rest api.
	 */
	function test_get_shipment_unauthenticated() {
		$shipment_initial = ShipmentHelper::create_simple_shipment();

		$response = $this->server->dispatch( new WP_REST_Request( 'GET', '/wc/v3/shipments/' . $shipment_initial->get_id() ) );
		$shipment_response = $response->get_data();

		$this->assertEquals( 401, $response->get_status() );
	}

	/**
	 * Tests updating one shipment via rest api
	 */
	public function test_update_shipment() {
		wp_set_current_user( $this->user );

		$shipment_initial = ShipmentHelper::create_simple_shipment();

		$request = new WP_REST_Request( 'PUT', '/wc/v3/shipments/' . $shipment_initial->get_id() );
		$request->set_header( 'content-type', 'application/json' );
		$request->set_body( json_encode( array( 'status' => 'processing' ) ) );

		$response        = $this->server->dispatch( $request );
		$update_response = $response->get_data();

		$this->assertEquals( 200, $response->get_status() );

		$response          = $this->server->dispatch( new WP_REST_Request( 'GET', '/wc/v3/shipments/' . $shipment_initial->get_id() ) );
		$shipment_response = $response->get_data();

		$this->assertEquals( 200, $response->get_status() );
		$this->assertEquals( 'processing', $update_response['status'] );
		$this->assertEquals( 'processing', $shipment_response['status'] );
	}

	/**
	 * Tests create a shipment via rest api
	 */
	public function test_create_shipment() {
		wp_set_current_user( $this->user );

		$shipment_initial = ShipmentHelper::create_simple_shipment();
		$order_id = $shipment_initial->get_order_id();
		$shipment_initial->delete( true );

		$request = new WP_REST_Request( 'POST', '/wc/v3/shipments' );
		$request->set_header( 'content-type', 'application/json' );
		$request->set_body( json_encode( array(
			'status'      => 'processing',
			'order_id'    => "$order_id",
			"tracking_id" => '12345678',
		) ) );

		$response        = $this->server->dispatch( $request );
		$create_response = $response->get_data();

		$this->assertEquals( 201, $response->get_status() );
		$this->assertEquals( 'processing', $create_response['status'] );
		$this->assertEquals( '12345678', $create_response['tracking_id'] );
		$this->assertEquals( 1, count( $create_response['items'] ) );
	}

	public function test_create_shipment_custom_items() {
		wp_set_current_user( $this->user );

		$shipment_initial = ShipmentHelper::create_simple_shipment();
		$order_id = $shipment_initial->get_order_id();
		$shipment_initial->delete( true );

		$order = wc_stc_get_shipment_order( $order_id );
		$shipment_items = $order->get_available_items_for_shipment();
		$order_item_id = array_keys( $shipment_items )[0];

		$request = new WP_REST_Request( 'POST', '/wc/v3/shipments' );
		$request->set_header( 'content-type', 'application/json' );
		$request->set_body( json_encode( array(
			'status'   => 'processing',
			'order_id' => "$order_id",
			"items"    => array(
				array(
					'quantity' => 2,
					'order_item_id' => $order_item_id
				)
			),
		) ) );

		$response        = $this->server->dispatch( $request );
		$create_response = $response->get_data();

		$this->assertEquals( 201, $response->get_status() );
		$this->assertEquals( 'processing', $create_response['status'] );
		$this->assertEquals( 2, $create_response['items'][0]['quantity'] );
	}

	/**
	 * Tests deleting one shipment via rest api
	 */
	public function test_delete_shipment() {
		wp_set_current_user( $this->user );

		$shipment_initial = ShipmentHelper::create_simple_shipment();

		$request = new WP_REST_Request( 'DELETE', '/wc/v3/shipments/' . $shipment_initial->get_id() );

		$response        = $this->server->dispatch( $request );
		$delete_response = $response->get_data();

		$this->assertEquals( 200, $response->get_status() );
		$this->assertEquals( 0, $delete_response['id'] );

		$response          = $this->server->dispatch( new WP_REST_Request( 'GET', '/wc/v3/shipments/' . $shipment_initial->get_id() ) );
		$shipment_response = $response->get_data();

		$this->assertEquals( 404, $response->get_status() );
	}

	/**
	 * Tests shipment lists
	 */
	public function test_list_shipments() {
		wp_set_current_user( $this->user );

		$shipment = ShipmentHelper::create_simple_shipment();
		$shipment->set_packaging_id( 0 );
		$shipment->set_props( array( 'length' => 12, 'width' => 10, 'height' => 5 ) );
		$shipment->save();

		$shipment_1 = ShipmentHelper::create_simple_shipment();
		$shipment_1->set_packaging_id( 0 );
		$shipment_1->set_props( array( 'length' => 12, 'width' => 10, 'height' => 5 ) );
		$shipment_1->save();

		$shipment_2 = ShipmentHelper::create_simple_shipment();
		$shipment_2->set_packaging_id( 0 );
		$shipment_2->set_props( array( 'length' => 12, 'width' => 10, 'height' => 5 ) );
		$shipment_2->save();

		$response           = $this->server->dispatch( new WP_REST_Request( 'GET', '/wc/v3/shipments' ) );
		$shipments_response = $response->get_data();

		$this->assertEquals( 200, $response->get_status() );
		$response_count = 0;

		foreach ( $shipments_response as $inner_shipment ) {
			$id = $inner_shipment->get_data()['id'];

			if ( in_array( (int) $id, array( $shipment->get_id(), $shipment_1->get_id(), $shipment_2->get_id() ), true ) ) {
				$this->check_single_shipment( $inner_shipment->get_data() );

				$response_count++;
			}
		}

		$this->assertEquals( 3, $response_count );
	}

	/**
	 * Checks validity of single shipment
	 *
	 * @param array $shipment
	 */
	private function check_single_shipment( $shipment ) {
		$this->assertEquals( '40', $shipment['total'] );
		$this->assertEquals( '4.4', $shipment['weight'] );
		$this->assertEquals( 'draft', $shipment['status'] );
		$this->assertEquals( array(
			'length' => '12',
			'width'  => '10',
			'height' => '5',
		), $shipment['dimensions'] );
		$this->assertEquals( array(
			'first_name' => 'Max',
			'last_name'  => 'Mustermann',
			'company'    => '',
			'address_1'  => 'Musterstr. 12',
			'address_2'  => '',
			'city'       => 'Berlin',
			'state'      => '',
			'postcode'   => '12222',
			'country'    => 'DE',
			'phone'      => '555-32123',
			'email'      => 'admin@example.org',
		), $shipment['address'] );
		$this->assertEquals( 1, count( $shipment['items'] ) );
		$this->assertEquals( 'Dummy Product', $shipment['items'][0]['name'] );
	}
}