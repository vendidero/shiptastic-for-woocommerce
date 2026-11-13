<?php

use Vendidero\Shiptastic\BulkFulfillments\Queue;
use Vendidero\Shiptastic\BulkFulfillments\QueueSync;
use Vendidero\Shiptastic\BulkFulfillments\StepLog;
use Vendidero\Shiptastic\BulkFulfillments\Workflow;
use Vendidero\Shiptastic\DataStores\FulfillmentWorkflow;
use Vendidero\Shiptastic\Warehouse;

/**
 * Fulfillment queue: workflows, sync per package and locking.
 */
class FulfillmentQueueTest extends \Vendidero\Shiptastic\Tests\Framework\UnitTestCase {

	public function setUp(): void {
		parent::setUp();

		update_option( 'woocommerce_shiptastic_enable_auto_packing', 'no' );
		update_option( 'woocommerce_manage_stock', 'yes' );
	}

	protected function create_workflow( $steps = array( 'pick' ), $filters = array(), $props = array() ) {
		$workflow = new Workflow();
		$workflow->set_props(
			array_merge(
				array(
					'title'   => 'Test',
					'steps'   => $steps,
					'filters' => $filters,
				),
				$props
			)
		);
		$workflow->save();

		return new Workflow( $workflow->get_id() );
	}

	protected function create_product( $props = array(), $warehouse_name = '' ) {
		$product = WC_Helper_Product::create_simple_product();
		$product->set_props( $props );

		if ( $warehouse_name ) {
			wc_shiptastic_get_product( $product )->set_warehouse_name( $warehouse_name );
		}

		$product->save();

		return wc_get_product( $product->get_id() );
	}

	/**
	 * @param array $lines [ product, quantity ]
	 * @param string $status
	 * @param int|null $date_paid
	 *
	 * @return WC_Order
	 */
	protected function create_order( $lines, $status = 'processing', $date_paid = null ) {
		$order = wc_create_order();

		foreach ( $lines as $line ) {
			list( $product, $quantity ) = $line;

			$item = new WC_Order_Item_Product();
			$item->set_props(
				array(
					'product'  => $product,
					'quantity' => $quantity,
					'subtotal' => 10 * $quantity,
					'total'    => 10 * $quantity,
				)
			);
			$order->add_item( $item );
		}

		$shipping = new WC_Order_Item_Shipping();
		$shipping->set_props(
			array(
				'method_title' => 'Flat rate',
				'method_id'    => 'flat_rate',
				'instance_id'  => 1,
				'total'        => '5',
			)
		);
		$order->add_item( $shipping );
		$order->set_shipping_country( 'DE' );
		$order->set_shipping_first_name( 'Max' );
		$order->set_shipping_last_name( 'Mustermann' );
		$order->set_shipping_address_1( 'Musterstr. 12' );
		$order->set_shipping_postcode( '12222' );
		$order->set_shipping_city( 'Berlin' );
		$order->set_status( $status );

		if ( $date_paid ) {
			$order->set_date_paid( $date_paid );
		}

		$order->save();

		return wc_get_order( $order->get_id() );
	}

	protected function sync( $order ) {
		if ( $cache = \Vendidero\Shiptastic\Caches\Helper::get_cache_object( 'shipment-orders' ) ) {
			$cache->remove( $order->get_id() );
		}

		return QueueSync::sync_order( $order->get_id() );
	}

	/**
	 * Pre-existing: Product::get_customs_description() reads an internal meta key via get_meta()
	 * which triggers a notice whenever shipment items are created.
	 */
	protected function expect_customs_notice() {
		$this->setExpectedIncorrectUsage( 'is_internal_meta_key' );
	}

