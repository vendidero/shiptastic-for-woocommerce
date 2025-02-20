<?php

namespace Vendidero\Shiptastic\API\Auth;

use Vendidero\Shiptastic\API\REST;
use Vendidero\Shiptastic\Interfaces\RESTAuth;

abstract class Auth implements RESTAuth {

	/**
	 * @var REST
	 */
	protected $api = null;

	/**
	 * @param $api REST
	 */
	public function __construct( $api ) {
		$this->api = $api;
	}

	/**
	 * @return REST
	 */
	public function get_api() {
		return $this->api;
	}

	public function is_connected() {
		return $this->has_auth();
	}

	public function is_unauthenticated_response( $code ) {
		return in_array( (int) $code, array( 401, 403 ), true );
	}

	protected function get_request_url( $endpoint = '', $query_args = array() ) {
		if ( ! strstr( $endpoint, 'http://' ) && ! strstr( $endpoint, 'https://' ) ) {
			$endpoint = trailingslashit( $this->get_url() ) . $endpoint;
		}

		return add_query_arg( $query_args, $endpoint );
	}
}
