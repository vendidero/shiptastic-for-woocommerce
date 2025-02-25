<?php
namespace Vendidero\Shiptastic\Interfaces;

use Vendidero\Shiptastic\API\Response;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

interface ApiAuth {

	public function get_type();

	/**
	 * @return Api
	 */
	public function get_api();
}
