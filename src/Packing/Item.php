<?php

namespace Vendidero\Shiptastic\Packing;

use Vendidero\Shiptastic\Interfaces\PackingItem;
use Vendidero\Shiptastic\Product;

defined( 'ABSPATH' ) || exit;

/**
 * An item to be packed.
 */
abstract class Item implements PackingItem {

	protected $item = null;

	protected $product = null;

	protected $dimensions = array(
		'width'  => 0,
		'length' => 0,
		'depth'  => 0,
	);

	protected $weight = 0;

	protected $total = 0;

	protected $subtotal = 0;

	/**
	 * The inner box (definition) this item represents, if any.
	 *
	 * @var array|null
	 */
	protected $inner_box = null;

	/**
	 * Override dimensions, weight and totals with the data of an inner box (in shop units).
	 *
	 * @param array $box
	 * @param string $dimension_unit
	 * @param string $weight_unit
	 */
	protected function apply_inner_box( $box, $dimension_unit = '', $weight_unit = '' ) {
		$this->inner_box = $box;

		$dimension_unit = empty( $dimension_unit ) ? get_option( 'woocommerce_dimension_unit', 'cm' ) : $dimension_unit;
		$weight_unit    = empty( $weight_unit ) ? get_option( 'woocommerce_weight_unit', 'kg' ) : $weight_unit;
		$count          = max( 1, absint( $box['count'] ) );

		$this->dimensions = array(
			'width'  => (int) ceil( (float) wc_get_dimension( (float) $box['width'], 'mm', $dimension_unit ) ),
			'length' => (int) ceil( (float) wc_get_dimension( (float) $box['length'], 'mm', $dimension_unit ) ),
			'depth'  => (int) ceil( (float) wc_get_dimension( (float) $box['height'], 'mm', $dimension_unit ) ),
		);

		$this->weight   = (int) ceil( (float) wc_get_weight( (float) $box['weight'], 'g', $weight_unit ) );
		$this->total    = (int) round( $this->total / $count );
		$this->subtotal = (int) round( $this->subtotal / $count );
	}

	/**
	 * @return array|null
	 */
	public function get_inner_box() {
		return $this->inner_box;
	}

	/**
	 * @return string
	 */
	public function get_inner_box_key() {
		return $this->inner_box ? (string) $this->inner_box['key'] : '';
	}

	/**
	 * Whether this item is the first part of a unit, e.g. to count units instead of inner boxes.
	 *
	 * @return bool
	 */
	public function is_first_unit_part() {
		return ! $this->inner_box || 1 === absint( $this->inner_box['index'] );
	}

	public function get_reference() {
		return $this->item;
	}

	protected function load_product() {
		$this->product = null;
	}

	/**
	 * @return null|Product
	 */
	public function get_product() {
		if ( is_null( $this->product ) ) {
			$this->load_product();
		}

		return $this->product;
	}

	public function canBePacked( $box, $already_packed_items, int $proposed_x, int $proposed_y, int $proposed_z, int $width, int $length, int $depth ): bool {
		$fits = true;
		$args = array(
			'x'      => $proposed_x,
			'y'      => $proposed_y,
			'z'      => $proposed_z,
			'length' => $length,
			'width'  => $width,
			'depth'  => $depth,
		);

		if ( $product = $this->get_product() ) {
			$shipping_class = $product->get_shipping_class_id();

			if ( $box->get_packaging()->has_shipping_class_restrictions() ) {
				if ( ! $box->get_packaging()->supports_shipping_class( $shipping_class ) ) {
					$fits = false;
				}
			}

			/**
			 * In case grouping is activated make sure that a new item with a different shipping class
			 * is not being packed within the same already existing package.
			 */
			if ( $fits && 'yes' === get_option( 'woocommerce_shiptastic_packing_group_by_shipping_class' ) ) {
				$count     = $already_packed_items->count();
				$last_item = $count > 0 ? $already_packed_items->getIterator()[ $count - 1 ]->getItem() : false;

				if ( $last_item && ( $last_product = $last_item->get_product() ) ) {
					if ( $last_product->get_shipping_class_id() !== $shipping_class ) {
						$fits = false;
					}
				}
			}
		}

		return apply_filters( 'woocommerce_shiptastic_item_fits_packaging', $fits, $this, $box->get_packaging(), $already_packed_items, $args );
	}

	/**
	 * Does this item need to be kept flat / packed "this way up"?
	 */
	public function getKeepFlat(): bool {
		return apply_filters( 'woocommerce_shiptastic_packing_item_keep_flat', false, $this );
	}

	/**
	 * Item SKU etc.
	 */
	public function getDescription(): string {
		$description = $this->get_id();

		if ( $product = $this->get_product() ) {
			$title = $product->get_title();

			if ( $product->get_sku() ) {
				$description = $this->get_product()->get_sku();
			}

			$description = $title . ' (' . $description . ')';
		}

		if ( $this->inner_box ) {
			$description .= ' - ' . InnerBoxes::get_box_label( $this->inner_box );
		}

		return apply_filters( 'woocommerce_shiptastic_packing_item_description', $description, $this );
	}

	/**
	 * Item width in mm.
	 */
	public function getWidth(): int {
		return apply_filters( 'woocommerce_shiptastic_packing_item_width_in_mm', $this->dimensions['width'], $this );
	}

	/**
	 * Item length in mm.
	 */
	public function getLength(): int {
		return apply_filters( 'woocommerce_shiptastic_packing_item_length_in_mm', $this->dimensions['length'], $this );
	}

	/**
	 * Item depth in mm.
	 */
	public function getDepth(): int {
		return apply_filters( 'woocommerce_shiptastic_packing_item_depth_in_mm', $this->dimensions['depth'], $this );
	}

	/**
	 * Item weight in g.
	 */
	public function getWeight(): int {
		return apply_filters( 'woocommerce_shiptastic_packing_item_weight_in_g', $this->weight, $this );
	}

	public function get_dimensions() {
		return $this->dimensions;
	}

	/**
	 * Item total in cents.
	 *
	 * @return int
	 */
	public function get_total(): int {
		return apply_filters( 'woocommerce_shiptastic_packing_item_total_in_cents', $this->total, $this );
	}

	/**
	 * Item subtotal in cents.
	 *
	 * @return int
	 */
	public function get_subtotal(): int {
		return apply_filters( 'woocommerce_shiptastic_packing_item_subtotal_in_cents', $this->subtotal, $this );
	}
}
