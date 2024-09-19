<?php

namespace Vendidero\Shiptastic\Interfaces;

/**
 * Compatibility
 *
 * @version  1.0.0
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

interface Compatibility {

	public static function is_active();

	public static function init();
}
