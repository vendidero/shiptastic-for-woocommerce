<?php

use Vendidero\Shiptastic\Warehouse;
use Vendidero\Shiptastic\Warehouse\LocationSchema;
use Vendidero\Shiptastic\Warehouse\StorageLocations;

/**
 * Storage location schema (pick sequence) and product storage locations.
 */
class StorageLocationsTest extends \Vendidero\Shiptastic\Tests\Framework\UnitTestCase {

	/**
	 * Sorts codes by their pick sequence.
	 */
	protected function sort_codes( LocationSchema $schema, $codes ) {
		usort(
			$codes,
			function ( $a, $b ) use ( $schema ) {
				$compare = strcmp( $schema->get_pick_sequence( $a ), $schema->get_pick_sequence( $b ) );

				return 0 !== $compare ? $compare : strcmp( $a, $b );
			}
		);

		return $codes;
	}

	public function test_natural_sort_without_levels() {
		$schema = new LocationSchema();

		$this->assertEquals( array( 'A-2', 'A-10', 'b-1' ), $this->sort_codes( $schema, array( 'A-10', 'b-1', 'A-2' ) ) );
		$this->assertEquals( '', $schema->get_pick_sequence( '' ) );
	}

	public function test_levels_list_numeric_alpha() {
		$schema = new LocationSchema(
			array(
				'separator' => '-',
				'levels'    => array(
					array(
						'name' => 'Zone',
						'type' => 'list',
					),
					array(
						'name' => 'Aisle',
						'type' => 'numeric',
					),
					array(
						'name'      => 'Bin',
						'type'      => 'alpha',
						'direction' => 'desc',
					),
				),
				// Explicit zone order: cold storage is walked first
				'values'    => array( 'KUEHL', 'A', 'B' ),
			)
		);

		$this->assertEquals(
			array( 'kuehl-1-B', 'KUEHL-1-A', 'A-2-C', 'A-2-A', 'A-10-A', 'B-1-A', 'X-1-A' ),
			$this->sort_codes( $schema, array( 'A-10-A', 'B-1-A', 'A-2-A', 'KUEHL-1-A', 'X-1-A', 'A-2-C', 'kuehl-1-B' ) )
		);

		// Unknown zone sorts last, valid codes validate
		$this->assertTrue( $schema->validate( 'A-03-B' ) );
		$this->assertTrue( $schema->validate( 'A-03' ) );
		$this->assertWPError( $schema->validate( 'X-1-A' ) );
		$this->assertWPError( $schema->validate( 'A-B-1' ) );
		$this->assertWPError( $schema->validate( 'A-1-A-5' ) );

		// Missing trailing levels sort first within their parent
		$this->assertEquals( array( 'A-2', 'A-2-B', 'A-2-A' ), $this->sort_codes( $schema, array( 'A-2-A', 'A-2', 'A-2-B' ) ) );
	}

	public function test_multiple_list_levels() {
		$schema = new LocationSchema(
			array(
				'levels' => array(
					array(
						'name'   => 'Zone',
						'type'   => 'list',
						'values' => array( 'cold', 'dry' ),
					),
					array(
						'name'   => 'Floor',
						'type'   => 'list',
						'values' => array( 'EG', 'OG', 'UG' ),
					),
					array(
						'name' => 'Bin',
						'type' => 'numeric',
					),
					array(
						'name'   => 'Ignored',
						'type'   => 'numeric',
						// Values are only kept for list levels
						'values' => array( 'X' ),
					),
				),
			)
		);

		$this->assertEquals( array( 'COLD', 'DRY' ), $schema->get_level_values( 0 ) );
		$this->assertEquals( array( 'EG', 'OG', 'UG' ), $schema->get_level_values( 1 ) );
		$this->assertEquals( array(), $schema->get_level_values( 3 ) );

		$this->assertEquals(
			array( 'COLD-EG-2', 'COLD-OG-1', 'COLD-UG-1', 'DRY-EG-1', 'DRY-UG-3' ),
			$this->sort_codes( $schema, array( 'DRY-UG-3', 'COLD-UG-1', 'DRY-EG-1', 'COLD-EG-2', 'COLD-OG-1' ) )
		);

		$error = $schema->validate( 'COLD-KG-1' );
		$this->assertWPError( $error );
		$this->assertStringContainsString( 'Floor', $error->get_error_message() );
	}

