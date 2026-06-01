<?php

namespace Vendidero\Shiptastic\Admin;

use Exception;
use Vendidero\Shiptastic\Package;
use Vendidero\Shiptastic\PDFMerger;

defined( 'ABSPATH' ) || exit;

/**
 * Shipment Order
 *
 * @version     1.0.0
 * @author      Vendidero
 */
abstract class BulkDownload extends BulkActionHandler {

	protected $file_type = '';

	abstract protected function get_download_name( $plural = false );

	public function get_title() {
		return sprintf( _x( 'Generating %1$s...', 'shipments', 'shiptastic-for-woocommerce' ), $this->get_download_name( true ) );
	}

	protected function get_file_type() {
		return $this->file_type;
	}

	protected function get_export_filename() {
		$filename = sanitize_file_name( "export-{$this->get_action()}." . ( 'pdf' === $this->get_file_type() ? 'pdf' : 'zip' ) );

		return $filename;
	}

	public function get_file() {
		$file = get_user_meta( get_current_user_id(), $this->get_file_option_name(), true );

		if ( $file ) {
			$uploads = Package::get_upload_dir();
			$path    = trailingslashit( $uploads['basedir'] ) . $file;

			return $path;
		}

		return '';
	}

	/**
	 * Get file path to export to.
	 *
	 * @return string
	 */
	protected function get_export_file_path() {
		$upload_dir = Package::get_upload_dir();

		return trailingslashit( $upload_dir['basedir'] ) . $this->get_export_filename();
	}

	protected function update_file( $path ) {
		update_user_meta( get_current_user_id(), $this->get_file_option_name(), $path );
	}

	protected function get_file_option_name() {
		$action = sanitize_key( $this->get_action() );

		return "woocommerce_shiptastic_{$action}_bulk_path";
	}

	public function get_filename() {
		if ( $file = $this->get_file() ) {
			return basename( $file );
		}

		return '';
	}

	public function reset( $is_new = false ) {
		parent::reset( $is_new );

		if ( $is_new ) {
			delete_user_meta( get_current_user_id(), $this->get_file_option_name() );
			delete_user_meta( get_current_user_id(), $this->get_files_option_name() );
		}
	}

	protected function get_download_button() {
		$download_button = '';

		if ( ( $path = $this->get_file() ) && file_exists( $path ) ) {
			$download_url = add_query_arg(
				array(
					'action'        => 'wc-stc-download-export',
					'shipment_type' => $this->get_file_type(),
					'handler'       => $this->get_action(),
					'force'         => 'no',
				),
				wp_nonce_url( admin_url(), 'download-export' )
			);

			$download_button = '<a class="button button-primary bulk-download-button" style="margin-left: 1em;" href="' . esc_url( $download_url ) . '" target="_blank">' . sprintf( esc_html_x( 'Download %1$s', 'shipments', 'shiptastic-for-woocommerce' ), esc_html( $this->get_download_name( true ) ) ) . '</a>';
		}

		return $download_button;
	}

	public function get_success_message() {
		$download_button = $this->get_download_button();

		if ( empty( $download_button ) ) {
			return sprintf( _x( 'There were no %1$s available for the shipments chosen.', 'shipments', 'shiptastic-for-woocommerce' ), esc_html( $this->get_download_name( true ) ) );
		} else {
			return sprintf( _x( 'Successfully generated %1$s. %2$s', 'shipments', 'shiptastic-for-woocommerce' ), esc_html( $this->get_download_name( true ) ), $download_button );
		}
	}

	public function admin_after_error() {
		$download_button = $this->get_download_button();

		if ( ! empty( $download_button ) ) {
			echo '<div class="notice"><p>' . sprintf( esc_html_x( '%1$s partially generated. %2$s', 'shipments', 'shiptastic-for-woocommerce' ), esc_html( $this->get_download_name( true ) ), $download_button ) . '</p></div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
	}

	protected function get_files_option_name() {
		$action = sanitize_key( $this->get_action() );

		return "woocommerce_shiptastic_{$action}_bulk_files";
	}

	protected function get_files() {
		$files = get_user_meta( get_current_user_id(), $this->get_files_option_name(), true );

		if ( empty( $files ) || ! is_array( $files ) ) {
			$files = array();
		}

		return $files;
	}

	protected function create_archive() {
		if ( ! class_exists( 'ZipArchive' ) ) {
			$this->add_notice( _x( 'Please make sure to install the PHP zip package. Ask your webhoster for further information.', 'shipments', 'shiptastic-for-woocommerce' ) );

			return false;
		}

		$file = new \ZipArchive();

		if ( @file_exists( $this->get_export_file_path() ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			$result = $file->open( $this->get_export_file_path() );
		} else {
			$result = $file->open( $this->get_export_file_path(), \ZipArchive::CREATE );
			/**
			 * Add an empty placeholder file to the zip to prevent missing zip files
			 * for exports which do not contain any documents. $zip->close() will delete
			 * zip files automatically in case no file is included.
			 */
			$file->addFromString( '.', '' );
		}

		if ( true !== $result ) {
			$this->add_notice( _x( 'Error while reading or creating ZIP file.', 'shipments', 'shiptastic-for-woocommerce' ) );

			return false;
		}

		return $file;
	}

	protected function create_bulk() {
		$files = $this->get_files();

		try {
			if ( '' === $this->get_file_type() ) {
				$this->file_type = 'pdf';

				if ( ! empty( $files ) ) {
					foreach ( $files as $file ) {
						$mime_type_info = wp_check_filetype(
							basename( $file ),
							array(
								'pdf' => 'application/pdf',
							)
						);

						if ( 'pdf' !== $mime_type_info['ext'] ) {
							$this->file_type = 'zip';
						}

						break;
					}
				}
			}

			if ( 'pdf' === $this->get_file_type() ) {
				$pdf = new PDFMerger();

				if ( ! empty( $files ) ) {
					foreach ( $files as $file ) {
						if ( ! file_exists( $file ) ) {
							continue;
						}

						$pdf->add( $file );
					}

					$filename = $this->get_export_filename();
					$file     = $pdf->output( $filename, 'S' );
					$path     = wc_shiptastic_upload_data( $filename, $file );

					if ( ! is_wp_error( $path ) ) {
						$this->update_file( $path );
					}
				}
			} elseif ( $zip = $this->create_archive() ) {
				foreach ( $files as $file ) {
					$zip->addFile( $file, basename( $file ) );
				}

					$zip->close();
					$this->update_file( $this->get_export_filename() );
			}
		} catch ( Exception $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
		}
	}

	protected function add_file( $path ) {
		$files = $this->get_files();

		if ( ! in_array( $path, $files, true ) ) {
			$files[] = $path;

			update_user_meta( get_current_user_id(), $this->get_files_option_name(), $files );
		}
	}
}