	public function test_workflow_model() {
		$workflow = $this->create_workflow(
			array( 'create_shipments', array( 'action' => 'Pick', 'settings' => array( 'mode' => 'batch' ) ), '' ),
			array(
				'locations'        => array( '', 'nord', 'nord' ),
				'countries'        => array( 'de' ),
				'shipping_methods' => array( 'flat_rate' ),
				'max_items'        => '5',
				'backorders'       => 'invalid',
			),
			array(
				'on_error' => 'stop',
				'run_as'   => 1,
				'priority' => 5,
			)
		);

		$this->assertEquals( array( 'create_shipments', 'pick' ), $workflow->get_step_actions() );
		$this->assertEquals( array( 'mode' => 'batch' ), $workflow->get_steps()[1]['settings'] );
		$this->assertEquals( array( '', 'nord' ), $workflow->get_filter( 'locations' ) );
		$this->assertEquals( array( 'DE' ), $workflow->get_filter( 'countries' ) );
		$this->assertSame( 5, $workflow->get_filter( 'max_items' ) );
		$this->assertSame( '', $workflow->get_filter( 'min_items' ) );
		$this->assertEquals( 'any', $workflow->get_filter( 'backorders' ) );
		$this->assertEquals( 'stop', $workflow->get_on_error() );
		$this->assertEquals( 1, $workflow->get_run_as() );
		$this->assertContains( $workflow->get_id(), FulfillmentWorkflow::get_active_workflow_ids() );

		$workflow->set_status( 'inactive' );
		$workflow->save();
		$this->assertNotContains( $workflow->get_id(), FulfillmentWorkflow::get_active_workflow_ids() );
	}

	public function test_sync_one_item_per_package() {
		$group = new Warehouse();
		$group->set_props(
			array(
				'title' => 'Nord',
				'type'  => 'group',
			)
		);
		$group->save();

		$warehouse = new Warehouse();
		$warehouse->set_props(
			array(
				'title' => 'Hamburg',
				'group' => 'nord',
			)
		);
		$warehouse->save();

		$p_default = $this->create_product( array( 'weight' => 1 ) );
		$p_nord    = $this->create_product( array( 'weight' => 2 ), 'hamburg' );
		$paid      = strtotime( '2026-01-10 10:00:00' );
		$order     = $this->create_order( array( array( $p_default, 2 ), array( $p_nord, 3 ) ), 'processing', $paid );

		$items = $this->sync( $order );

		$this->assertEquals( array( '|flat_rate:1', 'nord|flat_rate:1' ), array_keys( $items ) );

		$nord = $items['nord|flat_rate:1'];
		$this->assertEquals( 'pending', $nord->get_status() );
		$this->assertEquals( 'nord', $nord->get_location() );
		$this->assertEquals( 'flat_rate:1', $nord->get_shipping_method() );
		$this->assertEquals( 'DE', $nord->get_country() );
		$this->assertEquals( 3, $nord->get_item_count() );
		$this->assertEquals( 6, $nord->get_weight() );
		$this->assertFalse( $nord->has_backorders() );
		$this->assertEquals( $paid, $nord->get_date_queued()->getTimestamp() );

		// Syncing again does not change anything (no version bump)
		$version = $nord->get_version();
		$items   = $this->sync( $order );
		$this->assertEquals( $version, $items['nord|flat_rate:1']->get_version() );
	}

	public function test_sync_lifecycle() {
		$product = $this->create_product();

		// Not eligible yet
		$order = $this->create_order( array( array( $product, 2 ) ), 'pending' );
		$this->assertEquals( array(), $this->sync( $order ) );

		$order->set_status( 'processing' );
		$order->save();
		$items = $this->sync( $order );
		$this->assertCount( 1, $items );
		$this->assertEquals( 'pending', current( $items )->get_status() );

		// Shipments created but not shipped: still needs fulfillment
		$this->expect_customs_notice();
		$shipments = wc_stc_get_shipment_order( $order )->create_shipments();
		$this->assertCount( 1, $shipments );

		$items = $this->sync( $order );
		$this->assertEquals( 'pending', current( $items )->get_status() );
		$this->assertEquals( 2, current( $items )->get_item_count() );

		// Shipped: done
		$shipment = wc_stc_get_shipment( current( $shipments )->get_id() );
		$shipment->set_status( 'shipped' );
		$shipment->save();

		$items = $this->sync( $order );
		$this->assertEquals( 'done', current( $items )->get_status() );
	}

