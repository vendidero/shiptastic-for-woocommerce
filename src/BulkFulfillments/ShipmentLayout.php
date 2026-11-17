<?php

namespace Vendidero\Shiptastic\BulkFulfillments;

use Vendidero\Shiptastic\Package;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * The shipments of a queue item's package as edited within the fulfillment app's shipments overlay (available during
 * the whole run): items per shipment, packaging, shipping provider, content weight and dimensions.
 *
 * Changing a shipment's items reopens its shipment scope steps (e.g. pack). Order scope steps of actions depending on
 * the shipment contents (Action::resets_on_shipment_changes(), e.g. pick) are reopened in case the total quantities change.
 */
class ShipmentLayout {

	public static function get_default_status() {
		$status = Package::get_setting( 'auto_default_status' );

		return empty( $status ) ? 'processing' : $status;
	}

	/**
	 * @param \Vendidero\Shiptastic\Shipment $shipment
	 *
	 * @return bool Whether the shipment may be edited within the app. Packed (ready for shipping) shipments without
	 *              a label may be edited, they are reset to the default status in case their items change.
	 */
	public static function can_edit( $shipment ) {
		$can_edit = ! $shipment->is_shipped() && ! $shipment->has_label() && ( $shipment->is_editable() || $shipment->has_status( 'ready-for-shipping' ) );

		/**
		 * Filter whether a shipment may be edited within the fulfillment app.
		 *
		 * @param bool $can_edit
		 * @param \Vendidero\Shiptastic\Shipment $shipment
		 *
		 * @package Vendidero/Shiptastic
		 */
		return apply_filters( 'woocommerce_shiptastic_fulfillment_can_edit_shipment', $can_edit, $shipment );
	}

	/**
	 * @param \Vendidero\Shiptastic\Order $order_shipment
	 * @param \WC_Order_Item $order_item
	 *
	 * @return array
	 */
	/**
	 * Lines (items) of the fulfillment app are identified by a line key: the order item id or, for items
	 * consisting of inner boxes, {order_item_id}:{inner_box}.
	 *
	 * @param int    $order_item_id
	 * @param string $inner_box
	 *
	 * @return int|string
	 */
	public static function get_line_key( $order_item_id, $inner_box = '' ) {
		$order_item_id = absint( $order_item_id );
		$inner_box     = sanitize_key( (string) $inner_box );

		return '' === $inner_box ? $order_item_id : $order_item_id . ':' . $inner_box;
	}

	/**
	 * @param int|string $line_key
	 *
	 * @return array order item id, inner box ('' for complete units)
	 */
	public static function parse_line_key( $line_key ) {
		$parts = explode( ':', (string) $line_key, 2 );

		return array( absint( $parts[0] ), isset( $parts[1] ) ? sanitize_key( $parts[1] ) : '' );
	}

	/**
	 * Normalizes a (submitted) line key, e.g. '12' => 12.
	 *
	 * @param int|string $line_key
	 *
	 * @return int|string
	 */
	public static function normalize_line_key( $line_key ) {
		list( $order_item_id, $inner_box ) = self::parse_line_key( $line_key );

		return self::get_line_key( $order_item_id, $inner_box );
	}

	/**
	 * Converts a line map (line key => quantity) to the item format used to create/sync shipments:
	 * order_item_id => quantity or order_item_id => array( inner_box => quantity ).
	 *
	 * @param array $lines
	 *
	 * @return array
	 */
	public static function get_sync_items( $lines ) {
		$items = array();

		foreach ( $lines as $line_key => $quantity ) {
			list( $order_item_id, $inner_box ) = self::parse_line_key( $line_key );

			if ( '' === $inner_box ) {
				$items[ $order_item_id ] = absint( $quantity );
			} else {
				if ( ! isset( $items[ $order_item_id ] ) || ! is_array( $items[ $order_item_id ] ) ) {
					$items[ $order_item_id ] = array();
				}

				$items[ $order_item_id ][ $inner_box ] = absint( $quantity );
			}
		}

		return $items;
	}

