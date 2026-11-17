<?php

namespace Vendidero\Shiptastic\BulkFulfillments;

defined( 'ABSPATH' ) || exit;

/**
 * Order details within the fulfillment app. The app reads and updates orders via the WooCommerce core REST API
 * (wc/v3/orders). To support custom shipping address fields, the fields shown/saved on the edit order page
 * (`woocommerce_admin_shipping_fields`) are respected:
 *
 * - GET with `stc_address_fields=1`: the response contains `stc_shipping_fields` (key, label, type, options, value, ...)
 * - PUT/POST with `stc_address_fields=1` and `stc_shipping` (key => value): saved like the edit order page does
 *   (update callback, setter or meta)
 *
 * Packed shipments (ready for shipping, no label) are kept in sync with address corrections.
 */
class OrderDetails {

	const REQUEST_FLAG = 'stc_address_fields';

	public static function init() {
		add_action( 'woocommerce_before_order_object_save', array( __CLASS__, 'detect_address_change' ), 20 );
		add_filter( 'woocommerce_rest_prepare_shop_order_object', array( __CLASS__, 'add_shipping_fields' ), 10, 3 );
		add_filter( 'woocommerce_rest_pre_insert_shop_order_object', array( __CLASS__, 'save_shipping_fields' ), 10, 2 );
	}

	/**
	 * The shipping fields of the edit order page incl. fields added via `woocommerce_admin_shipping_fields`.
	 *
	 * @param \WC_Order $order
	 *
	 * @return array
	 */
	public static function get_admin_shipping_fields( $order ) {
		if ( ! class_exists( 'WC_Meta_Box_Order_Data' ) && defined( 'WC_ABSPATH' ) && file_exists( WC_ABSPATH . 'includes/admin/meta-boxes/class-wc-meta-box-order-data.php' ) ) {
			include_once WC_ABSPATH . 'includes/admin/meta-boxes/class-wc-meta-box-order-data.php';
		}

		// The edit order page's definition (protected), incl. its filter.
		if ( class_exists( 'WC_Meta_Box_Order_Data' ) && method_exists( 'WC_Meta_Box_Order_Data', 'get_shipping_fields' ) ) {
			try {
				$method = new \ReflectionMethod( 'WC_Meta_Box_Order_Data', 'get_shipping_fields' );
				$method->setAccessible( true );

				return (array) $method->invoke( null, $order, 'edit' );
			} catch ( \Exception $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
			}
		}

		// Fallback: the edit order page's defaults, filtered the same way.
		return (array) apply_filters(
			'woocommerce_admin_shipping_fields',
			array(
				'first_name' => array( 'label' => _x( 'First name', 'shipments', 'shiptastic-for-woocommerce' ) ),
				'last_name'  => array( 'label' => _x( 'Last name', 'shipments', 'shiptastic-for-woocommerce' ) ),
				'company'    => array( 'label' => _x( 'Company', 'shipments', 'shiptastic-for-woocommerce' ) ),
				'address_1'  => array( 'label' => _x( 'Address line 1', 'shipments', 'shiptastic-for-woocommerce' ) ),
				'address_2'  => array( 'label' => _x( 'Address line 2', 'shipments', 'shiptastic-for-woocommerce' ) ),
				'city'       => array( 'label' => _x( 'City', 'shipments', 'shiptastic-for-woocommerce' ) ),
				'postcode'   => array( 'label' => _x( 'Postcode / ZIP', 'shipments', 'shiptastic-for-woocommerce' ) ),
				'country'    => array(
					'label' => _x( 'Country / Region', 'shipments', 'shiptastic-for-woocommerce' ),
					'type'  => 'select',
				),
				'state'      => array( 'label' => _x( 'State / County', 'shipments', 'shiptastic-for-woocommerce' ) ),
				'phone'      => array( 'label' => _x( 'Phone', 'shipments', 'shiptastic-for-woocommerce' ) ),
			),
			$order,
			'edit'
		);
	}

	/**
	 * @param \WC_Order $order
	 * @param string $key
	 * @param array $field
	 *
	 * @return string The value as loaded by the edit order page.
	 */
	protected static function get_field_value( $order, $key, $field ) {
		if ( isset( $field['value'] ) ) {
			return (string) $field['value'];
		}

		if ( is_callable( array( $order, 'get_shipping_' . $key ) ) ) {
			return (string) $order->{"get_shipping_{$key}"}( 'edit' );
		}

		return (string) $order->get_meta( isset( $field['id'] ) ? $field['id'] : '_shipping_' . $key );
	}