	public function test_snake_walking_path() {
		$schema = new LocationSchema(
			array(
				'levels' => array(
					array(
						'name' => 'Aisle',
						'type' => 'numeric',
					),
					array(
						'name'  => 'Rack',
						'type'  => 'numeric',
						'snake' => true,
					),
				),
			)
		);

		// Aisle 1 front to back, aisle 2 back to front, aisle 3 front to back
		$this->assertEquals(
			array( '1-1', '1-2', '1-3', '2-3', '2-2', '2-1', '3-1', '3-2' ),
			$this->sort_codes( $schema, array( '2-1', '1-3', '3-2', '2-3', '1-1', '3-1', '2-2', '1-2' ) )
		);
	}

	public function test_overrides_and_sanitize() {
		$schema = new LocationSchema(
			array(
				'separator' => '.',
				'levels'    => array(
					array(
						'type'  => 'numeric',
						'snake' => true,
					),
					array( 'type' => 'alpha' ),
				),
				'overrides' => array( 'packing table' => '2.b' ),
			)
		);

		$levels = $schema->get_levels();

		// Snake is not possible for the first level
		$this->assertFalse( $levels[0]['snake'] );

		$this->assertEquals( array( '1.A', 'Packing Table', '2.C' ), $this->sort_codes( $schema, array( '2.C', 'Packing Table', '1.A' ) ) );
		$this->assertTrue( $schema->validate( 'PACKING TABLE' ) );
	}

	public function test_product_locations_and_sorting() {
		$warehouse = new Warehouse();
		$warehouse->set_title( 'Main' );
		$warehouse->save();

		$products = array();

		foreach ( array( 'a2' => '2-1', 'a1' => '1-5', 'none' => '', 'a10' => '10-1' ) as $key => $code ) {
			$product   = WC_Helper_Product::create_simple_product();
			$s_product = wc_shiptastic_get_product( $product );
			$s_product->set_warehouse_name( 'main' );
			$s_product->set_warehouse_location( $code );
			$product->save();

			$products[ $key ] = $product->get_id();
		}

		$locations = StorageLocations::get_product_locations( array_values( $products ) );

		$this->assertArrayNotHasKey( $products['none'], $locations );
		$this->assertEquals( 'main', $locations[ $products['a1'] ]['warehouse'] );
		$this->assertEquals( '1-5', $locations[ $products['a1'] ]['location_code'] );

		// Natural sort while no schema exists, products without location last
		$this->assertEquals(
			array( $products['a1'], $products['a2'], $products['a10'], $products['none'] ),
			StorageLocations::sort_product_ids( array( $products['none'], $products['a10'], $products['a2'], $products['a1'] ) )
		);

		// Configure descending aisles - sequences are computed on demand, no recomputation needed
		$warehouse = wc_stc_get_warehouse( $warehouse->get_id() );
		$warehouse->set_location_schema(
			array(
				'levels' => array(
					array(
						'type'      => 'numeric',
						'direction' => 'desc',
					),
					array( 'type' => 'numeric' ),
				),
			)
		);
		$warehouse->save();

		$this->assertEquals(
			array( $products['a10'], $products['a2'], $products['a1'], $products['none'] ),
			StorageLocations::sort_product_ids( array( $products['a1'], $products['none'], $products['a2'], $products['a10'] ) )
		);

		// Products without a location are not returned
		$product   = wc_get_product( $products['a2'] );
		$s_product = wc_shiptastic_get_product( $product );
		$s_product->set_warehouse_location( '' );
		$product->save();

		$this->assertArrayNotHasKey( $products['a2'], StorageLocations::get_product_locations( array( $products['a2'] ) ) );
	}