	public function test_cancelled_order_is_removed_and_reopened() {
		$order = $this->create_order( array( array( $this->create_product(), 1 ) ) );
		$this->sync( $order );

		$order->set_status( 'cancelled' );
		$order->save();
		$this->assertEquals( 'removed', current( $this->sync( $order ) )->get_status() );

		$order->set_status( 'processing' );
		$order->save();
		$this->assertEquals( 'pending', current( $this->sync( $order ) )->get_status() );
	}

	public function test_claim_exclude() {
		$product = $this->create_product();
		$first   = current( $this->sync( $this->create_order( array( array( $product, 1 ) ), 'processing', time() - 100 ) ) );
		$second  = current( $this->sync( $this->create_order( array( array( $product, 1 ) ), 'processing', time() - 50 ) ) );

		$claimed = Queue::claim(
			$this->create_workflow(),
			array(
				'user_id' => 1,
				'exclude' => array( $first->get_id() ),
			)
		);

		$this->assertEquals( array( $second->get_id() ), array_values( array_map( fn( $item ) => $item->get_id(), $claimed ) ) );
	}

	public function test_backorder_only_package() {
		$product = $this->create_product(
			array(
				'manage_stock'   => true,
				'stock_quantity' => 0,
				'backorders'     => 'yes',
			)
		);

		$order = $this->create_order( array( array( $product, 2 ) ) );
		wc_reduce_stock_levels( $order->get_id() );

		$item = current( $this->sync( $order ) );
		$this->assertTrue( $item->has_backorders() );
		$this->assertEquals( 0, $item->get_item_count() );

		$this->assertCount( 0, Queue::claim( $this->create_workflow( array( 'pick' ), array( 'backorders' => 'exclude' ) ), array( 'user_id' => 1 ) ) );
		$this->assertCount( 1, Queue::claim( $this->create_workflow( array( 'pick' ), array( 'backorders' => 'only' ) ), array( 'user_id' => 1 ) ) );
	}

