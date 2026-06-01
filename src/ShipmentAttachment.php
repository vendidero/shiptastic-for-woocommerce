<?php
/**
 * Regular shipment
 *
 * @package Vendidero/Shiptastic
 * @version 1.0.0
 */
namespace Vendidero\Shiptastic;

use Vendidero\Shiptastic\Interfaces\Attachment;
use WC_Data;
use WC_Data_Store;
use Exception;
use WC_DateTime;
use WP_Error;

defined( 'ABSPATH' ) || exit;

class ShipmentAttachment extends WC_Data implements Attachment {

	/**
	 * This is the name of this object type.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	protected $object_type = 'shipment_attachment';

	/**
	 * Contains a reference to the data store for this class.
	 *
	 * @since 1.0.0
	 * @var object
	 */
	protected $data_store_name = 'shipment-attachment';

	/**
	 * Stores meta in cache for future reads.
	 * A group must be set to to enable caching.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	protected $cache_group = 'shipment-attachments';

	protected $shipment = null;

	/**
	 * Stores document data.
	 *
	 * @var array
	 */
	protected $data = array(
		'type'          => 'other',
		'name'          => '',
		'extension'     => 'pdf',
		'date_created'  => null,
		'shipment_id'   => '',
		'relative_path' => '',
	);

	/**
	 * @param int|object|ShipmentAttachment $attachment Attachment to read.
	 */
	public function __construct( $attachment = 0 ) {
		parent::__construct( $attachment );

		if ( $attachment instanceof ShipmentAttachment ) {
			$this->set_id( absint( $attachment->get_id() ) );
		} elseif ( is_numeric( $attachment ) ) {
			$this->set_id( $attachment );
		}

		$this->data_store = WC_Data_Store::load( $this->data_store_name );

		// If we have an ID, load the user from the DB.
		if ( $this->get_id() ) {
			try {
				$this->data_store->read( $this );
			} catch ( Exception $e ) {
				$this->set_id( 0 );
				$this->set_object_read( true );
			}
		} else {
			$this->set_object_read( true );
		}
	}

	/**
	 * Prefix for action and filter hooks on data.
	 *
	 * @return string
	 */
	protected function get_hook_prefix() {
		return "{$this->get_general_hook_prefix()}get_";
	}

	/**
	 * Prefix for action and filter hooks on data.
	 *
	 * @return string
	 */
	protected function get_general_hook_prefix() {
		return 'shiptastic_shipment_attachment_';
	}

	public function get_type( $context = 'view' ) {
		return $this->get_prop( 'type', $context );
	}

	public function get_title() {
		$title = $this->get_name();

		if ( empty( $title ) ) {
			$title = wc_stc_get_shipment_attachment_type_name( $this->get_type(), $this->get_shipment() ? $this->get_shipment()->get_type() : 'simple' );
		}

		return $title;
	}

	public function get_name( $context = 'view' ) {
		return $this->get_prop( 'name', $context );
	}

	public function get_relative_path( $context = 'view' ) {
		return $this->get_prop( 'relative_path', $context );
	}

	public function get_extension( $context = 'view' ) {
		$extension = $this->get_prop( 'extension', $context );

		if ( 'view' === $context && empty( $extension ) && $this->has_file() ) {
			$filename  = $this->get_filename();
			$extension = pathinfo( $filename, PATHINFO_EXTENSION );
		}

		return $extension;
	}

	public function get_path() {
		$file = $this->get_relative_path();

		if ( empty( $file ) ) {
			return false;
		}

		return Package::get_file_by_path( $file );
	}

	/**
	 * Returns the (real) filename of this attachment.
	 * In case another context is provided, the filename is being regenerated
	 * based on current attachment data (e.g. for direct browser output).
	 *
	 * The real filename might include postfixes e.g. invoice-12-1.pdf to make sure
	 * no files are being overridden.
	 *
	 * @param string $context
	 *
	 * @return string
	 */
	public function get_filename( $context = 'view' ) {
		$path     = $this->get_relative_path();
		$filename = ( ! empty( $path ) ? basename( $path ) : '' );

		if ( empty( $filename ) && 'view' === $context ) {
			$filename = apply_filters( "{$this->get_hook_prefix()}filename", $this->generate_filename(), $this );
		}

		return sanitize_file_name( $filename );
	}

	public function get_download_url( $args = array() ) {
		$base_url     = is_admin() ? admin_url() : trailingslashit( home_url() );
		$download_url = add_query_arg(
			array(
				'action'        => 'wc-stc-download-shipment-attachment',
				'attachment_id' => $this->get_id(),
				'shipment_id'   => $this->get_shipment_id(),
			),
			wp_nonce_url( $base_url, 'download-shipment-attachment' )
		);

		foreach ( $args as $arg => $val ) {
			if ( is_bool( $val ) ) {
				$args[ $arg ] = wc_bool_to_string( $val );
			}
		}

		$download_url = add_query_arg( $args, $download_url );

		return esc_url_raw( apply_filters( "{$this->get_hook_prefix()}download_url", $download_url, $this ) );
	}

