<?php
namespace Vendidero\Shiptastic\Interfaces;

use Vendidero\Shiptastic\API\Response;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

interface RESTAuth extends ApiAuth {

	/**
	 * @return Response|true
	 */
	public function auth();

	/**
	 * @return bool
	 */
	public function has_auth();

	public function is_connected();

	public function is_unauthenticated_response( $code );
	/**
	 * @return array
	 */
	public function get_headers();

	public function revoke();

	public function get_url();
}