	public function test_claim_fifo_filters_and_locks() {
		$warehouse = new Warehouse();
		$warehouse->set_title( 'Berlin' );
		$warehouse->save();

		$p_default = $this->create_product();
		$p_berlin  = $this->create_product( array(), 'berlin' );

		$order_new    = $this->create_order( array( array( $p_berlin, 1 ) ), 'processing', strtotime( '2026-01-03' ) );
		$order_old    = $this->create_order( array( array( $p_berlin, 1 ) ), 'processing', strtotime( '2026-01-01' ) );
		$order_mid    = $this->create_order( array( array( $p_berlin, 1 ) ), 'processing', strtotime( '2026-01-02' ) );
		$order_other  = $this->create_order( array( array( $p_default, 1 ) ), 'processing', strtotime( '2025-12-01' ) );

		foreach ( array( $order_new, $order_old, $order_mid, $order_other ) as $order ) {
			$this->sync( $order );
		}

		$workflow = $this->create_workflow( array( 'pick' ), array( 'locations' => array( 'berlin' ) ) );

		// FIFO + filter: the default location order is ignored
		$claimed = Queue::claim(
			$workflow,
			array(
				'limit'   => 2,
				'user_id' => 1,
			)
		);
		$this->assertEquals( array( $order_old->get_id(), $order_mid->get_id() ), array_map( function ( $i ) { return $i->get_order_id(); }, $claimed ) );

		$token = $claimed[0]->get_lock_token();
		$this->assertEquals( $token, $claimed[1]->get_lock_token() );
		$this->assertTrue( $claimed[0]->is_locked() );
		$this->assertEquals( $workflow->get_id(), $claimed[0]->get_workflow_id() );
		$this->assertEquals( 'interactive', $claimed[0]->get_lock_source() );
		$this->assertEqualsWithDelta( time() + Queue::LOCK_TTL_INTERACTIVE, $claimed[0]->get_lock_expires()->getTimestamp(), 5 );

		// A second session gets the remaining item only - claims are disjoint
		$second = Queue::claim( $workflow, array( 'user_id' => 2 ) );
		$this->assertEquals( array( $order_new->get_id() ), array_map( function ( $i ) { return $i->get_order_id(); }, $second ) );
		$this->assertNotEquals( $token, $second[0]->get_lock_token() );
		$this->assertEquals( array(), Queue::claim( $workflow, array( 'user_id' => 3 ) ) );

		// An expired lock may be claimed by someone else, the old token is invalid then
		global $wpdb;
		$wpdb->update( $wpdb->stc_fulfillment_queue, array( 'queue_lock_expires_gmt' => gmdate( 'Y-m-d H:i:s', time() - 60 ) ), array( 'queue_id' => $claimed[0]->get_id() ) );

		$third = Queue::claim( $workflow, array( 'user_id' => 3 ) );
		$this->assertEquals( array( $claimed[0]->get_id() ), array_map( function ( $i ) { return $i->get_id(); }, $third ) );
		$this->assertWPError( Queue::verify_lock( $claimed[0]->get_id(), $token ) );
		$this->assertEquals( 'queue_lock_lost', Queue::verify_lock( $claimed[0]->get_id(), $token )->get_error_code() );

		// The other item of the first claim is still valid
		$this->assertTrue( Queue::verify_lock( $claimed[1]->get_id(), $token ) );
	}

	public function test_heartbeat_version_and_release() {
		$order = $this->create_order( array( array( $this->create_product(), 1 ) ) );
		$this->sync( $order );

		$workflow = $this->create_workflow();
		$item     = current( Queue::claim( $workflow, array( 'user_id' => 1, 'ttl' => 60 ) ) );
		$token    = $item->get_lock_token();

		// Heartbeat extends the lock
		$this->assertEquals( 1, Queue::heartbeat( $token, 3600 ) );
		$this->assertEqualsWithDelta( time() + 3600, Queue::get( $item->get_id() )->get_lock_expires()->getTimestamp(), 5 );
		$this->assertEquals( 0, Queue::heartbeat( 'invalid' ) );

		// Optimistic concurrency
		$this->assertTrue( Queue::verify_lock( $item->get_id(), $token, $item->get_version() ) );
		$new_version = Queue::bump_version( $item->get_id(), $token );
		$this->assertEquals( $item->get_version() + 1, $new_version );
		$this->assertEquals( 'queue_version_conflict', Queue::verify_lock( $item->get_id(), $token, $item->get_version() )->get_error_code() );

		// Release: wrong token fails, correct token makes the item pending again
		$this->assertWPError( Queue::release( $item->get_id(), 'invalid' ) );
		$this->assertTrue( Queue::release( $item->get_id(), $token ) );

		$released = Queue::get( $item->get_id() );
		$this->assertEquals( 'pending', $released->get_status() );
		$this->assertEquals( '', $released->get_lock_token() );
		$this->assertFalse( $released->is_locked() );

		// Error inbox
		$item = current( Queue::claim( $workflow, array( 'user_id' => 1 ) ) );
		$this->assertTrue( Queue::mark_error( $item->get_id(), $item->get_lock_token(), 'Label creation failed' ) );
		$this->assertEquals( 'error', Queue::get( $item->get_id() )->get_status() );
		$this->assertEquals( 'Label creation failed', Queue::get( $item->get_id() )->get_last_error() );
		$this->assertEquals( array(), Queue::claim( $workflow, array( 'user_id' => 1 ) ) );

		$this->assertTrue( Queue::reset( $item->get_id() ) );
		$this->assertEquals( 'pending', Queue::get( $item->get_id() )->get_status() );
	}

