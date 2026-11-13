<?php
/**
 * Warehouse
 *
 * @package Vendidero/Shiptastic
 * @version 1.0.0
 */
namespace Vendidero\Shiptastic;

use WC_Data;
use WC_Data_Store;
use Exception;
use WC_DateTime;

defined( 'ABSPATH' ) || exit;

/**
 * Warehouse Class.
 */
class Warehouse extends WC_Data {

	/**
	 * This is the name of this object type.
	 *
	 * @var string
	 */
	protected $object_type = 'warehouse';

	/**
	 * Contains a reference to the data store for this class.
	 *
	 * @var object
	 */
	protected $data_store_name = 'warehouse';

	/**
	 * Stores meta in cache for future reads.
	 * A group must be set to to enable caching.
	 *
	 * @var string
	 */
	protected $cache_group = 'warehouse';

	/**
	 * Stores warehouse data.
	 *
	 * @var array
	 */
	protected $data = array(
		'date_created'     => null,
		'order'            => 0,
		'type'             => '',
		'description'      => '',
		'title'            => '',
		'name'             => '',
		'group'            => '',
		'address'          => array(),
		'override_address' => false,
		'location_schema'  => array(),
	);

	/**
	 * Get the warehouse if ID is passed, otherwise the warehouse is new and empty.
	 * This class should NOT be instantiated, but the `wc_stc_get_warehouse` function should be used.
	 *
	 * @param int|object|Warehouse $warehouse warehouse to read.
	 */
	public function __construct( $data = 0 ) {
		parent::__construct( $data );

		$db_data = null;

		if ( $data instanceof Warehouse ) {
			$this->set_id( absint( $data->get_id() ) );
		} elseif ( is_numeric( $data ) ) {
			$this->set_id( $data );
		} elseif ( ! empty( $data->warehouse_id ) ) {
			$this->set_id( absint( $data->warehouse_id ) );

			$db_data = $data;
		}

		$this->data_store = WC_Data_Store::load( $this->data_store_name );

		// If we have an ID, load the user from the DB.
		if ( $this->get_id() ) {
			try {
				$this->data_store->read( $this, $db_data );
			} catch ( Exception $e ) {
				$this->set_id( 0 );
				$this->set_object_read( true );
			}
		} else {
			$this->set_object_read( true );
		}
	}

	/**
	 * This method overwrites the base class's clone method to make it a no-op. In base class WC_Data, we are unsetting the meta_id to clone.
	 *
	 * @see WC_Abstract_Order::__clone()
	 */
	public function __clone() {}

	/**
	 * Merge changes with data and clear.
	 * Overrides WC_Data::apply_changes.
	 *
	 */
	public function apply_changes() {
		if ( function_exists( 'array_replace' ) ) {
			$this->data = array_replace( $this->data, $this->changes ); // phpcs:ignore PHPCompatibility.FunctionUse.NewFunctions.array_replaceFound
		} else { // PHP 5.2 compatibility.
			foreach ( $this->changes as $key => $change ) {
				$this->data[ $key ] = $change;
			}
		}
		$this->changes = array();
	}

	/**
	 * Prefix for action and filter hooks on data.
	 *
	 * @return string
	 */
	protected function get_hook_prefix() {
		return $this->get_general_hook_prefix() . 'get_';
	}

	/**
	 * Prefix for action and filter hooks on data.
	 *
	 * @return string
	 */
	protected function get_general_hook_prefix() {
		return 'woocommerce_shiptastic_warehouse_';
	}

	/**
	 * Return the date this warehouse was created.
	 *
	 * @param  string $context What the value is for. Valid values are 'view' and 'edit'.
	 * @return WC_DateTime|null object if the date is set or null if there is no date.
	 */
	public function get_date_created( $context = 'view' ) {
		return $this->get_prop( 'date_created', $context );
	}

	/**
	 * Returns the warehouse order within its list.
	 *
	 * @param  string $context What the value is for. Valid values are 'view' and 'edit'.
	 * @return string
	 */
	public function get_order( $context = 'view' ) {
		return $this->get_prop( 'order', $context );
	}

	/**
	 * Returns the warehouse type e.g. box or letter.
	 *
	 * @param  string $context What the value is for. Valid values are 'view' and 'edit'.
	 * @return string
	 */
	public function get_type( $context = 'view' ) {
		return $this->get_prop( 'type', $context );
	}

	/**
	 * Returns the warehouse description.
	 *
	 * @param  string $context What the value is for. Valid values are 'view' and 'edit'.
	 * @return string
	 */
	public function get_description( $context = 'view' ) {
		return $this->get_prop( 'description', $context );
	}

	public function get_title( $context = 'view' ) {
		return $this->get_prop( 'title', $context );
	}

	public function get_name( $context = 'view' ) {
		return $this->get_prop( 'name', $context );
	}

	/**
	 * Set the date this warehouse was created.
	 *
	 * @param  string|integer|null $date UTC timestamp, or ISO 8601 DateTime. If the DateTime string has no timezone or offset, WordPress site timezone will be assumed. Null if their is no date.
	 */
	public function set_date_created( $date = null ) {
		$this->set_date_prop( 'date_created', $date );
	}

	/**
	 * Set warehouse order.
	 *
	 * @param integer $order The order.
	 */
	public function set_order( $order ) {
		$this->set_prop( 'order', absint( $order ) );
	}

	/**
	 * Set warehouse type
	 *
	 * @param string $type The type.
	 */
	public function set_type( $type ) {
		$this->set_prop( 'type', $type );
	}

	/**
	 * Set warehouse title
	 *
	 * @param string $tile The title.
	 */
	public function set_title( $title ) {
		$this->set_prop( 'title', $title );
	}