	/**
	 * @param \Vendidero\Shiptastic\Order $order_shipment
	 * @param \WC_Order_Item                $order_item
	 * @param string                        $inner_box Optional inner box key.
	 *
	 * @return array
	 */
	public static function get_item_data( $order_shipment, $order_item, $inner_box = '' ) {
		$product = $order_shipment->get_order_item_product( $order_item );
		$box     = '' !== $inner_box ? \Vendidero\Shiptastic\Packing\InnerBoxes::get_order_item_box( $order_item, $inner_box ) : false;
		$name    = html_entity_decode( wp_strip_all_tags( $order_item->get_name() ), ENT_QUOTES, get_bloginfo( 'charset' ) );

		if ( $box ) {
			$name = sprintf( '%1$s (%2$s)', $name, \Vendidero\Shiptastic\Packing\InnerBoxes::get_box_label( $box ) );
		}

		return array(
			'id'       => self::get_line_key( $order_item->get_id(), $box ? $box['key'] : '' ),
			'name'     => $name,
			'sku'      => $product ? $product->get_sku() : '',
			'gtin'     => $product && is_callable( array( $product->get_product(), 'get_global_unique_id' ) ) ? $product->get_product()->get_global_unique_id() : '',
			'image'    => $product && $product->get_product() ? wp_get_attachment_image_url( $product->get_product()->get_image_id(), 'thumbnail' ) : '',
			'weight'   => $box ? (float) $box['weight'] : ( $product ? (float) $product->get_shipping_weight() : 0.0 ),
			'innerBox' => $box ? $box['key'] : '',
			// Packaging suggestions (packing.js).
			'packing'  => self::get_item_packing_data( $order_item, $box ? $box : null ),
		);
	}

	/**
	 * Packing data of an order item (per unit) used for packaging suggestions, same units and filters as the
	 * server-side packer (Packing\Item): dimensions in mm, weight in g.
	 *
	 * @param \WC_Order_Item $order_item
	 * @param array|null     $inner_box
	 *
	 * @return array width, length, depth, weight, shippingClass
	 */
	public static function get_item_packing_data( $order_item, $inner_box = null ) {
		$data = array(
			'width'         => 0,
			'length'        => 0,
			'depth'         => 0,
			'weight'        => 0,
			'shippingClass' => 0,
		);

		if ( is_a( $order_item, 'WC_Order_Item_Product' ) ) {
			try {
				$packing_item = new \Vendidero\Shiptastic\Packing\OrderItem( $order_item, $inner_box );

				$data = array(
					'width'         => $packing_item->getWidth(),
					'length'        => $packing_item->getLength(),
					'depth'         => $packing_item->getDepth(),
					'weight'        => $packing_item->getWeight(),
					'shippingClass' => $packing_item->get_product() ? absint( $packing_item->get_product()->get_shipping_class_id() ) : 0,
				);
			} catch ( \Exception $e ) {
				// E.g. the product does not exist anymore: no packing data (checked by weight only).
				$data['weight'] = 0;
			}
		}

		return $data;
	}

	/**
	 * Packing data of a packaging used for packaging suggestions, same units, inner dimensions (incl. the inner buffer
	 * setting) and filters as the server-side packer (Packing\PackagingBox): dimensions in mm, weights in g.
	 *
	 * @param \Vendidero\Shiptastic\Packaging $packaging
	 *
	 * @return array width, length, depth (inner), emptyWeight, maxWeight (content, 0 = no limit), costs,
	 *               shippingClasses (restricted to, empty = all)
	 */
	public static function get_packaging_packing_data( $packaging ) {
		$box = \Vendidero\Shiptastic\Packing\Helper::get_packaging_box( $packaging );

		$data = array(
			'width'           => $box->getInnerWidth(),
			'length'          => $box->getInnerLength(),
			'depth'           => $box->getInnerDepth(),
			'emptyWeight'     => $box->getEmptyWeight(),
			'maxWeight'       => $packaging->get_max_content_weight() ? max( 0, $box->getMaxWeight() - $box->getEmptyWeight() ) : 0,
			// Costs depend on the shipping method rules (cart), not available within the app.
			'costs'           => (float) $box->get_costs(),
			'shippingClasses' => array_values( array_map( 'absint', (array) $packaging->get_available_shipping_classes( 'edit' ) ) ),
		);

		/**
		 * Filter to adjust the packing data of a packaging used for packaging suggestions within the fulfillment app.
		 *
		 * @param array $data
		 * @param \Vendidero\Shiptastic\Packaging $packaging
		 *
		 * @package Vendidero/Shiptastic
		 */
		return apply_filters( 'woocommerce_shiptastic_fulfillment_packaging_packing_data', $data, $packaging );
	}

