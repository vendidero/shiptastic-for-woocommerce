<?php

namespace Vendidero\Shiptastic\Packing;

class DefaultItemSorter implements \DVDoug\BoxPacker\ItemSorter {

	/**
	 * @param Item $item_a
	 * @param Item $item_b
	 *
	 * @return int
	 */
	public function compare( $item_a, $item_b ): int {
		$volume_decider = $item_a->getWidth() * $item_b->getLength() * $item_b->getDepth() <=> $item_a->getWidth() * $item_a->getLength() * $item_a->getDepth();

		if ( 0 !== $volume_decider ) {
			return $volume_decider;
		}

		$weight_decider = $item_b->getWeight() <=> $item_a->getWeight();

		if ( 0 !== $weight_decider ) {
			return $weight_decider;
		}

		return $item_a->getDescription() <=> $item_b->getDescription();
	}
}
