<?php

namespace Vendidero\Shiptastic\ShippingProvider;

defined( 'ABSPATH' ) || exit;

class PickupLocation {

	protected $code = '';

	protected $type = '';

	protected $label = '';

	protected $latitude = '';

	protected $longitude = '';

	protected $shipping_provider_name = '';

	protected $supports_customer_number = false;

	protected $customer_number_is_mandatory = false;

	protected $address = array();

	protected $address_replacement_map = array();

	protected $max_dimensions = array();

	protected $max_weight = '';

	protected $meta = array();

	public function __construct( $args ) {
		$core_args = array(
			'code'                         => '',
			'type'                         => '',
			'label'                        => '',
			'latitude'                     => '',
			'longitude'                    => '',
			'shipping_provider_name'       => '',
			'supports_customer_number'     => false,
			'customer_number_is_mandatory' => false,
			'address'                      => array(),
			'address_replacement_map'      => array(),
			'max_dimensions'               => array(),
			'max_weight'                   => '',
		);

		$args = wp_parse_args( $args, $core_args );

		if ( empty( $args['code'] ) ) {
			$args['code'] = sanitize_key( $args['label'] );
		}

		if ( empty( $args['code'] ) ) {
			throw new \Exception( esc_html( 'A pickup location needs a code.' ), 500 );
		}

		$code_parts      = wc_stc_get_pickup_location_code_parts( $args['code'] );
		$args['code']    = $code_parts['code'];
		$args['address'] = wp_parse_args(
			(array) $args['address'],
			array(
				'postcode' => $code_parts['postcode'],
				'country'  => $code_parts['country'],
				'company'  => '',
			)
		);

		if ( empty( $args['address']['company'] ) ) {
			$args['address']['company'] = $args['label'];
		}

		$args['max_dimensions'] = wp_parse_args(
			(array) $args['max_dimensions'],
			array(
				'length' => '',
				'width'  => '',
				'height' => '',
			)
		);

		if ( ! empty( $code_parts['shipping_provider'] ) ) {
			$args['shipping_provider_name'] = $code_parts['shipping_provider'];
		}

		foreach ( $args as $arg_name => $value ) {
			if ( is_callable( array( $this, "set_{$arg_name}" ) ) ) {
				$this->{"set_{$arg_name}"}( $value );
			} else {
				$this->update_meta_data( $arg_name, $value );
			}
		}
	}

	public function get_id() {
		return $this->get_code();
	}

	public function get_code( $context = 'view' ) {
		if ( 'view' === $context ) {
			$provider_name = str_replace( '_', '#', $this->get_shipping_provider_name() );

			return sanitize_key( "{$this->code}_{$this->get_country()}_{$this->get_postcode()}" . ( ! empty( $provider_name ) ? "_{$provider_name}" : '' ) );
		} else {
			return $this->code;
		}
	}

	public function set_code( $code ) {
		$this->code = $code;
	}

	public function get_label() {
		return $this->label;
	}

	public function set_label( $label ) {
		$this->label = $label;
	}

	public function set_max_dimensions( $dimensions ) {
		$this->max_dimensions = $dimensions;
	}

	public function get_max_dimensions() {
		return $this->max_dimensions;
	}

	public function set_max_weight( $weight ) {
		$this->max_weight = $weight;
	}

	public function get_max_weight() {
		return $this->max_weight;
	}

	public function get_shipping_provider_name() {
		return $this->shipping_provider_name;
	}

	public function set_shipping_provider_name( $provider_name ) {
		$this->shipping_provider_name = is_a( $provider_name, '\Vendidero\Shiptastic\Interfaces\ShippingProvider' ) ? $provider_name->get_name() : $provider_name;
	}

	public function get_type() {
		return $this->type;
	}

	public function set_type( $type ) {
		$this->type = $type;
	}

	public function get_address() {
		return $this->address;
	}

	public function set_address( $address ) {
		$this->address = wp_parse_args(
			(array) $address,
			array(
				'address_1' => '',
				'city'      => '',
				'postcode'  => '',
				'country'   => '',
				'state'     => '',
				'company'   => '',
			)
		);
	}

	public function get_postcode() {
		return $this->address['postcode'];
	}

	public function get_country() {
		return $this->address['country'];
	}

	public function get_city() {
		return $this->address['city'];
	}

	public function get_state() {
		return $this->address['state'];
	}

	public function get_address_1() {
		return $this->address['address_1'];
	}

	public function supports_customer_number() {
		return $this->supports_customer_number;
	}

	public function set_supports_customer_number( $supports ) {
		$this->supports_customer_number = wc_string_to_bool( $supports );
	}

	public function supports_cart( $cart_data ) {
		$cart_data = wp_parse_args(
			$cart_data,
			array(
				'max_dimensions' => array(),
				'max_weight'     => 0.0,
			)
		);

		return $this->supports_dimensions( $cart_data['max_dimensions'] ) && $this->supports_weight( $cart_data['max_weight'] );
	}