	/**
	 * @return array Packaging list, dimensions are outer dimensions (used as shipment dimensions).
	 */
	public static function get_packaging_data() {
		$packaging_list = array();

		foreach ( wc_stc_get_packaging_list() as $packaging ) {
			$packaging_list[] = array(
				'id'        => $packaging->get_id(),
				'title'     => html_entity_decode( wp_strip_all_tags( $packaging->get_title() ), ENT_QUOTES, get_bloginfo( 'charset' ) ),
				'weight'    => (float) $packaging->get_weight(),
				'maxWeight' => (float) $packaging->get_max_content_weight(),
				'length'    => (float) $packaging->get_length(),
				'width'     => (float) $packaging->get_width(),
				'height'    => (float) $packaging->get_height(),
				'providers' => array_values( (array) $packaging->get_available_shipping_provider() ),
				'packing'   => self::get_packaging_packing_data( $packaging ),
			);
		}

		return $packaging_list;
	}

	/**
	 * @return array List of id (name), title of activated shipping providers.
	 */
	public static function get_provider_data() {
		$providers = array();

		foreach ( wc_stc_get_shipping_provider_select( false ) as $name => $title ) {
			$providers[] = array(
				'id'    => (string) $name,
				'title' => html_entity_decode( wp_strip_all_tags( $title ), ENT_QUOTES, get_bloginfo( 'charset' ) ),
			);
		}

		return $providers;
	}

	/**
	 * Items of the package not yet part of a shipment: line key => available (left for shipping, not
	 * backordered), backordered (left for shipping). Items consisting of inner boxes are split into one line per box.
	 *
	 * @param QueueItem $item
	 *
	 * @return array
	 */
	protected static function get_items_left( $item ) {
		$order_shipment = $item->get_shipment_order();
		$items          = array();

		if ( ! $order_shipment ) {
			return $items;
		}

		foreach ( $order_shipment->get_packages() as $package ) {
			if ( $package['location'] !== $item->get_location() || $order_shipment->get_shipping_method_id( $package['method_id'] ) !== $item->get_shipping_method() ) {
				continue;
			}

			foreach ( $package['item_map'] as $order_item_id => $quantity ) {
				if ( ! empty( $package['inner_boxes'][ $order_item_id ] ) ) {
					foreach ( $package['inner_boxes'][ $order_item_id ] as $inner_box => $box_quantity ) {
						if ( $box_quantity > 0 ) {
							$items[ self::get_line_key( $order_item_id, $inner_box ) ] = array(
								'available'   => absint( $box_quantity ),
								'backordered' => 0,
							);
						}
					}
				} else {
					$items[ $order_item_id ] = array(
						'available'   => absint( $quantity ),
						'backordered' => 0,
					);
				}
			}
		}

		foreach ( $order_shipment->get_backordered_items_left_for_shipping() as $order_item_id => $data ) {
			$product = $order_shipment->get_order_item_product( $data['item'] );

			if ( wc_stc_get_product_location_name( $product ) !== $item->get_location() ) {
				continue;
			}

			$inner_boxes = \Vendidero\Shiptastic\Packing\InnerBoxes::get_order_item_boxes( $order_item_id );
			$line_keys   = empty( $inner_boxes ) ? array( $order_item_id ) : array_map(
				function ( $inner_box ) use ( $order_item_id ) {
					return self::get_line_key( $order_item_id, $inner_box );
				},
				array_keys( $inner_boxes )
			);

			foreach ( $line_keys as $line_key ) {
				if ( ! isset( $items[ $line_key ] ) ) {
					$items[ $line_key ] = array(
						'available'   => 0,
						'backordered' => 0,
					);
				}

				$items[ $line_key ]['backordered'] = absint( $data['quantity'] );
			}
		}

		return $items;
	}

