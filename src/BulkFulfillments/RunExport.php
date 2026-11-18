<?php

namespace Vendidero\Shiptastic\BulkFulfillments;

use Vendidero\Shiptastic\Package;

defined( 'ABSPATH' ) || exit;

/**
 * Export (zip) of an automation run: files created by its steps (e.g. labels, documents) are accumulated while the run
 * processes orders and may be downloaded during the run or once it finished.
 *
 * - Actions add files to their result: StepResult::add_file( $path ) / add_file_content( $name, $content ).
 * - Each step has its own folder within the zip, e.g. "02-create-label/1234-5678-label.pdf"
 *   (step position + title / order number + shipment id + file name).
 * - The zip lives in a protected folder of the Shiptastic upload directory (random file name), the download is
 *   served via admin-post.php for the user who started the run and shop managers.
 * - Exports of runs finished a while ago are removed daily (filter woocommerce_shiptastic_fulfillment_export_retention_days).
 */
class RunExport {

	const DOWNLOAD_ACTION = 'stc_fulfillment_run_export';

	const CLEANUP_HOOK = 'woocommerce_shiptastic_fulfillment_cleanup_exports';

	public static function init() {
		add_action( 'admin_post_' . self::DOWNLOAD_ACTION, array( __CLASS__, 'download' ) );
		add_action( self::CLEANUP_HOOK, array( __CLASS__, 'cleanup' ) );
		add_action( 'init', array( __CLASS__, 'schedule_cleanup' ), 20 );
	}

	/**
	 * @return bool Whether zip files can be created (ZipArchive).
	 */
	public static function is_available() {
		return class_exists( 'ZipArchive' );
	}

	public static function schedule_cleanup() {
		if ( ! function_exists( 'as_has_scheduled_action' ) || ! function_exists( 'as_schedule_recurring_action' ) ) {
			return;
		}

		if ( ! as_has_scheduled_action( self::CLEANUP_HOOK ) ) {
			as_schedule_recurring_action( time() + HOUR_IN_SECONDS, DAY_IN_SECONDS, self::CLEANUP_HOOK, array(), 'woocommerce-shiptastic' );
		}
	}

	/**
	 * @return string The (protected) export directory, created on demand. Empty in case it is not available.
	 */
	public static function get_dir() {
		$uploads = Package::get_upload_dir();

		if ( ! empty( $uploads['error'] ) ) {
			return '';
		}

		$dir = trailingslashit( $uploads['basedir'] ) . 'fulfillment-exports';

		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			return '';
		}

		// The upload directory is protected already, protect the folder itself as well (e.g. moved upload dir).
		foreach ( array(
			'.htaccess' => 'deny from all',
			'index.php' => '<?php // Silence is golden.',
		) as $file => $content ) {
			if ( ! file_exists( "{$dir}/{$file}" ) ) {
				@file_put_contents( "{$dir}/{$file}", $content ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			}
		}

		return $dir;
	}

	/**
	 * @param Run $run
	 *
	 * @return string Absolute path of the run's zip file, empty in case there is none (yet).
	 */
	public static function get_path( $run ) {
		if ( ! $run || '' === (string) $run->export ) {
			return '';
		}

		$dir = self::get_dir();

		return $dir ? $dir . '/' . wp_basename( $run->export ) : '';
	}

	/**
	 * @param Run $run
	 *
	 * @return bool
	 */
	public static function has_export( $run ) {
		$path = self::get_path( $run );

		return $run->export_files > 0 && $path && file_exists( $path );
	}

	/**
	 * Adds the files of a step result to the run's export.
	 *
	 * @param Run $run
	 * @param QueueItem|null $item Null for steps of run level phases (start of batch, end of run).
	 * @param Step $step
	 * @param StepResult $result
	 * @param string|null $prefix File name prefix, defaults to the order number (+ shipment id).
	 *
	 * @return Run
	 */
	public static function add_files( $run, $item, $step, $result, $prefix = null ) {
		$files = is_a( $result, StepResult::class ) ? $result->get_files() : array();

		if ( empty( $files ) ) {
			return $run;
		}

		if ( ! self::is_available() ) {
			Package::log( sprintf( 'Fulfillment run #%d: files cannot be exported, ZipArchive is not available.', $run->get_id() ), 'error', 'fulfillment' );

			return $run;
		}

		if ( '' === (string) $run->export ) {
			$run = Run::update( $run->get_id(), array( 'export' => 'run-' . $run->get_id() . '-' . wp_generate_password( 20, false, false ) . '.zip' ) );
		}

		$path = self::get_path( $run );

		if ( ! $path ) {
			return $run;
		}

		$zip = new \ZipArchive();

		if ( true !== $zip->open( $path, \ZipArchive::CREATE ) ) {
			Package::log( sprintf( 'Fulfillment run #%d: the export file could not be opened.', $run->get_id() ), 'error', 'fulfillment' );

			return $run;
		}

		$folder = self::get_step_folder( $run, $step );
		$prefix = is_null( $prefix ) ? ( $item ? self::get_file_prefix( $item, $step ) : 'run' ) : (string) $prefix;

		foreach ( $files as $file ) {
			$name  = sanitize_file_name( $prefix . '-' . $file['name'] );
			$entry = self::get_unique_entry( $zip, $folder . '/' . $name );

			if ( null !== $file['content'] ) {
				$zip->addFromString( $entry, $file['content'] );
			} elseif ( $file['path'] && is_readable( $file['path'] ) && is_file( $file['path'] ) ) {
				$zip->addFile( $file['path'], $entry );
			} else {
				Package::log( sprintf( 'Fulfillment run #%1$d: the file %2$s does not exist.', $run->get_id(), $file['path'] ), 'error', 'fulfillment' );
			}
		}

		$count = $zip->numFiles; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		$zip->close();

		return Run::update( $run->get_id(), array( 'export_files' => (int) $count ) );
	}