	/**
	 * Set warehouse name
	 *
	 * @param string $name The name.
	 */
	public function set_name( $name ) {
		$this->set_prop( 'name', $name );
	}

	/**
	 * Set warehouse description
	 *
	 * @param string $description The description.
	 */
	public function set_description( $description ) {
		$this->set_prop( 'description', $description );
	}

	/**
	 * Whether this entry is a warehouse group (location) rather than a warehouse.
	 *
	 * @return bool
	 */
	public function is_group() {
		return 'group' === $this->get_type();
	}

	/**
	 * The name of the group (location) the warehouse belongs to. Warehouses within the same group ship together.
	 *
	 * @param string $context
	 *
	 * @return string
	 */
	public function get_group( $context = 'view' ) {
		return $this->get_prop( 'group', $context );
	}

	public function set_group( $group ) {
		$this->set_prop( 'group', wc_clean( (string) $group ) );
	}

	/**
	 * @return Warehouse|null
	 */
	public function get_group_instance() {
		if ( $this->is_group() || '' === $this->get_group() ) {
			return null;
		}

		$group = \Vendidero\Shiptastic\Warehouse\Helper::get_warehouse_by_name( $this->get_group() );

		return ( $group && $group->is_group() ) ? $group : null;
	}

	/**
	 * Returns the location this warehouse ships from: its group or, in case it does not belong
	 * to an (existing) group, the warehouse itself. Location settings (e.g. the sender address)
	 * are read from the location.
	 *
	 * @return Warehouse
	 */
	public function get_location() {
		$group = $this->get_group_instance();

		return $group ? $group : $this;
	}

	/**
	 * Returns the raw warehouse address.
	 *
	 * @param string $context
	 *
	 * @return array
	 */
	public function get_address( $context = 'view' ) {
		return (array) $this->get_prop( 'address', $context );
	}

	public function set_address( $address ) {
		$address = array_filter( array_map( 'wc_clean', (array) $address ) );

		$this->set_prop( 'address', array_intersect_key( $address, array_flip( self::get_address_props() ) ) );
	}

	public function get_address_prop( $prop, $context = 'view' ) {
		$address = $this->get_address( $context );

		return array_key_exists( $prop, $address ) ? $address[ $prop ] : '';
	}

	public static function get_address_props() {
		return array( 'company', 'first_name', 'last_name', 'address_1', 'address_2', 'postcode', 'city', 'country', 'phone', 'email' );
	}

	/**
	 * Raw storage location schema data, see `Warehouse\LocationSchema`. Only warehouses (not groups) have a schema.
	 *
	 * @param string $context
	 *
	 * @return array
	 */
	public function get_location_schema( $context = 'view' ) {
		return (array) $this->get_prop( 'location_schema', $context );
	}

	public function set_location_schema( $schema ) {
		$this->set_prop( 'location_schema', \Vendidero\Shiptastic\Warehouse\LocationSchema::sanitize( $schema ) );
	}

	/**
	 * @return \Vendidero\Shiptastic\Warehouse\LocationSchema
	 */
	public function get_location_schema_instance() {
		return new \Vendidero\Shiptastic\Warehouse\LocationSchema( $this->is_group() ? array() : $this->get_location_schema() );
	}

	/**
	 * Converts a storage location code within this warehouse into a sortable pick sequence.
	 *
	 * @param string $code
	 *
	 * @return string
	 */
	public function get_pick_sequence( $code ) {
		return $this->get_location_schema_instance()->get_pick_sequence( $code );
	}

	/**
	 * @param string $code
	 *
	 * @return true|\WP_Error
	 */
	public function validate_location_code( $code ) {
		return $this->get_location_schema_instance()->validate( $code );
	}

	/**
	 * Whether the group overrides the (shipper) address. Addresses may only be overridden for groups.
	 *
	 * @param string $context
	 *
	 * @return bool
	 */
	public function get_override_address( $context = 'view' ) {
		return $this->get_prop( 'override_address', $context );
	}

	public function set_override_address( $override ) {
		$this->set_prop( 'override_address', wc_string_to_bool( $override ) );
	}

	/**
	 * Whether a custom address is used for this location. Only groups may override the address,
	 * warehouses (grouped or not) and the default location use the shipper address.
	 *
	 * @return bool
	 */
	public function has_address() {
		return $this->is_group() && true === $this->get_override_address() && '' !== $this->get_address_prop( 'address_1' ) && '' !== $this->get_address_prop( 'country' );
	}

	/**
	 * Returns the formatted sender address used for shipments from this location,
	 * in the same format as `wc_stc_get_shipment_setting_address_fields()`.
	 * Falls back to the shipper address in case the location does not override the address.
	 *
	 * @return array
	 */
	public function get_sender_address() {
		$shipper = wc_stc_get_shipment_setting_address_fields( 'shipper' );

		if ( ! $this->has_address() ) {
			return $shipper;
		}

		$address = array_merge(
			$shipper,
			array_fill_keys( self::get_address_props(), '' ),
			$this->get_address()
		);

		$country_state      = wc_format_country_state_string( $address['country'] );
		$address['country'] = $country_state['country'];
		$address['state']   = $country_state['state'];

		$split                    = wc_stc_split_shipment_street( $address['address_1'] );
		$address['street']        = $split['street'];
		$address['street_number'] = $split['number'];
		$address['full_name']     = trim( sprintf( _x( '%1$s %2$s', 'full name', 'shiptastic-for-woocommerce' ), $address['first_name'], $address['last_name'] ) );

		/**
		 * Filter to adjust the sender address of a warehouse.
		 *
		 * @param array     $address The address.
		 * @param Warehouse $warehouse The warehouse.
		 *
		 * @package Vendidero/Shiptastic
		 */
		return apply_filters( "{$this->get_general_hook_prefix()}sender_address", $address, $this );
	}
}