	/**
	 * @param \Vendidero\Shiptastic\Shipment $shipment
	 *
	 * @return array line key => quantity (parent items only)
	 */
	public static function get_shipment_item_map( $shipment ) {
		$map = array();

		foreach ( $shipment->get_items() as $shipment_item ) {
			if ( $shipment_item->get_item_parent_id() > 0 ) {
				continue;
			}

			$line_key         = self::get_line_key( $shipment_item->get_order_item_id(), $shipment_item->get_inner_box() );
			$map[ $line_key ] = ( isset( $map[ $line_key ] ) ? $map[ $line_key ] : 0 ) + $shipment_item->get_quantity();
		}

		return $map;
	}

	/**
	 * @param \Vendidero\Shiptastic\SimpleShipment[] $shipments
	 *
	 * @return array line key => quantity
	 */
	protected static function get_total_quantities( $shipments ) {
		$totals = array();

		foreach ( $shipments as $shipment ) {
			foreach ( self::get_shipment_item_map( $shipment ) as $line_key => $quantity ) {
				$totals[ $line_key ] = ( isset( $totals[ $line_key ] ) ? $totals[ $line_key ] : 0 ) + $quantity;
			}
		}

		ksort( $totals );

		return $totals;
	}

	/**
	 * @param \Vendidero\Shiptastic\Shipment $shipment
	 *
	 * @return array
	 */
	public static function get_shipment_data( $shipment ) {
		return array(
			'id'            => $shipment->get_id(),
			'key'           => 'shipment-' . $shipment->get_id(),
			'status'        => $shipment->get_status(),
			'editable'      => self::can_edit( $shipment ),
			'packaging_id'  => $shipment->get_packaging_id(),
			'provider'      => $shipment->get_shipping_provider(),
			// Empty: calculated from the items.
			'weight'        => (string) $shipment->get_weight( 'edit' ),
			'length'        => (string) $shipment->get_length( 'edit' ),
			'width'         => (string) $shipment->get_width( 'edit' ),
			'height'        => (string) $shipment->get_height( 'edit' ),
			'contentLength' => (float) $shipment->get_content_length(),
			'contentWidth'  => (float) $shipment->get_content_width(),
			'contentHeight' => (float) $shipment->get_content_height(),
			'items'         => (object) self::get_shipment_item_map( $shipment ),
		);
	}

	/**
	 * @param QueueItem $item
	 *
	 * @return array items (incl. total = units in shipments + available, backordered = backordered units left),
	 *               shipments
	 */
	public static function get_data( $item ) {
		$order_shipment = $item->get_shipment_order();
		$data           = array(
			'items'     => array(),
			'shipments' => array(),
		);

		if ( ! $order_shipment ) {
			return $data;
		}

		$order     = $order_shipment->get_order();
		$shipments = Runner::get_package_shipments( $item );
		$totals    = self::get_total_quantities( $shipments );
		$left      = self::get_items_left( $item );

		foreach ( array_unique( array_merge( array_keys( $totals ), array_keys( $left ) ) ) as $line_key ) {
			list( $order_item_id, $inner_box ) = self::parse_line_key( $line_key );

			if ( ! $order_item = $order->get_item( $order_item_id ) ) {
				continue;
			}

			$data['items'][] = array_merge(
				self::get_item_data( $order_shipment, $order_item, $inner_box ),
				array(
					'total'       => ( isset( $totals[ $line_key ] ) ? $totals[ $line_key ] : 0 ) + ( isset( $left[ $line_key ] ) ? $left[ $line_key ]['available'] : 0 ),
					'backordered' => isset( $left[ $line_key ] ) ? $left[ $line_key ]['backordered'] : 0,
				)
			);
		}

		foreach ( $shipments as $shipment ) {
			$data['shipments'][] = self::get_shipment_data( $shipment );
		}

		return $data;
	}

