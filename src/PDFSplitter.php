<?php
namespace Vendidero\Shiptastic;

use Exception;
use setasign\Fpdi\Fpdi;
use setasign\Fpdi\PdfParser\PdfParserException;
use setasign\Fpdi\PdfParser\StreamReader;
use setasign\Fpdi\PdfReader\PdfReaderException;

class PDFSplitter {

	/**
	 * Fpdi pdf instance
	 *
	 * @var null|Fpdi
	 */
	protected $_pdf = null;

	protected $pagecount = 0;

	protected $file = '';

	protected $filename = '';

	/**
	 * Pdf constructor
	 *
	 */
	public function __construct( $file, $stream = false, $filename = '' ) {
		$this->_pdf = new Fpdi();

		try {

			if ( $stream ) {
				$file           = StreamReader::createByString( $file );
				$this->filename = $filename;
			} else {
				$this->filename = basename( $this->file );
			}

			$this->file      = $file;
			$this->pagecount = $this->_pdf->setSourceFile( $file ); // How many pages?

		} catch ( PdfParserException $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
		} catch ( Exception $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
		}
	}

	public function get_page_count() {
		return $this->pagecount;
	}

	public function split() {
		$new_files = array();

		try {
			// Split each page into a new PDF
			for ( $i = 1; $i <= $this->pagecount; $i++ ) {

				$new_pdf = new Fpdi();
				$new_pdf->AddPage();
				$new_pdf->setSourceFile( $this->file );
				$new_pdf->useTemplate( $new_pdf->importPage( $i ), 0, 0, 210, null, true );

				$new_files[] = $new_pdf->Output( 'S', $this->filename );
			}
		} catch ( PdfParserException $e ) {
			return false;
		}

		return $new_files;
	}

	/**
	 * Add file to this pdf
	 *
	 * @param string $filename Filename of the source file
	 * @param mixed $pages Range of files (if not set, all pages where imported)
	 */
	public function add( $filename, $pages = array() ) {
		if ( file_exists( $filename ) ) {
			$page_count = $this->_pdf->setSourceFile( $filename );
			for ( $i = 1; $i <= $page_count; $i++ ) {
				if ( $this->_isPageInRange( $i, $pages ) ) {
					$this->_addPage( $i );
				}
			}
		}
		return $this;
	}

	/**
	 * Output merged pdf
	 *
	 * @param string $type
	 */
	public function output( $filename, $type = 'I' ) {
		return $this->_pdf->Output( $type, $filename );
	}

	/**
	 * Force download merged pdf as file
	 *
	 * @param $filename
	 * @return string
	 */
	public function download( $filename ) {
		return $this->output( $filename, 'D' );
	}

	/**
	 * Save merged pdf
	 *
	 * @param $filename
	 * @return string
	 */
	public function save( $filename ) {
		return $this->output( $filename, 'F' );
	}

	/**
	 * Add single page
	 *
	 * @param $page_number
	 * @throws PdfReaderException
	 */
	private function _addPage( $page_number ) { // phpcs:ignore PSR2.Methods.MethodDeclaration.Underscore,WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
		$page_id = $this->_pdf->importPage( $page_number );
		$this->_pdf->addPage();
		$this->_pdf->useImportedPage( $page_id );
	}


	/**
	 * Check if a specific page should be merged.
	 * If pages are empty, all pages will be merged
	 *
	 * @return bool
	 */
	private function _isPageInRange( $page_number, $pages = array() ) { // phpcs:ignore PSR2.Methods.MethodDeclaration.Underscore,WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
		if ( empty( $pages ) ) {
			return true;
		}

		foreach ( $pages as $range ) {
			if ( in_array( $page_number, $this->_getRange( $range ), true ) ) {
				return true;
			}
		}

		return false;
	}


	/**
	 * Get range by given value
	 *
	 * @param mixed $value
	 * @return array
	 */
	private function _getRange( $value = null ) { // phpcs:ignore PSR2.Methods.MethodDeclaration.Underscore,WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
		$value = preg_replace( '/[^0-9\-.]/is', '', $value );

		if ( '' === $value ) {
			return false;
		}

		$value = explode( '-', $value );

		if ( 1 === count( $value ) ) {
			return $value;
		}

		return range( $value[0] > $value[1] ? $value[1] : $value[0], $value[0] > $value[1] ? $value[0] : $value[1] );
	}

}
