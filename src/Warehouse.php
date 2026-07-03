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
		'date_created' => null,
		'order'        => 0,
		'type'         => '',
		'description'  => '',
		'title'        => '',
		'name'         => '',
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
}