	public function has_max_dimension( $dim = 'length' ) {
		return array_key_exists( $dim, $this->max_dimensions ) && '' !== $this->max_dimensions[ $dim ];
	}

	/**
	 * @param array $dimensions
	 *
	 * @return boolean
	 */
	public function supports_dimensions( $dimensions ) {
		$dimensions = wp_parse_args(
			$dimensions,
			array(
				'width'  => 0.0,
				'height' => 0.0,
				'length' => 0.0,
			)
		);

		if ( $this->has_max_dimension( 'length' ) || $this->has_max_dimension( 'width' ) || $this->has_max_dimension( 'height' ) ) {
			$max_dimensions  = array_values( $this->get_max_dimensions() );
			$real_dimensions = array_values( $dimensions );

			/**
			 * Sort in ascending order
			 */
			sort( $max_dimensions );
			sort( $real_dimensions );

			$supports_dimensions = true;

			foreach ( $max_dimensions as $k => $max_dimension ) {
				if ( '' === $max_dimension || ! isset( $real_dimensions[ $k ] ) ) {
					continue;
				}

				if ( $real_dimensions[ $k ] > $max_dimension ) {
					$supports_dimensions = false;
					break;
				}
			}

			return $supports_dimensions;
		}

		return true;
	}

	/**
	 * @param $weight
	 *
	 * @return boolean
	 */
	public function supports_weight( $weight ) {
		if ( '' !== $this->get_max_weight() ) {
			$max_weight = (float) $this->get_max_weight();

			if ( (float) $weight > $max_weight ) {
				return false;
			}
		}

		return true;
	}

	public function customer_number_is_mandatory() {
		return $this->customer_number_is_mandatory;
	}

	public function set_customer_number_is_mandatory( $is_mandatory ) {
		$this->customer_number_is_mandatory = wc_string_to_bool( $is_mandatory );
	}

	/**
	 * @param $customer_number
	 *
	 * @return bool|\WP_Error
	 */
	public function customer_number_is_valid( $customer_number ) {
		$is_valid = $this->customer_number_is_mandatory() ? ! empty( $customer_number ) : true;

		return $is_valid;
	}

	public function get_customer_number_field_label() {
		return _x( 'Customer Number', 'shipments', 'shiptastic-for-woocommerce' );
	}

	public function get_latitude() {
		return $this->latitude;
	}

	public function set_latitude( $latitude ) {
		$this->latitude = $latitude;
	}

	public function get_longitude() {
		return $this->longitude;
	}

	public function set_longitude( $longitude ) {
		$this->longitude = $longitude;
	}

	public function get_formatted_address( $separator = ', ' ) {
		return WC()->countries->get_formatted_address( $this->get_address(), $separator );
	}

	public function get_address_replacement_map() {
		return $this->address_replacement_map;
	}

	public function set_address_replacement_map( $address_map ) {
		$this->address_replacement_map = (array) $address_map;
	}

	public function get_address_replacements() {
		$replacements              = array();
		$location_address          = $this->get_address();
		$location_address['label'] = $this->get_label();
		$location_address['code']  = $this->get_code( 'edit' );

		foreach ( $this->get_address_replacement_map() as $address_key => $location_address_key ) {
			$replacements[ $address_key ] = '';

			if ( isset( $location_address[ $location_address_key ] ) ) {
				$replacements[ $address_key ] = $location_address[ $location_address_key ];
			}
		}

		return $replacements;
	}

	public function replace_address( $target_object ) {
		foreach ( $this->get_address_replacements() as $address_field => $address_value ) {
			$setter = "set_shipping_{$address_field}";

			if ( is_callable( array( $target_object, $setter ) ) ) {
				$target_object->{$setter}( $address_value );
			}
		}
	}

	public function update_meta_data( $meta_key, $meta_value ) {
		$this->meta[ $meta_key ] = $meta_value;
	}

	public function get_meta( $meta_key, $default_value = null ) {
		return array_key_exists( $meta_key, $this->meta ) ? $this->meta[ $meta_key ] : $default_value;
	}

	public function get_data() {
		$data = array(
			'code'                         => $this->get_code(),
			'type'                         => $this->get_type(),
			'label'                        => $this->get_label(),
			'latitude'                     => $this->get_latitude(),
			'longitude'                    => $this->get_longitude(),
			'supports_customer_number'     => $this->supports_customer_number(),
			'customer_number_is_mandatory' => $this->customer_number_is_mandatory(),
			'customer_number_field_label'  => $this->get_customer_number_field_label(),
			'address'                      => $this->get_address(),
			'address_replacement_map'      => $this->get_address_replacement_map(),
			'address_replacements'         => $this->get_address_replacements(),
			'formatted_address'            => $this->get_formatted_address(),
			'shipping_provider_name'       => $this->get_shipping_provider_name(),
			'max_dimensions'               => $this->get_max_dimensions(),
			'max_weight'                   => $this->get_max_weight(),
		);

		foreach ( $this->meta as $key => $value ) {
			$data[ $key ] = $value;
		}

		return $data;
	}
}
