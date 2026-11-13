<?php
/**
 * Pick step: pick list sorted along the walking path.
 */
defined( 'ABSPATH' ) || exit;

echo \Vendidero\Shiptastic\BulkFulfillments\App::render_view( 'partials/check-list', array( 'all_label' => _x( 'Pick all', 'shipments', 'shiptastic-for-woocommerce' ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