	/**
	 * @param \WC_Order $order
	 *
	 * @return array[] key, label, type (text, select, country, state, tel), options (select), value, wide (full width),
	 *                 required, placeholder
	 */
	public static function get_shipping_fields_data( $order ) {
		$data = array();

		foreach ( self::get_admin_shipping_fields( $order ) as $key => $field ) {
			$field = (array) $field;
			$type  = isset( $field['type'] ) ? $field['type'] : 'text';

			if ( 'hidden' === $type ) {
				continue;
			}

			if ( 'country' === $key || 'state' === $key ) {
				$type = $key;
			} elseif ( 'phone' === $key && 'text' === $type ) {
				$type = 'tel';
			} elseif ( ! in_array( $type, array( 'select', 'textarea', 'email', 'tel' ), true ) ) {
				$type = 'text';
			}

			$options = array();

			if ( 'select' === $type && ! empty( $field['options'] ) ) {
				foreach ( (array) $field['options'] as $option_value => $option_label ) {
					$options[] = array(
						'value' => (string) $option_value,
						'label' => html_entity_decode( wp_strip_all_tags( $option_label ), ENT_QUOTES, get_bloginfo( 'charset' ) ),
					);
				}
			}

			$wrapper_class = isset( $field['wrapper_class'] ) ? (string) $field['wrapper_class'] : '';

			$data[] = array(
				'key'         => (string) $key,
				'label'       => html_entity_decode( wp_strip_all_tags( isset( $field['label'] ) ? $field['label'] : $key ), ENT_QUOTES, get_bloginfo( 'charset' ) ),
				'type'        => $type,
				'options'     => $options,
				'value'       => self::get_field_value( $order, $key, $field ),
				'wide'        => false !== strpos( $wrapper_class, 'form-field-wide' ) || in_array( $key, array( 'company', 'address_1', 'address_2' ), true ) || 'textarea' === $type,
				'required'    => ! empty( $field['required'] ),
				'placeholder' => isset( $field['placeholder'] ) ? wp_strip_all_tags( (string) $field['placeholder'] ) : '',
			);
		}

		return $data;
	}

	/**
	 * Adds the edit order page's shipping fields (incl. values) to the core REST API order response on request.
	 *
	 * @param \WP_REST_Response $response
	 * @param \WC_Order $order
	 * @param \WP_REST_Request $request
	 *
	 * @return \WP_REST_Response
	 */
	public static function add_shipping_fields( $response, $order, $request ) {
		if ( empty( $request[ self::REQUEST_FLAG ] ) || ! is_a( $order, 'WC_Order' ) ) {
			return $response;
		}

		$data                        = $response->get_data();
		$data['stc_shipping_fields'] = self::get_shipping_fields_data( $order );
		$response->set_data( $data );

		return $response;
	}

	/**
	 * Saves `stc_shipping` values (key => value) like the edit order page does: the field's update callback, a setter
	 * or meta.
	 *
	 * @param \WC_Order $order
	 * @param \WP_REST_Request $request
	 *
	 * @return \WC_Order
	 */
	public static function save_shipping_fields( $order, $request ) {
		if ( empty( $request[ self::REQUEST_FLAG ] ) || ! is_array( $request['stc_shipping'] ) || ! is_a( $order, 'WC_Order' ) ) {
			return $order;
		}

		$posted = $request['stc_shipping'];

		foreach ( self::get_admin_shipping_fields( $order ) as $key => $field ) {
			$field = (array) $field;

			if ( ! array_key_exists( $key, $posted ) || ( isset( $field['type'] ) && 'hidden' === $field['type'] ) ) {
				continue;
			}

			$id    = isset( $field['id'] ) ? $field['id'] : '_shipping_' . $key;
			$value = wc_clean( wp_unslash( (string) $posted[ $key ] ) );

			if ( isset( $field['update_callback'] ) && is_callable( $field['update_callback'] ) ) {
				call_user_func( $field['update_callback'], $id, $value, $order );
			} elseif ( is_callable( array( $order, 'set_shipping_' . $key ) ) ) {
				$order->{"set_shipping_{$key}"}( $value );
			} else {
				$order->update_meta_data( $id, $value );
			}
		}

		return $order;
	}

	/**
	 * Shiptastic syncs the address of editable shipments on order updates. Packed shipments (ready for shipping) without
	 * a label are not editable, but an address correction (e.g. within the fulfillment app) must reach them before the
	 * label is created. Detects a changed shipping address and updates these shipments after saving.
	 *
	 * @param \WC_Order $order
	 */
	public static function detect_address_change( $order ) {
		if ( ! is_a( $order, 'WC_Order' ) || ! $order->get_id() ) {
			return;
		}

		$changes = $order->get_changes();

		if ( empty( $changes['shipping'] ) && ! array_key_exists( 'billing', $changes ) ) {
			return;
		}

		$order_id = $order->get_id();

		add_action(
			'woocommerce_after_order_object_save',
			function ( $saved ) use ( $order_id ) {
				if ( $saved->get_id() !== $order_id ) {
					return;
				}

				self::sync_packed_shipments( $saved );
			},
			200
		);
	}

	/**
	 * @param \WC_Order $order
	 */
	protected static function sync_packed_shipments( $order ) {
		$order_shipment = wc_stc_get_shipment_order( $order );

		if ( ! $order_shipment ) {
			return;
		}

		$address = array_merge(
			$order->has_shipping_address() ? $order->get_address( 'shipping' ) : $order->get_address( 'billing' ),
			array( 'email' => $order->get_billing_email() )
		);

		if ( is_callable( array( $order, 'get_shipping_phone' ) ) && $order->get_shipping_phone() ) {
			$address['phone'] = $order->get_shipping_phone();
		}

		foreach ( $order_shipment->get_simple_shipments() as $shipment ) {
			// Editable shipments are synced by Shiptastic itself.
			if ( $shipment->is_editable() || ! ShipmentLayout::can_edit( $shipment ) ) {
				continue;
			}

			$shipment->set_address( array_merge( (array) $shipment->get_address(), $address ) );
			$shipment->set_country( $address['country'] );
			$shipment->save();
		}
	}
}