	/**
	 * Generates a new filename for the document.
	 *
	 * @return string
	 */
	protected function generate_filename() {
		$file_parts = array(
			wc_stc_get_shipment_attachment_type_name( $this->get_type() ),
		);

		$file_parts[] = $this->get_shipment_id();

		$filename_default = implode( '-', $file_parts );
		$filename_default = $filename_default . '.' . $this->get_extension();

		return sanitize_file_name( $filename_default );
	}

	/**
	 * @param $stream
	 *
	 * @return string|\WP_Error
	 */
	public function upload( $stream ) {
		$path = wc_shiptastic_upload_data( $this->get_filename(), $stream, true, $this->has_file() ? true : false );

		if ( is_wp_error( $path ) ) {
			return $path;
		} else {
			$this->set_relative_path( $path );
			$this->save();

			return $path;
		}
	}

	/**
	 * Import a file, if necessary and set as relative path.
	 *
	 * @param $path
	 *
	 * @return string|\WP_Error
	 */
	public function upload_from_file( $path ) {
		$uploads = Package::get_upload_dir();

		/**
		 * Check whether the file is stored within another path.
		 */
		if ( path_is_absolute( $path ) && 0 !== strncmp( $path, $uploads['basedir'], strlen( $uploads['basedir'] ) ) && file_exists( $path ) ) {
			try {
				$stream = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			} catch ( \Exception $e ) {
				$stream = '';
			}

			if ( ! is_string( $stream ) ) {
				$stream = '';
			}

			return $this->upload( $stream );
		} else {
			$this->set_relative_path( $path );

			return $path;
		}
	}

	public function has_file() {
		$path = $this->get_path();

		if ( ! empty( $path ) && file_exists( $path ) ) {
			return true;
		}

		return false;
	}

	public function get_shipment_id( $context = 'view' ) {
		return $this->get_prop( 'shipment_id', $context );
	}

	/**
	 * Get parent document object.
	 *
	 * @return Shipment|boolean
	 */
	public function get_shipment() {
		if ( is_null( $this->shipment ) && 0 < $this->get_shipment_id() ) {
			$this->shipment = wc_stc_get_shipment( $this->get_shipment_id() );
		}

		return $this->shipment ? $this->shipment : false;
	}

	/**
	 * Return the date this document was created.
	 *
	 * @param  string $context What the value is for. Valid values are 'view' and 'edit'.
	 * @return WC_DateTime|null object if the date is set or null if there is no date.
	 */
	public function get_date_created( $context = 'view' ) {
		return $this->get_prop( 'date_created', $context );
	}

	/**
	 * Set the date this document was created.
	 *
	 * @param  string|integer|null $date UTC timestamp, or ISO 8601 DateTime. If the DateTime string has no timezone or offset, WordPress site timezone will be assumed. Null if their is no date.
	 */
	public function set_date_created( $date = null ) {
		$this->set_date_prop( 'date_created', $date );
	}

	public function set_type( $type ) {
		$this->set_prop( 'type', $type );
	}

	/**
	 * Set the relative path.
	 *
	 * @param string $path The path.
	 */
	public function set_relative_path( $path ) {
		$path = ! empty( $path ) ? Package::get_relative_upload_dir( $path ) : $path;

		$this->set_prop( 'relative_path', $path );
	}

	/**
	 * Set the name.
	 *
	 * @param string $name The name.
	 */
	public function set_name( $name ) {
		$this->set_prop( 'name', $name );
	}

	/**
	 * Set the file extension.
	 *
	 * @param string $extension The extension.
	 */
	public function set_extension( $extension ) {
		$extension = explode( '/', $extension );

		if ( count( $extension ) > 1 ) {
			$extension = $extension[ count( $extension ) - 1 ];
		}

		$this->set_prop( 'extension', $extension );
	}

	/**
	 * Set document id.
	 *
	 * @param int $value document id.
	 */
	public function set_shipment_id( $value ) {
		$this->shipment = null;

		$this->set_prop( 'shipment_id', absint( $value ) );
	}

	/**
	 * @param Shipment $shipment
	 */
	public function set_shipment( $shipment ) {
		$this->set_shipment_id( $shipment->get_id() );

		$this->shipment = $shipment;
	}

	public function get_stream() {
		if ( ! $this->has_file() ) {
			return '';
		}

		try {
			$result = file_get_contents( $this->get_path() ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		} catch ( \Exception $e ) {
			$result = '';
		}

		if ( ! is_string( $result ) ) {
			$result = '';
		}

		return $result;
	}

	public function get_data() {
		$data = array_merge(
			array(
				'id' => $this->get_id(),
			),
			$this->data,
			array(
				'meta_data' => $this->get_meta_data(),
			)
		);

		$data['path'] = $this->has_file() ? $this->get_path() : '';

		return $data;
	}
}
