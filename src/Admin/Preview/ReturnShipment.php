<?php

namespace Vendidero\Shiptastic\Admin\Preview;

use Vendidero\Shiptastic\ShipmentItem;
use Vendidero\Shiptastic\ShippingProvider\Helper;

defined( 'ABSPATH' ) || exit;

class ReturnShipment extends \Vendidero\Shiptastic\ReturnShipment {

	public function __construct( $data = 0 ) {
		parent::__construct( 0 );

		$this->set_tracking_id( '12345678' );
		$this->set_id( 1234 );
		$this->set_sender_address(
			array(
				'first_name' => _x( 'John', 'shipments-email-preview-name', 'shiptastic-for-woocommerce' ),
				'last_name'  => _x( 'Doe', 'shipments-email-preview-name', 'shiptastic-for-woocommerce' ),
				'address_1'  => _x( '123 Sample Street', 'shipments-email-preview-address', 'shiptastic-for-woocommerce' ),
				'city'       => _x( 'Los Angeles', 'shipments-email-preview-city', 'shiptastic-for-woocommerce' ),
				'postcode'   => _x( '12345', 'shipments-email-preview-postcode', 'shiptastic-for-woocommerce' ),
				'country'    => _x( 'US', 'shipments-email-preview-country', 'shiptastic-for-woocommerce' ),
				'state'      => _x( 'CA', 'shipments-email-preview-state', 'shiptastic-for-woocommerce' ),
				'email'      => _x( 'john@company.com', 'shipments-email-preview-email', 'shiptastic-for-woocommerce' ),
			)
		);

		$item = new ShipmentItem( 0 );
		$item->set_name( _x( 'Sample item', 'shipments-email-preview-item', 'shiptastic-for-woocommerce' ) );
		$item->set_weight( 5 );
		$item->set_quantity( 2 );
		$item->set_height( 10 );
		$item->set_length( 10 );
		$item->set_width( 10 );
		$item->set_total( 15 );

		$this->add_item( $item );

		$available_providers = Helper::instance()->get_available_shipping_providers();

		if ( ! empty( $available_providers ) ) {
			foreach ( $available_providers as $provider ) {
				if ( $provider->supports_customer_returns() ) {
					$this->set_shipping_provider( $provider->get_name() );
					break;
				}
			}
		}
	}
}