	/**
	 * @param Run $run
	 * @param Step $step
	 *
	 * @return string E.g. 02-create-label: position within the workflow (00 for injected dependencies) + title.
	 */
	protected static function get_step_folder( $run, $step ) {
		$workflow = $run->get_workflow();
		$position = $workflow ? array_search( $step->action, $workflow->get_step_actions(), true ) : false;
		$action   = $step->get_action();
		$title    = $action ? $action::get_title() : $step->action;

		return sprintf( '%02d-%s', false === $position ? 0 : $position + 1, sanitize_title( $title ) );
	}

	/**
	 * @param QueueItem $item
	 * @param Step $step
	 *
	 * @return string Order number (+ shipment id for shipment scope steps).
	 */
	protected static function get_file_prefix( $item, $step ) {
		$order  = wc_get_order( $item->get_order_id() );
		$prefix = $order ? $order->get_order_number() : $item->get_order_id();

		if ( $step->shipment_id ) {
			$prefix .= '-' . $step->shipment_id;
		}

		return (string) $prefix;
	}

	/**
	 * @param \ZipArchive $zip
	 * @param string $entry
	 *
	 * @return string The entry name, suffixed in case it exists already (e.g. a step re-run after fixing an error).
	 */
	protected static function get_unique_entry( $zip, $entry ) {
		if ( false === $zip->locateName( $entry ) ) {
			return $entry;
		}

		$info = pathinfo( $entry );
		$i    = 2;

		do {
			$candidate = $info['dirname'] . '/' . $info['filename'] . '-' . $i . ( isset( $info['extension'] ) ? '.' . $info['extension'] : '' );
			++$i;
		} while ( false !== $zip->locateName( $candidate ) );

		return $candidate;
	}

	/**
	 * @param Run $run
	 *
	 * @return bool The user who started the run and shop managers may download the export.
	 */
	public static function can_download( $run ) {
		return $run && ( get_current_user_id() === $run->user_id || current_user_can( 'manage_woocommerce' ) );
	}

	/**
	 * @param Run $run
	 *
	 * @return string Download URL (nonce protected), empty in case there is nothing to download.
	 */
	public static function get_download_url( $run ) {
		if ( ! self::has_export( $run ) || ! self::can_download( $run ) ) {
			return '';
		}

		return add_query_arg(
			array(
				'action'   => self::DOWNLOAD_ACTION,
				'run'      => $run->get_id(),
				'_wpnonce' => wp_create_nonce( self::DOWNLOAD_ACTION . '-' . $run->get_id() ),
			),
			admin_url( 'admin-post.php' )
		);
	}

	/**
	 * @param Run $run
	 *
	 * @return string E.g. pick-pack-run-12.zip
	 */
	public static function get_download_name( $run ) {
		$workflow = $run->get_workflow();

		return sanitize_file_name( ( $workflow ? sanitize_title( $workflow->get_title() ) . '-' : '' ) . 'run-' . $run->get_id() . '.zip' );
	}

	/**
	 * @param Run $run
	 *
	 * @return string[] Entries (folder/file name) of the run's export.
	 */
	public static function get_entries( $run ) {
		$path    = self::has_export( $run ) ? self::get_path( $run ) : '';
		$entries = array();

		if ( ! $path || ! self::is_available() ) {
			return $entries;
		}

		$zip = new \ZipArchive();

		if ( true !== $zip->open( $path ) ) {
			return $entries;
		}

		for ( $i = 0; $i < $zip->numFiles; $i++ ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			$name = $zip->getNameIndex( $i );

			if ( $name && '/' !== substr( $name, -1 ) ) {
				$entries[] = $name;
			}
		}

		$zip->close();

		return $entries;
	}

	/**
	 * @param Run $run
	 * @param string $entry
	 *
	 * @return string Download URL of a single file of the run's export (nonce protected).
	 */
	public static function get_file_url( $run, $entry ) {
		if ( ! self::can_download( $run ) ) {
			return '';
		}

		return add_query_arg(
			array(
				'action'   => self::DOWNLOAD_ACTION,
				'run'      => $run->get_id(),
				'file'     => rawurlencode( $entry ),
				'_wpnonce' => wp_create_nonce( self::DOWNLOAD_ACTION . '-' . $run->get_id() ),
			),
			admin_url( 'admin-post.php' )
		);
	}

