<?php

namespace Vendidero\Shiptastic\BulkFulfillments;

defined( 'ABSPATH' ) || exit;

/**
 * Stores responses of mutating requests by idempotency key (per user) for a day, so that clients may safely retry
 * requests (e.g. replaying an offline outbox) without applying changes twice.
 */
class Idempotency {

	const TTL = DAY_IN_SECONDS;

	protected static function get_transient_name( $key, $user_id ) {
		return 'wc_stc_idem_' . md5( absint( $user_id ) . '|' . (string) $key );
	}

	/**
	 * @param string $key
	 * @param int $user_id
	 *
	 * @return array|null status, data
	 */
	public static function get( $key, $user_id ) {
		if ( '' === (string) $key ) {
			return null;
		}

		$stored = get_transient( self::get_transient_name( $key, $user_id ) );

		return is_array( $stored ) ? $stored : null;
	}

	/**
	 * @param string $key
	 * @param int $user_id
	 * @param int $status HTTP status
	 * @param mixed $data Response data
	 */
	public static function store( $key, $user_id, $status, $data ) {
		if ( '' === (string) $key ) {
			return;
		}

		set_transient(
			self::get_transient_name( $key, $user_id ),
			array(
				'status' => absint( $status ),
				'data'   => $data,
			),
			self::TTL
		);
	}
}
