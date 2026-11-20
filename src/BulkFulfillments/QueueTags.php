<?php

namespace Vendidero\Shiptastic\BulkFulfillments;

defined( 'ABSPATH' ) || exit;

/**
 * Tags of queue items, used by workflow filters ("including"/"excluding") within the claim SQL: product categories
 * (incl. their parents) and shipping classes of the package's items and the order's payment method. Computed whenever
 * the queue syncs an order (table `stc_fulfillment_queue_tags`), extensions add their own tags via
 * `woocommerce_shiptastic_fulfillment_queue_item_tags` and offer them via `woocommerce_shiptastic_fulfillment_queue_tag_choices`.
 *
 * Tags: cat:{category slug}, class:{shipping class slug}, pay:{payment gateway id}.
 */
class QueueTags {

	const BACKFILL_OPTION = 'woocommerce_shiptastic_fulfillment_queue_tags_version';

	/**
	 * Whether the workflow editor offers the including/excluding filters (Shiptastic Pro). Tags are maintained and
	 * saved filters apply regardless.
	 *
	 * @return bool
	 */
	public static function has_editor_filters() {
		/**
		 * Filter whether the workflow editor offers the including/excluding (tag) filters, enabled by Shiptastic Pro.
		 *
		 * @param bool $enabled
		 *
		 * @package Vendidero/Shiptastic
		 */
		return (bool) apply_filters( 'woocommerce_shiptastic_fulfillment_workflow_tag_filters_enabled', false );
	}

	const VERSION = 1;

	/**
	 * @param \WC_Order $order
	 * @param int[] $order_item_ids Order items of the package (unshipped items and items of unshipped shipments).
	 * @param array $package Package data, see QueueSync::get_order_packages().
	 *
	 * @return string[]
	 */
	public static function get_package_tags( $order, $order_item_ids, $package = array() ) {
		$tags     = array();
		$products = array();

		foreach ( array_unique( array_map( 'absint', $order_item_ids ) ) as $order_item_id ) {
			$item = $order->get_item( $order_item_id );

			if ( ! $item || ! is_callable( array( $item, 'get_product' ) ) ) {
				continue;
			}

			$product = $item->get_product();

			if ( $product ) {
				$products[ $product->get_id() ] = $product;
			}
		}

		foreach ( $products as $product ) {
			// Variations: the categories of their parent.
			$parent_id = $product->get_parent_id() ? $product->get_parent_id() : $product->get_id();

			foreach ( wc_get_product_term_ids( $parent_id, 'product_cat' ) as $term_id ) {
				foreach ( array_merge( array( $term_id ), get_ancestors( $term_id, 'product_cat', 'taxonomy' ) ) as $category_id ) {
					$term = get_term( $category_id, 'product_cat' );

					if ( $term && ! is_wp_error( $term ) ) {
						$tags[] = 'cat:' . $term->slug;
					}
				}
			}

			$shipping_class = $product->get_shipping_class();

			if ( $shipping_class ) {
				$tags[] = 'class:' . $shipping_class;
			}
		}

		if ( $order->get_payment_method() ) {
			$tags[] = 'pay:' . $order->get_payment_method();
		}

		/**
		 * Filter the tags of a queue item (package of an order), used by workflow filters.
		 *
		 * @param string[] $tags
		 * @param \WC_Order $order
		 * @param \WC_Product[] $products Products of the package.
		 * @param array $package Package data (location, shipping_method, shipping_provider, ...).
		 *
		 * @package Vendidero/Shiptastic
		 */
		$tags = (array) apply_filters( 'woocommerce_shiptastic_fulfillment_queue_item_tags', $tags, $order, array_values( $products ), $package );

		return self::sanitize( $tags );
	}