	/**
	 * Applies submitted shipment props: packaging_id, provider, weight (content weight, empty = calculated),
	 * length, width, height (ignored in case a packaging is chosen, the packaging defines the dimensions).
	 * Only props contained in $data are changed.
	 *
	 * @param \Vendidero\Shiptastic\Shipment $shipment
	 * @param array $data
	 *
	 * @return true|WP_Error
	 */
	public static function apply_props( $shipment, $data ) {
		$data = (array) $data;

		if ( array_key_exists( 'packaging_id', $data ) ) {
			$packaging_id = absint( $data['packaging_id'] );

			if ( $packaging_id && ! wc_stc_get_packaging( $packaging_id ) ) {
				return new WP_Error( 'invalid_packaging', _x( 'The packaging does not exist.', 'shipments', 'shiptastic-for-woocommerce' ), array( 'status' => 400 ) );
			}

			$shipment->set_packaging_id( $packaging_id );
		}

		if ( array_key_exists( 'provider', $data ) ) {
			$provider = wc_clean( (string) $data['provider'] );

			if ( '' !== $provider && ! array_key_exists( $provider, wc_stc_get_shipping_provider_select( false ) ) ) {
				return new WP_Error( 'invalid_provider', _x( 'The shipping provider is not available.', 'shipments', 'shiptastic-for-woocommerce' ), array( 'status' => 400 ) );
			}

			$shipment->set_shipping_provider( $provider );
		}

		if ( array_key_exists( 'weight', $data ) ) {
			$shipment->set_weight( '' === (string) $data['weight'] ? '' : wc_format_decimal( $data['weight'] ) );
		}

		if ( ! $shipment->get_packaging_id() ) {
			$packaging_removed = array_key_exists( 'packaging_id', $shipment->get_changes() );

			foreach ( array( 'length', 'width', 'height' ) as $dimension ) {
				if ( array_key_exists( $dimension, $data ) ) {
					$value = '' === (string) $data[ $dimension ] ? '' : wc_format_decimal( $data[ $dimension ] );

					/**
					 * Removing the packaging resets dimensions not explicitly changed on save (Shipment::update_packaging()).
					 * Unchanged values (e.g. the former packaging's dimensions kept by the user) need to be marked as changed.
					 */
					if ( $packaging_removed && (string) $value === (string) $shipment->{"get_{$dimension}"}( 'edit' ) ) {
						$shipment->{"set_{$dimension}"}( '' === $value ? '0' : '' );
					}

					$shipment->{"set_{$dimension}"}( $value );
				}
			}
		}

		return true;
	}

	/**
	 * @param \Vendidero\Shiptastic\SimpleShipment $shipment
	 * @param \WC_Order $order
	 * @param array $items line key => quantity
	 */
	protected static function sync_shipment_items( $shipment, $order, $items ) {
		foreach ( $items as $line_key => $quantity ) {
			list( $order_item_id, $inner_box ) = self::parse_line_key( $line_key );

			if ( $shipment_item = $shipment->get_item_by_order_item_id( $order_item_id, $inner_box ) ) {
				$shipment_item->sync( array( 'quantity' => $quantity ) );
			} elseif ( $order_item = $order->get_item( $order_item_id ) ) {
				$args = array( 'quantity' => $quantity );

				if ( '' !== $inner_box ) {
					$args['inner_box'] = $inner_box;
				}

				$new_item = wc_stc_create_shipment_item( $shipment, $order_item, $args );

				if ( ! is_wp_error( $new_item ) ) {
					$shipment->add_item( $new_item );
				}
			}
		}

		foreach ( $shipment->get_items() as $shipment_item ) {
			if ( $shipment_item->get_item_parent_id() > 0 ) {
				continue;
			}

			if ( ! isset( $items[ self::get_line_key( $shipment_item->get_order_item_id(), $shipment_item->get_inner_box() ) ] ) ) {
				$shipment->remove_item( $shipment_item->get_id() );
			}
		}
	}

