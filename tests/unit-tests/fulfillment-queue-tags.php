<?php

use Vendidero\Shiptastic\BulkFulfillments\Queue;
use Vendidero\Shiptastic\BulkFulfillments\QueueSync;
use Vendidero\Shiptastic\BulkFulfillments\QueueTags;
use Vendidero\Shiptastic\BulkFulfillments\Workflow;

/**
 * Queue item tags (product categories incl. parents, shipping classes, payment method) and the including/excluding
 * workflow filters using them within the claim SQL.
 */
class FulfillmentQueueTagsTest extends \Vendidero\Shiptastic\Tests\Framework\UnitTestCase {

	public function setUp(): void {
		parent::setUp();

		update_option( 'woocommerce_shiptastic_enable_auto_packing', 'no' );
	}

	protected function create_workflow( $filters ) {
		$workflow = new Workflow();
		$workflow->set_props(
			array(
				'title'   => 'Tags',
				'steps'   => array( 'pick' ),
				'filters' => $filters,
			)
		);
		$workflow->save();

		return new Workflow( $workflow->get_id() );
	}

	protected function create_order( $product, $payment_method = 'bacs' ) {
		$order = wc_create_order();
		$item  = new WC_Order_Item_Product();
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
		$order->set_shipping_address_1( 'Musterstr. 12' );
		$order->set_shipping_postcode( '12222' );
		$order->set_shipping_city( 'Berlin' );
		$order->set_payment_method( $payment_method );
		$order->set_status( 'processing' );
		$order->save();

		QueueSync::sync_order( $order->get_id() );

		return wc_get_order( $order->get_id() );
	}

	protected function get_queue_item( $order ) {
		return current( Queue::get_by_order( $order->get_id() ) );
	}

	public function test_tags_and_filters() {
		$parent = wp_insert_term( 'Food', 'product_cat', array( 'slug' => 'food' ) );
		$child  = wp_insert_term( 'Fruit', 'product_cat', array( 'slug' => 'fruit', 'parent' => $parent['term_id'] ) );
		$bulky  = wp_insert_term( 'Bulky', 'product_shipping_class', array( 'slug' => 'bulky' ) );

		$apple = WC_Helper_Product::create_simple_product();
		$apple->set_category_ids( array( $child['term_id'] ) );
		$apple->save();

		$sofa = WC_Helper_Product::create_simple_product();
		$sofa->set_shipping_class_id( $bulky['term_id'] );
		$sofa->save();

		$apple_order = $this->create_order( $apple, 'bacs' );
		$sofa_order  = $this->create_order( $sofa, 'cod' );

		// Categories incl. their parents, shipping classes, the payment method.
		$apple_tags = QueueTags::get( $this->get_queue_item( $apple_order )->get_id() );
		$this->assertContains( 'cat:fruit', $apple_tags );
		$this->assertContains( 'cat:food', $apple_tags );
		$this->assertContains( 'pay:bacs', $apple_tags );
		$this->assertNotContains( 'class:bulky', $apple_tags );

		$sofa_tags = QueueTags::get( $this->get_queue_item( $sofa_order )->get_id() );
		$this->assertContains( 'class:bulky', $sofa_tags );
		$this->assertContains( 'pay:cod', $sofa_tags );

		// Including: any of the tags.
		$food = $this->create_workflow( array( 'tags' => array( 'cat:food' ) ) );
		$this->assertEquals( 1, Queue::count( array( 'workflow' => $food, 'status' => 'pending' ) ) );

		$any = $this->create_workflow( array( 'tags' => array( 'cat:food', 'class:bulky' ) ) );
		$this->assertEquals( 2, Queue::count( array( 'workflow' => $any, 'status' => 'pending' ) ) );

		// Excluding: none of the tags - claims respect it.
		$no_bulky = $this->create_workflow( array( 'exclude_tags' => array( 'class:bulky' ) ) );
		$claimed  = Queue::claim( $no_bulky, array( 'user_id' => 1 ) );
		$this->assertEquals( array( $apple_order->get_id() ), array_values( array_map( function ( $item ) { return $item->get_order_id(); }, $claimed ) ) ); // phpcs:ignore Generic.Functions.FunctionCallArgumentSpacing

		// Tags follow changes of the order (re-sync).
		$apple_order->set_payment_method( 'cod' );
		$apple_order->save();
		QueueSync::sync_order( $apple_order->get_id() );

		$apple_tags = QueueTags::get( $this->get_queue_item( $apple_order )->get_id() );
		$this->assertContains( 'pay:cod', $apple_tags );
		$this->assertNotContains( 'pay:bacs', $apple_tags );

		// The editor offers the filters with Shiptastic Pro only (they apply regardless).
		$this->assertFalse( QueueTags::has_editor_filters() );

		// Editor choices (autocomplete): all (limited), searched, labels of selected tags.
		$choices = wp_list_pluck( QueueTags::get_choices(), 'value' );
		$this->assertContains( 'cat:fruit', $choices );
		$this->assertContains( 'class:bulky', $choices );

		$this->assertEquals( array( 'cat:fruit' ), wp_list_pluck( QueueTags::get_choices( array( 'search' => 'fru' ) ), 'value' ) );
		$this->assertEquals( array( 'class:bulky' ), wp_list_pluck( QueueTags::get_choices( array( 'search' => 'bulk' ) ), 'value' ) );

		$included = wp_list_pluck( QueueTags::get_choices( array( 'include' => array( 'cat:food', 'class:bulky', 'cat:unknown' ) ) ), 'label', 'value' );
		$this->assertEquals( array( 'cat:food', 'class:bulky' ), array_keys( $included ) );
		$this->assertStringContainsString( 'Food', $included['cat:food'] );

		// Sanitized filters.
		$filters = Workflow::sanitize_filters( array( 'tags' => array( ' CAT:Food ', '', 'cat:food' ) ) );
		$this->assertEquals( array( 'cat:food' ), $filters['tags'] );
		$this->assertEquals( array(), $filters['exclude_tags'] );
	}
}
