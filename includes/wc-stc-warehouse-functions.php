<?php
/**
 *
 * @package Shiptastic/Functions
 * @version 3.3.0
 */
defined( 'ABSPATH' ) || exit;

/**
 * Get Warehouse.
 *
 * @param mixed $warehouse (default: false) Warehouse id/name to get or empty if new.
 *
 * @return \Vendidero\Shiptastic\Warehouse|bool
 */
function wc_stc_get_warehouse( $warehouse = false ) {
	return \Vendidero\Shiptastic\WarehouseFactory::get_warehouse( $warehouse );
}

function wc_stc_get_warehouse_types() {
	$types = array(
		'self'     => _x( 'Self-operated', 'shipments', 'shiptastic-for-woocommerce' ),
		'external' => _x( 'Externally operated', 'shipments', 'shiptastic-for-woocommerce' ),
	);

	return apply_filters( 'woocommerce_shiptastic_warehouse_types', $types );
}

/**
 * @return \Vendidero\Shiptastic\Warehouse[] $warehouse_list
 */
function wc_stc_get_warehouse_list( $args = array() ) {
	return \Vendidero\Shiptastic\Warehouse\Helper::get_warehouse_list( $args );
}

function wc_stc_get_warehouse_select( $args = array() ) {
	$list   = wc_stc_get_warehouse_list( $args );
	$select = array(
		'' => _x( 'Default', 'shipments-warehouse', 'shiptastic-for-woocommerce' ),
	);

	foreach ( $list as $warehouse ) {
		$select[ $warehouse->get_name() ] = $warehouse->get_title();
	}

	return $select;
}
