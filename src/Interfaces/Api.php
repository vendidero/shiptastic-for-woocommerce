<?php
namespace Vendidero\Shiptastic\Interfaces;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

interface Api {

	public function is_sandbox();

	public function set_is_sandbox( $is_sandbox );

	public function get_name();

	public function get_title();

	public function get_setting_name();

	/**
	 * @return false|ApiAuth
	 */
	public function get_auth_api();

	/**
	 * @param $auth null|ApiAuth
	 */
	public function set_auth_api( $auth );

	public function get_url();
}