	public function test_sample_codes() {
		$schema = new LocationSchema( LocationSchema::get_default_data() );

		// 2 aisles x 3 racks, walked as snake
		$this->assertEquals(
			array( '1-1', '1-2', '1-3', '2-3', '2-2', '2-1' ),
			$this->sort_codes( $schema, $schema->get_sample_codes() )
		);

		$schema = new LocationSchema(
			array(
				'separator' => '.',
				'levels'    => array(
					array(
						'type'   => 'list',
						'values' => array( 'COLD', 'DRY', 'BULK' ),
					),
					array( 'type' => 'alpha' ),
					array( 'type' => 'numeric' ),
					array( 'type' => 'numeric' ),
				),
			)
		);

		$codes = $schema->get_sample_codes();

		// 2 x 3 x 2 x 1
		$this->assertCount( 12, $codes );
		$this->assertEquals( 'COLD.A.1.1', $codes[0] );
		$this->assertContains( 'DRY.C.2.1', $codes );

		foreach ( $codes as $code ) {
			$this->assertTrue( $schema->validate( $code ) );
		}
	}

	public function test_default_schema_for_new_warehouses() {
		$warehouse = new Warehouse();
		$warehouse->set_title( 'New' );
		$warehouse->save();

		$levels = wc_stc_get_warehouse( $warehouse->get_id() )->get_location_schema_instance()->get_levels();

		$this->assertCount( 2, $levels );
		$this->assertEquals( array( 'Aisle', 'Rack' ), wp_list_pluck( $levels, 'name' ) );
		$this->assertEquals( array( false, true ), wp_list_pluck( $levels, 'snake' ) );

		// An explicitly configured schema is kept
		$warehouse = new Warehouse();
		$warehouse->set_title( 'Custom' );
		$warehouse->set_location_schema( array( 'levels' => array( array( 'type' => 'alpha' ) ) ) );
		$warehouse->save();
		$this->assertCount( 1, wc_stc_get_warehouse( $warehouse->get_id() )->get_location_schema_instance()->get_levels() );

		// Groups have no storage locations
		$group = new Warehouse();
		$group->set_props(
			array(
				'title' => 'Group',
				'type'  => 'group',
			)
		);
		$group->save();
		$this->assertFalse( wc_stc_get_warehouse( $group->get_id() )->get_location_schema_instance()->has_levels() );
	}

	public function test_admin_product_save() {
		$warehouse = new Warehouse();
		$warehouse->set_title( 'Main' );
		$warehouse->set_location_schema( array( 'levels' => array( array( 'type' => 'numeric' ), array( 'type' => 'alpha' ) ) ) );
		$warehouse->save();

		$product = WC_Helper_Product::create_simple_product();

		// Pre-existing: Product customs setters/getters use internal meta keys via (update|get)_meta.
		$this->setExpectedIncorrectUsage( 'is_internal_meta_key' );

		$_POST['_warehouse_name']     = 'main';
		$_POST['_warehouse_location'] = ' 12-b ';

		\Vendidero\Shiptastic\Admin\Admin::save_product( $product );
		$product->save();

		unset( $_POST['_warehouse_name'], $_POST['_warehouse_location'] );

		$s_product = wc_shiptastic_get_product( wc_get_product( $product->get_id() ) );

		$this->assertEquals( 'main', $s_product->get_warehouse_name() );
		$this->assertEquals( '12-B', $s_product->get_warehouse_location() );

		$locations = StorageLocations::get_product_locations( array( $product->get_id() ) );
		$this->assertEquals( '000012000002', $locations[ $product->get_id() ]['pick_sequence'] );
	}

	public function test_variation_inherits_parent_location() {
		$warehouse = new Warehouse();
		$warehouse->set_title( 'Main' );
		$warehouse->save();

		$parent = WC_Helper_Product::create_variation_product();
		$s_parent = wc_shiptastic_get_product( $parent );
		$s_parent->set_warehouse_name( 'main' );
		$s_parent->set_warehouse_location( '3-1' );
		$parent->save();

		$children = $parent->get_children();
		$child    = wc_get_product( $children[0] );
		$other    = wc_get_product( $children[1] );

		$s_child = wc_shiptastic_get_product( $child );
		$s_child->set_warehouse_location( '1-1' );
		$child->save();

		$locations = StorageLocations::get_product_locations( array( $child->get_id(), $other->get_id() ) );

		$this->assertEquals( '1-1', $locations[ $child->get_id() ]['location_code'] );
		$this->assertEquals( '3-1', $locations[ $other->get_id() ]['location_code'] );
		$this->assertEquals( 'main', $locations[ $other->get_id() ]['warehouse'] );
	}
}