	/**
	 * Applies a shipment layout to the package of a (locked) queue item.
	 *
	 * @param QueueItem $item
	 * @param array $shipments List of id (existing id or temporary key for new shipments), items (line key => quantity)
	 *                         and props (see apply_props()). Package shipments missing from the list (or without items) are removed.
	 *
	 * @return array|WP_Error shipment_ids, id_map (temporary key => id), changed (shipment ids with changed items)
	 */
	public static function apply( $item, $shipments ) {
		$order_shipment = $item->get_shipment_order();

		if ( ! $order_shipment ) {
			return new WP_Error( 'order_not_found', _x( 'The order does not exist.', 'shipments', 'shiptastic-for-woocommerce' ), array( 'status' => 404 ) );
		}

		$order    = $order_shipment->get_order();
		$existing = Runner::get_package_shipments( $item );
		$before   = self::get_total_quantities( $existing );
		$allowed  = array();
		$layout   = array();
		$assigned = array();

		foreach ( self::get_items_left( $item ) as $line_key => $quantities ) {
			$allowed[ $line_key ] = $quantities['available'] + $quantities['backordered'];
		}

		foreach ( $before as $line_key => $quantity ) {
			$allowed[ $line_key ] = ( isset( $allowed[ $line_key ] ) ? $allowed[ $line_key ] : 0 ) + $quantity;
		}

		$shipments = array_values( (array) $shipments );
		$submitted = array_map(
			function ( $shipment_data ) {
				$shipment_data = (array) $shipment_data;

				return isset( $shipment_data['id'] ) ? (string) $shipment_data['id'] : '';
			},
			$shipments
		);

		// Locked shipments are kept as they are, even if missing from the layout.
		foreach ( $existing as $shipment_id => $shipment ) {
			if ( ! self::can_edit( $shipment ) && ! in_array( (string) $shipment_id, $submitted, true ) ) {
				$shipments[] = array( 'id' => $shipment_id );
			}
		}

		foreach ( $shipments as $shipment_data ) {
			$shipment_data = wp_parse_args(
				(array) $shipment_data,
				array(
					'id'    => '',
					'items' => array(),
				)
			);

			$is_existing = is_numeric( $shipment_data['id'] ) && isset( $existing[ absint( $shipment_data['id'] ) ] );

			// Locked shipments keep their items.
			if ( $is_existing && ! self::can_edit( $existing[ absint( $shipment_data['id'] ) ] ) ) {
				$shipment_data['items'] = self::get_shipment_item_map( $existing[ absint( $shipment_data['id'] ) ] );
			}

			$items = array();

			foreach ( (array) $shipment_data['items'] as $line_key => $quantity ) {
				$line_key = self::normalize_line_key( $line_key );
				$quantity = absint( $quantity );

				if ( $quantity > 0 && isset( $allowed[ $line_key ] ) ) {
					$items[ $line_key ]    = $quantity;
					$assigned[ $line_key ] = ( isset( $assigned[ $line_key ] ) ? $assigned[ $line_key ] : 0 ) + $quantity;
				}
			}

			if ( ! empty( $items ) ) {
				ksort( $items );
				$layout[] = array_merge( $shipment_data, array( 'items' => $items ) );
			}
		}

		foreach ( $assigned as $line_key => $quantity ) {
			if ( $quantity > $allowed[ $line_key ] ) {
				list( $order_item_id, $inner_box ) = self::parse_line_key( $line_key );
				$order_item                        = $order->get_item( $order_item_id );
				$item_name                         = $order_item ? self::get_item_data( $order_shipment, $order_item, $inner_box )['name'] : $line_key;

				/* translators: 1: item name 2: max quantity */
				return new WP_Error( 'invalid_quantity', sprintf( _x( '%1$s may be added %2$d time(s) at most.', 'shipments', 'shiptastic-for-woocommerce' ), $item_name, $allowed[ $line_key ] ), array( 'status' => 400 ) );
			}
		}

		if ( empty( $layout ) ) {
			return new WP_Error( 'no_shipments', _x( 'Please add at least one item to a shipment.', 'shipments', 'shiptastic-for-woocommerce' ), array( 'status' => 400 ) );
		}

		$id_map  = array();
		$kept    = array();
		$changed = array();

		foreach ( $layout as $shipment_data ) {
			$props = isset( $shipment_data['props'] ) ? (array) $shipment_data['props'] : array();

			if ( is_numeric( $shipment_data['id'] ) && isset( $existing[ absint( $shipment_data['id'] ) ] ) ) {
				$shipment = $existing[ absint( $shipment_data['id'] ) ];
				$kept[]   = $shipment->get_id();

				if ( ! self::can_edit( $shipment ) ) {
					continue;
				}

				$current = self::get_shipment_item_map( $shipment );
				ksort( $current );

				if ( $current !== $shipment_data['items'] ) {
					$changed[] = $shipment->get_id();

					// E.g. packed shipments need to be packed again.
					if ( ! $shipment->is_editable() ) {
						$shipment->set_status( self::get_default_status() );
					}

					self::sync_shipment_items( $shipment, $order, $shipment_data['items'] );
				}

				$applied = self::apply_props( $shipment, $props );

				if ( is_wp_error( $applied ) ) {
					return $applied;
				}

				$shipment->save();
				continue;
			}

			$shipment = wc_stc_create_shipment(
				$order_shipment,
				array(
					'items' => self::get_sync_items( $shipment_data['items'] ),
					'props' => array(
						'status'          => self::get_default_status(),
						'location'        => $item->get_location(),
						'shipping_method' => $item->get_shipping_method(),
					),
				)
			);

			if ( is_wp_error( $shipment ) ) {
				return $shipment;
			}

			$applied = self::apply_props( $shipment, $props );

			if ( is_wp_error( $applied ) ) {
				return $applied;
			}

			$shipment->save();
			$order_shipment->add_shipment( $shipment );

			$id_map[ (string) $shipment_data['id'] ] = $shipment->get_id();
			$kept[]                                  = $shipment->get_id();
			$changed[]                               = $shipment->get_id();
		}

		foreach ( $existing as $shipment_id => $shipment ) {
			if ( ! in_array( $shipment_id, $kept, true ) && self::can_edit( $shipment ) ) {
				$order_shipment->remove_shipment( $shipment_id );
				$changed[] = $shipment_id;
			}
		}

		$order_shipment->validate_shipment_item_quantities();
		$order_shipment->save();

		if ( ! empty( $changed ) ) {
			self::reopen_steps( $item, $changed, self::get_total_quantities( Runner::get_package_shipments( $item ) ) !== $before );
		}

		return array(
			'shipment_ids' => $kept,
			'id_map'       => $id_map,
			'changed'      => $changed,
		);
	}

	/**
	 * Reopens the steps affected by changed shipments: their shipment scope steps, the order scope roll-up entries of
	 * shipment scope actions and - in case the total quantities changed - order scope steps of actions depending on
	 * the shipment contents.
	 *
	 * @param QueueItem $item
	 * @param int[] $shipment_ids
	 * @param bool $totals_changed
	 */
	protected static function reopen_steps( $item, $shipment_ids, $totals_changed ) {
		$entries         = StepLog::get_entries( $item->get_id() );
		$shipment_scoped = array();

		foreach ( $entries as $entry ) {
			if ( $entry['shipment_id'] > 0 ) {
				$shipment_scoped[ $entry['action'] ] = true;
			}
		}

		foreach ( $entries as $entry ) {
			$action = ActionRegistry::get( $entry['action'] );

			if ( $entry['shipment_id'] > 0 ) {
				$reopen = in_array( $entry['shipment_id'], $shipment_ids, true );
			} else {
				$reopen = isset( $shipment_scoped[ $entry['action'] ] ) || ( $totals_changed && $action && $action::resets_on_shipment_changes() );
			}

			if ( $reopen ) {
				StepLog::delete( $item->get_id(), $entry['action'], $entry['shipment_id'] );
			}
		}
	}
}
