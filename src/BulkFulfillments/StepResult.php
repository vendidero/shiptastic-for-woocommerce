<?php

namespace Vendidero\Shiptastic\BulkFulfillments;

defined( 'ABSPATH' ) || exit;

/**
 * Result of executing a step.
 */
class StepResult {

	protected $status = 'done';

	protected $message = '';

	protected $code = '';

	protected $data = array();

	/**
	 * @param string $status done|error|skipped
	 * @param string $message
	 * @param array $data Stored within the step log (e.g. picked quantities).
	 * @param string $code Error code, e.g. short_pick.
	 */
	public function __construct( $status = 'done', $message = '', $data = array(), $code = '' ) {
		$this->status  = in_array( $status, array( 'done', 'error', 'skipped' ), true ) ? $status : 'error';
		$this->message = (string) $message;
		$this->data    = (array) $data;
		$this->code    = (string) $code;
	}

	public static function done( $data = array() ) {
		return new self( 'done', '', $data );
	}

	public static function skipped( $message = '', $data = array() ) {
		return new self( 'skipped', $message, $data );
	}

	public static function error( $message, $code = 'error', $data = array() ) {
		return new self( 'error', $message, $data, $code );
	}

	/**
	 * @param \WP_Error $error
	 *
	 * @return StepResult
	 */
	public static function from_wp_error( $error ) {
		return self::error( implode( ' ', $error->get_error_messages() ), $error->get_error_code() );
	}

	public function get_status() {
		return $this->status;
	}

	public function is_error() {
		return 'error' === $this->status;
	}

	public function get_message() {
		return $this->message;
	}

	public function get_code() {
		return $this->code;
	}

	public function get_data() {
		return $this->data;
	}
}
