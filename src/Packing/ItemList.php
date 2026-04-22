<?php

namespace Vendidero\Shiptastic\Packing;

use ArrayIterator;
use Traversable;
use DVDoug\BoxPacker\ConstrainedPlacementItem;
use DVDoug\BoxPacker\Item;
use DVDoug\BoxPacker\PackedItemList;

defined( 'ABSPATH' ) || exit;

/**
 * An item to be packed.
 */
class ItemList extends \DVDoug\BoxPacker\ItemList {

	private $list = array();

	private $is_sorted = false;

	/**
	 * Does this list contain constrained items?
	 */
	private $has_constrained_items = null;

	private $sorter;

	public function __construct( $sorter = null ) {
		$this->sorter = null === $sorter ? apply_filters( 'shiptastic_packing_item_sorter', new DefaultItemSorter() ) : $sorter;

		parent::__construct( $sorter );
	}

	/**
	 * Do a bulk create.
	 *
	 * @param Item[] $items
	 */
	public static function fromArray( array $items, bool $pre_sorted = false ): self {
		$list            = new self();
		$list->list      = array_reverse( $items ); // internal sort is largest at the end
		$list->is_sorted = $pre_sorted;

		return $list;
	}

	public function insert( Item $item, int $qty = 1 ): void {
		for ( $i = 0; $i < $qty; ++$i ) {
			$this->list[] = $item;
		}
		$this->is_sorted = false;

		if ( ! is_null( $this->has_constrained_items ) ) { // normally lazy evaluated, override if that's already been done
			$this->has_constrained_items = $this->has_constrained_items || $item instanceof ConstrainedPlacementItem;
		}
	}

	private function maybe_sort() {
		if ( ! $this->is_sorted ) {
			usort( $this->list, array( $this->sorter, 'compare' ) );
			$this->list      = array_reverse( $this->list ); // internal sort is largest at the end
			$this->is_sorted = true;
		}
	}

	/**
	 * Remove item from list.
	 */
	public function remove( Item $item ): void {
		$this->maybe_sort();

		end( $this->list );
		do {
			if ( current( $this->list ) === $item ) {
				unset( $this->list[ key( $this->list ) ] );

				return;
			}
		} while ( prev( $this->list ) !== false );
	}

	public function removePackedItems( PackedItemList $packed_item_list ): void {
		foreach ( $packed_item_list as $packed_item ) {
			end( $this->list );
			do {
				if ( current( $this->list ) === $packed_item->getItem() ) {
					unset( $this->list[ key( $this->list ) ] );

					break;
				}
			} while ( prev( $this->list ) !== false );
		}
	}

	/**
	 * @internal
	 */
	public function extract(): Item {
		$this->maybe_sort();

		return array_pop( $this->list );
	}

	/**
	 * @internal
	 */
	public function top(): Item {
		$this->maybe_sort();

		return $this->list[ array_key_last( $this->list ) ];
	}

	/**
	 * @internal
	 */
	public function topN( int $n ): self {
		$this->maybe_sort();

		$top_n_list            = new self();
		$top_n_list->list      = array_slice( $this->list, -$n, $n );
		$top_n_list->is_sorted = true;

		return $top_n_list;
	}

	/**
	 * @return Traversable<Item>
	 */
	public function getIterator(): Traversable {
		$this->maybe_sort();

		return new ArrayIterator( array_reverse( $this->list ) );
	}

	/**
	 * Number of items in list.
	 */
	public function count(): int {
		return count( $this->list );
	}

	/**
	 * Does this list contain items with constrained placement criteria.
	 */
	public function hasConstrainedItems(): bool {
		if ( is_null( $this->has_constrained_items ) ) {
			$this->has_constrained_items = false;
			foreach ( $this->list as $item ) {
				if ( $item instanceof ConstrainedPlacementItem ) {
					$this->has_constrained_items = true;
					break;
				}
			}
		}

		return $this->has_constrained_items;
	}
}
