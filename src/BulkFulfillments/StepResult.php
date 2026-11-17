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

	protected $files = array();

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

	/**
	 * Adds a file created by the step (e.g. a label PDF) to the export of the automation run executing the step
	 * (see RunExport). The file is copied, the original is kept.
	 *
	 * @param string $path Absolute path of a readable file.
	 * @param string $name File name within the export, defaults to the file's name.
	 *
	 * @return $this
	 */
	public function add_file( $path, $name = '' ) {
		$this->files[] = array(
			'path'    => (string) $path,
			'name'    => '' !== $name ? (string) $name : wp_basename( (string) $path ),
			'content' => null,
		);

		return $this;
	}

	/**
	 * Adds generated content (e.g. a CSV) as a file to the export of the automation run executing the step.
	 *
	 * @param string $name File name within the export.
	 * @param string $content
	 *
	 * @return $this
	 */
	public function add_file_content( $name, $content ) {
		$this->files[] = array(
			'path'    => '',
			'name'    => (string) $name,
			'content' => (string) $content,
		);

		return $this;
	}

	/**
	 * @return array[] path, name, content (null in case a path is used)
	 */
	public function get_files() {
		return $this->files;
	}
}
