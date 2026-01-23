<?php

namespace Vendidero\Shiptastic\Fulfillments;

use Vendidero\Shiptastic\SimpleShipment;

defined( 'ABSPATH' ) || exit;

class ShippingProvider extends \Automattic\WooCommerce\Internal\Fulfillments\Providers\AbstractShippingProvider {

	/**
	 * @var \Vendidero\Shiptastic\Interfaces\ShippingProvider
	 */
	protected $provider = null;

	/**
	 * @param \Vendidero\Shiptastic\Interfaces\ShippingProvider $provider
	 */
	public function __construct( $provider ) {
		$this->provider = $provider;
	}

	public function get_key(): string {
		return $this->provider->get_name();
	}

	public function get_name(): string {
		return $this->provider->get_title();
	}

	public function get_icon(): string {
		return $this->provider->get_icon();
	}

	public function get_tracking_url( string $tracking_number ): string {
		$shipment = new SimpleShipment();
		$shipment->set_tracking_id( $tracking_number );

		return $this->provider->get_tracking_url( $shipment );
	}

	public function get_shipping_to_countries(): array {
		return $this->provider->get_shipping_to_countries();
	}

	public function get_shipping_from_countries(): array {
		return $this->provider->get_shipping_from_countries();
	}
}
