<?php
namespace Vendidero\Shiptastic\Interfaces;

use Vendidero\Shiptastic\API\Response;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

interface RESTAuth {

	public function get_type();

	/**
	 * @return Response|true
	 */
	public function auth();

	/**
	 * @return bool
	 */
	public function has_auth();

	public function get_url();

	public function is_unauthenticated_response( $code );

	/**
	 * @return array
	 */
	public function get_headers();

	public function revoke();
}
