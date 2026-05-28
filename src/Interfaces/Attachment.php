<?php

namespace Vendidero\Shiptastic\Interfaces;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

interface Attachment {

	public function get_id();

	public function get_type( $context = 'view' );

	public function get_title();

	public function get_path();

	public function get_download_url( $args = array() );

	public function get_shipment();

	public function has_file();

	public function delete( $force_delete = false );

	public function save();
}