	/**
	 * Groups the export's files per order (file names are prefixed with the order number, see get_file_prefix()).
	 *
	 * @param Run $run
	 * @param string[] $order_numbers
	 *
	 * @return array order number => list of name (file name), folder (step), url
	 */
	public static function get_files_by_order( $run, $order_numbers ) {
		$files = array_fill_keys( array_map( 'strval', $order_numbers ), array() );

		foreach ( self::get_entries( $run ) as $entry ) {
			$folder = dirname( $entry );
			$name   = basename( $entry );

			foreach ( array_keys( $files ) as $order_number ) {
				$prefix = sanitize_file_name( $order_number . '-' );

				if ( 0 === strpos( $name, $prefix ) ) {
					$files[ $order_number ][] = array(
						'name'   => $name,
						'folder' => '.' === $folder ? '' : $folder,
						'url'    => self::get_file_url( $run, $entry ),
					);
					break;
				}
			}
		}

		return $files;
	}

	public static function download() {
		$run_id = isset( $_GET['run'] ) ? absint( wp_unslash( $_GET['run'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( ! $run_id || ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_GET['_wpnonce'] ) ), self::DOWNLOAD_ACTION . '-' . $run_id ) ) {
			wp_die( esc_html_x( 'The link has expired, please try again.', 'shipments', 'shiptastic-for-woocommerce' ), 403 );
		}

		$run = Run::get( $run_id );

		if ( ! $run || ! self::can_download( $run ) ) {
			wp_die( esc_html_x( 'Sorry, you are not allowed to download this export.', 'shipments', 'shiptastic-for-woocommerce' ), 403 );
		}

		if ( ! self::has_export( $run ) ) {
			wp_die( esc_html_x( 'There are no files to download (anymore).', 'shipments', 'shiptastic-for-woocommerce' ), 404 );
		}

		$file = isset( $_GET['file'] ) ? rawurldecode( wp_unslash( $_GET['file'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

		// A single file of the export (e.g. the label of an order).
		if ( '' !== $file ) {
			self::send_entry( $run, $file );
		}

		self::send( self::get_path( $run ), self::get_download_name( $run ) );
	}

	/**
	 * @param Run $run
	 * @param string $entry
	 */
	protected static function send_entry( $run, $entry ) {
		if ( ! in_array( $entry, self::get_entries( $run ), true ) ) {
			wp_die( esc_html_x( 'The file does not exist (anymore).', 'shipments', 'shiptastic-for-woocommerce' ), 404 );
		}

		$zip = new \ZipArchive();

		if ( true !== $zip->open( self::get_path( $run ) ) ) {
			wp_die( esc_html_x( 'The export could not be opened.', 'shipments', 'shiptastic-for-woocommerce' ), 500 );
		}

		$content = $zip->getFromName( $entry );
		$zip->close();

		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}

		$type = wp_check_filetype( basename( $entry ) );

		nocache_headers();
		header( 'Content-Type: ' . ( $type['type'] ? $type['type'] : 'application/octet-stream' ) );
		header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( basename( $entry ) ) . '"' );
		header( 'Content-Length: ' . strlen( (string) $content ) );
		header( 'X-Content-Type-Options: nosniff' );

		echo $content; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		exit;
	}

	/**
	 * @param string $path
	 * @param string $name
	 */
	protected static function send( $path, $name ) {
		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}

		nocache_headers();
		header( 'Content-Type: application/zip' );
		header( 'Content-Disposition: attachment; filename="' . $name . '"' );
		header( 'Content-Length: ' . filesize( $path ) );
		header( 'X-Content-Type-Options: nosniff' );

		readfile( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
		exit;
	}

	/**
	 * Deletes the export of a run (e.g. cleanup).
	 *
	 * @param Run $run
	 *
	 * @return Run
	 */
	public static function delete( $run ) {
		$path = self::get_path( $run );

		if ( $path && file_exists( $path ) ) {
			wp_delete_file( $path );
		}

		// The number of files is kept: the run history tells that files existed but were removed (retention).
		return Run::update(
			$run->get_id(),
			array(
				'export' => '',
			)
		);
	}

	/**
	 * Removes the exports of runs which finished more than the retention period ago.
	 */
	public static function cleanup() {
		global $wpdb;

		/**
		 * Filter to adjust the number of days the export (zip) of a finished fulfillment automation run is kept.
		 *
		 * @param int $days
		 *
		 * @package Vendidero/Shiptastic
		 */
		$days   = max( 1, absint( apply_filters( 'woocommerce_shiptastic_fulfillment_export_retention_days', 7 ) ) );
		$before = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );

		$run_ids = $wpdb->get_col( $wpdb->prepare( "SELECT run_id FROM {$wpdb->stc_fulfillment_runs} WHERE run_export != '' AND run_date_finished_gmt IS NOT NULL AND run_date_finished_gmt < %s LIMIT 500", $before ) );

		foreach ( $run_ids as $run_id ) {
			if ( $run = Run::get( $run_id ) ) {
				self::delete( $run );
			}
		}
	}
}
