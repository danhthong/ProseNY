<?php
/**
 * PDF engine contract for text and field extraction.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Forms\Pdf;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Interface Pdf_Engine_Interface
 */
interface Pdf_Engine_Interface {

	/**
	 * Whether this engine can run in the current environment.
	 *
	 * @return bool
	 */
	public function is_available(): bool;

	/**
	 * Engine identifier (php, python).
	 *
	 * @return string
	 */
	public function get_id(): string;

	/**
	 * Extract text from the first N pages of a PDF.
	 *
	 * @param string $file_path Local PDF path.
	 * @param int    $max_pages Maximum pages to scan.
	 * @return string
	 */
	public function extract_text( string $file_path, int $max_pages = 3 ): string;

	/**
	 * Extract AcroForm fields from a PDF.
	 *
	 * @param string $file_path Local PDF path.
	 * @return array<int, array<string, mixed>>
	 */
	public function extract_fields( string $file_path ): array;
}
