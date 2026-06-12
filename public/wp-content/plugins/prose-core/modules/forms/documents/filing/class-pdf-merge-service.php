<?php
/**
 * PDF merge service — merge filled forms into a single packet PDF.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Forms\Documents\Filing;

use ProSe\Core\Forms\Documents\Pdf\Pdf_Document_Writer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Pdf_Merge_Service
 *
 * Merges an ordered set of filled court forms into one filing packet PDF,
 * preserving filing order.
 *
 * Strategy:
 *   - PDFTK:   when every form was produced as a real template PDF and the
 *     pdftk toolchain is available, the source PDFs are concatenated with
 *     pdftk so each form's original court layout is preserved page for page.
 *   - BUILTIN: otherwise the forms' rendered sections are composed into a
 *     single multi-page PDF (one form per page break) with the builtin writer.
 */
final class Pdf_Merge_Service {

	public const STRATEGY_PDFTK   = 'pdftk';
	public const STRATEGY_BUILTIN = 'builtin';

	/**
	 * PDF writer (builtin strategy).
	 *
	 * @var Pdf_Document_Writer
	 */
	private Pdf_Document_Writer $writer;

	/**
	 * Cached pdftk availability.
	 *
	 * @var bool|null
	 */
	private ?bool $pdftk_available = null;

	/**
	 * Constructor.
	 *
	 * @param Pdf_Document_Writer|null $writer PDF writer.
	 */
	public function __construct( ?Pdf_Document_Writer $writer = null ) {
		$this->writer = $writer ?? new Pdf_Document_Writer();
	}

	/**
	 * Merge filled forms into a single packet PDF.
	 *
	 * @param array<int, array<string, mixed>> $forms   Ordered fill descriptors.
	 * @param array<string, mixed>             $options Options.
	 * @return array<string, mixed> { bytes, page_count, strategy }.
	 */
	public function merge( array $forms, array $options = array() ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		if ( $this->can_merge_with_pdftk( $forms ) ) {
			$merged = $this->merge_with_pdftk( $forms );

			if ( null !== $merged ) {
				return array(
					'bytes'      => $merged,
					'page_count' => $this->page_count( $merged ),
					'strategy'   => self::STRATEGY_PDFTK,
				);
			}
		}

		$bytes = $this->merge_builtin( $forms );

		return array(
			'bytes'      => $bytes,
			'page_count' => $this->page_count( $bytes ),
			'strategy'   => self::STRATEGY_BUILTIN,
		);
	}

	/**
	 * Count pages in a PDF byte string.
	 *
	 * @param string $bytes PDF bytes.
	 * @return int
	 */
	public function page_count( string $bytes ): int {
		return Pdf_Document_Writer::count_pages( $bytes );
	}

	/**
	 * Compose filled-form sections into a single builtin PDF.
	 *
	 * @param array<int, array<string, mixed>> $forms Fill descriptors.
	 * @return string
	 */
	private function merge_builtin( array $forms ): string {
		$sections = array();

		foreach ( $forms as $form ) {
			$sections[] = (array) ( $form['sections'] ?? array() );
		}

		if ( empty( $sections ) ) {
			$sections[] = array( 'Empty filing packet.' );
		}

		return $this->writer->build( $sections );
	}

	/**
	 * Whether every form is a real PDF file mergeable with pdftk.
	 *
	 * @param array<int, array<string, mixed>> $forms Fill descriptors.
	 * @return bool
	 */
	private function can_merge_with_pdftk( array $forms ): bool {
		if ( empty( $forms ) || ! class_exists( '\mikehaertl\pdftk\Pdf' ) ) {
			return false;
		}

		foreach ( $forms as $form ) {
			if ( Court_Pdf_Fill_Service::STRATEGY_ACROFORM !== ( $form['strategy'] ?? '' ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Merge real PDF sources with pdftk.
	 *
	 * @param array<int, array<string, mixed>> $forms Fill descriptors.
	 * @return string|null Merged bytes, or null on failure.
	 */
	private function merge_with_pdftk( array $forms ): ?string {
		$sources = array();
		$handles = array();

		try {
			foreach ( $forms as $index => $form ) {
				$tmp = tempnam( sys_get_temp_dir(), 'prose-merge-' );

				if ( false === $tmp ) {
					return null;
				}

				file_put_contents( $tmp, (string) ( $form['bytes'] ?? '' ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
				$handles[]               = $tmp;
				$sources[ 'F' . $index ] = $tmp;
			}

			$class = '\mikehaertl\pdftk\Pdf';
			$pdf   = new $class( $sources );

			foreach ( array_keys( $sources ) as $handle ) {
				$pdf->cat( null, null, $handle );
			}

			$out = tempnam( sys_get_temp_dir(), 'prose-packet-' );

			if ( false === $out || ! $pdf->saveAs( $out ) ) {
				return null;
			}

			$bytes     = (string) file_get_contents( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			$handles[] = $out;

			return '' === $bytes ? null : $bytes;
		} catch ( \Throwable $e ) {
			return null;
		} finally {
			foreach ( $handles as $handle ) {
				if ( is_string( $handle ) && file_exists( $handle ) ) {
					unlink( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
				}
			}
		}
	}
}