	public function test_step_log_shared_across_workflows() {
		$order = $this->create_order( array( array( $this->create_product(), 1 ) ) );
		$item  = current( $this->sync( $order ) );

		$pick_pack = $this->create_workflow( array( 'pick', 'pack' ) );
		$labels    = $this->create_workflow( array( 'pack', 'create_label' ) );

		StepLog::record( $item->get_id(), 'pick', array( 'workflow_id' => $pick_pack->get_id() ) );
		$this->assertCount( 1, Queue::claim( $pick_pack, array( 'user_id' => 1 ) ) );
		Queue::release_all( Queue::get( $item->get_id() )->get_lock_token() );

		StepLog::record( $item->get_id(), 'pack', array( 'workflow_id' => $pick_pack->get_id() ) );

		// All steps of the first workflow are done - it does not claim the item again
		$this->assertCount( 0, Queue::claim( $pick_pack, array( 'user_id' => 1 ) ) );

		// The other workflow still has an open step (create_label), pack counts as done for it as well
		$claimed = Queue::claim( $labels, array( 'user_id' => 1 ) );
		$this->assertCount( 1, $claimed );
		$this->assertEqualsCanonicalizing( array( 'pick', 'pack' ), StepLog::get_done_actions( $item->get_id() ) );

		// Recording again updates the entry
		StepLog::record( $item->get_id(), 'pack', array( 'status' => 'error', 'error' => 'Scale offline' ) );
		$entries = wp_list_pluck( StepLog::get_entries( $item->get_id() ), 'status', 'action' );
		$this->assertEquals( 'error', $entries['pack'] );
	}

	public function test_locked_item_becomes_stale() {
		$order = $this->create_order( array( array( $this->create_product(), 1 ) ) );
		$this->sync( $order );

		$item    = current( Queue::claim( $this->create_workflow(), array( 'user_id' => 1 ) ) );
		$version = $item->get_version();

		$order->set_status( 'cancelled' );
		$order->save();

		// The lock holder keeps the item, it is flagged instead
		$synced = current( $this->sync( $order ) );
		$this->assertEquals( 'locked', $synced->get_status() );
		$this->assertTrue( $synced->is_stale() );
		$this->assertEquals( $version, $synced->get_version() );

		// Releasing it removes it
		$this->assertTrue( Queue::release( $item->get_id(), $item->get_lock_token() ) );
		$released = Queue::get( $item->get_id() );
		$this->assertEquals( 'removed', $released->get_status() );
		$this->assertFalse( $released->is_stale() );
	}

	public function test_reconcile() {
		$order_processing = $this->create_order( array( array( $this->create_product(), 1 ) ) );
		$order_pending    = $this->create_order( array( array( $this->create_product(), 1 ) ), 'pending' );

		delete_option( QueueSync::CURSOR_OPTION );

		// Initial run: eligible orders only
		$this->assertGreaterThanOrEqual( 1, QueueSync::reconcile() );
		$this->assertCount( 1, Queue::get_by_order( $order_processing->get_id() ) );
		$this->assertCount( 0, Queue::get_by_order( $order_pending->get_id() ) );
		$this->assertGreaterThan( 0, absint( get_option( QueueSync::CURSOR_OPTION ) ) );

		// Expired locks are reset
		$item = current( Queue::claim( $this->create_workflow(), array( 'user_id' => 1 ) ) );

		global $wpdb;
		$wpdb->update( $wpdb->stc_fulfillment_queue, array( 'queue_lock_expires_gmt' => gmdate( 'Y-m-d H:i:s', time() - 60 ) ), array( 'queue_id' => $item->get_id() ) );

		QueueSync::reconcile();
		$this->assertEquals( 'pending', Queue::get( $item->get_id() )->get_status() );
	}
}