	/**
	 * @param string[] $tags
	 *
	 * @return string[]
	 */
	public static function sanitize( $tags ) {
		$sanitized = array();

		foreach ( (array) $tags as $tag ) {
			$tag = strtolower( trim( wc_clean( (string) $tag ) ) );

			if ( '' !== $tag ) {
				$sanitized[] = substr( $tag, 0, 191 );
			}
		}

		return array_values( array_unique( $sanitized ) );
	}

	/**
	 * @param int $queue_id
	 *
	 * @return string[]
	 */
	public static function get( $queue_id ) {
		global $wpdb;

		return $wpdb->get_col( $wpdb->prepare( "SELECT queue_tag FROM {$wpdb->stc_fulfillment_queue_tags} WHERE queue_tag_queue_id = %d ORDER BY queue_tag ASC", $queue_id ) );
	}

	/**
	 * Replaces the tags of a queue item (only changed tags are written).
	 *
	 * @param int $queue_id
	 * @param string[] $tags
	 */
	public static function set( $queue_id, $tags ) {
		global $wpdb;

		$queue_id = absint( $queue_id );

		if ( ! $queue_id ) {
			return;
		}

		$tags    = self::sanitize( $tags );
		$current = self::get( $queue_id );

		foreach ( array_diff( $current, $tags ) as $tag ) {
			$wpdb->delete(
				$wpdb->stc_fulfillment_queue_tags,
				array(
					'queue_tag_queue_id' => $queue_id,
					'queue_tag'          => $tag,
				)
			);
		}

		foreach ( array_diff( $tags, $current ) as $tag ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->stc_fulfillment_queue_tags} (queue_tag_queue_id, queue_tag) VALUES (%d, %s)", $queue_id, $tag ) );
		}
	}

	public static function delete( $queue_id ) {
		global $wpdb;

		$wpdb->delete( $wpdb->stc_fulfillment_queue_tags, array( 'queue_tag_queue_id' => absint( $queue_id ) ) );
	}

	/**
	 * SQL condition: the queue item has at least one (or none) of the tags.
	 *
	 * @param string[] $tags
	 * @param bool $exclude
	 *
	 * @return string
	 */
	public static function get_condition( $tags, $exclude = false ) {
		global $wpdb;

		$tags = self::sanitize( $tags );

		if ( empty( $tags ) ) {
			return '';
		}

		$placeholders = implode( ',', array_fill( 0, count( $tags ), '%s' ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$exists = $wpdb->prepare( "EXISTS ( SELECT 1 FROM {$wpdb->stc_fulfillment_queue_tags} AS queue_tags WHERE queue_tags.queue_tag_queue_id = {$wpdb->stc_fulfillment_queue}.queue_id AND queue_tags.queue_tag IN ({$placeholders}) )", $tags );

		return $exclude ? 'NOT ' . $exists : $exists;
	}

	/**
	 * Tags offered by the workflow editor (autocomplete): product categories, shipping classes, payment methods.
	 *
	 * @param array $args search (matches the label or tag), include (tags to return, e.g. the labels of selected
	 *                    tags), limit (max. results, 0 = all).
	 *
	 * @return array[] value, label
	 */
	public static function get_choices( $args = array() ) {
		$args    = wp_parse_args(
			$args,
			array(
				'search'  => '',
				'include' => array(),
				'limit'   => 20,
			)
		);
		$search  = trim( (string) $args['search'] );
		$include = self::sanitize( (array) $args['include'] );
		$choices = array();

		// Categories: searched within the database (a shop may have many of them).
		$term_args = array(
			'taxonomy'   => 'product_cat',
			'hide_empty' => false,
		);

		if ( ! empty( $include ) ) {
			$slugs = array();

			foreach ( $include as $tag ) {
				if ( 0 === strpos( $tag, 'cat:' ) ) {
					$slugs[] = substr( $tag, 4 );
				}
			}

			$term_args['slug'] = $slugs;
		} elseif ( '' !== $search ) {
			$term_args['search'] = $search;
			$term_args['number'] = $args['limit'] ? absint( $args['limit'] ) : 0;
		}

		$categories = ( ! empty( $include ) && empty( $term_args['slug'] ) ) ? array() : get_terms( $term_args );

		if ( ! is_wp_error( $categories ) ) {
			foreach ( $categories as $category ) {
				/* translators: %s product category */
				$choices[] = array( 'value' => 'cat:' . $category->slug, 'label' => sprintf( _x( 'Category: %s', 'shipments-queue-tag', 'shiptastic-for-woocommerce' ), self::plain( $category->name ) ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
			}
		}

		if ( function_exists( 'WC' ) && WC()->shipping() ) {
			foreach ( WC()->shipping()->get_shipping_classes() as $shipping_class ) {
				/* translators: %s shipping class */
				$choices[] = array( 'value' => 'class:' . $shipping_class->slug, 'label' => sprintf( _x( 'Shipping class: %s', 'shipments-queue-tag', 'shiptastic-for-woocommerce' ), self::plain( $shipping_class->name ) ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
			}
		}

		if ( function_exists( 'WC' ) && WC()->payment_gateways() ) {
			foreach ( WC()->payment_gateways()->payment_gateways() as $gateway ) {
				if ( 'yes' !== $gateway->enabled ) {
					continue;
				}

				/* translators: %s payment method */
				$choices[] = array( 'value' => 'pay:' . $gateway->id, 'label' => sprintf( _x( 'Payment method: %s', 'shipments-queue-tag', 'shiptastic-for-woocommerce' ), self::plain( $gateway->get_title() ) ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
			}
		}

		/**
		 * Filter the tags offered by the workflow editor (value, label), e.g. tags added by extensions. Results are
		 * narrowed down to the search/included tags afterwards.
		 *
		 * @param array[] $choices
		 * @param array $args search, include, limit
		 *
		 * @package Vendidero/Shiptastic
		 */
		$choices = array_values( (array) apply_filters( 'woocommerce_shiptastic_fulfillment_queue_tag_choices', $choices, $args ) );

		if ( ! empty( $include ) ) {
			$choices = array_filter(
				$choices,
				function ( $choice ) use ( $include ) {
					return in_array( $choice['value'], $include, true );
				}
			);
		} elseif ( '' !== $search ) {
			$needle  = function_exists( 'mb_strtolower' ) ? mb_strtolower( $search ) : strtolower( $search );
			$choices = array_filter(
				$choices,
				function ( $choice ) use ( $needle ) {
					$haystack = $choice['label'] . ' ' . $choice['value'];
					$haystack = function_exists( 'mb_strtolower' ) ? mb_strtolower( $haystack ) : strtolower( $haystack );

					return false !== strpos( $haystack, $needle );
				}
			);
		}

		$choices = array_values( $choices );

		return $args['limit'] && empty( $include ) ? array_slice( $choices, 0, absint( $args['limit'] ) ) : $choices;
	}

	/**
	 * @param string $text Term/gateway names are stored with entities (e.g. &amp;).
	 *
	 * @return string
	 */
	protected static function plain( $text ) {
		return html_entity_decode( wp_strip_all_tags( (string) $text ), ENT_QUOTES, get_bloginfo( 'charset' ) );
	}

	/**
	 * Fills the tags of queued orders once (or after the tags changed, see VERSION): the queue's reconcile re-syncs the
	 * orders with an eligible status from the start.
	 */
	public static function maybe_backfill() {
		if ( (int) get_option( self::BACKFILL_OPTION, 0 ) >= self::VERSION ) {
			return;
		}

		delete_option( QueueSync::CURSOR_OPTION );
		update_option( self::BACKFILL_OPTION, self::VERSION, false );

		if ( QueueSync::is_enabled() && function_exists( 'WC' ) && WC()->queue() ) {
			WC()->queue()->schedule_single( time() + 10, QueueSync::RECONCILE_HOOK, array(), QueueSync::GROUP );
		}
	}
}
