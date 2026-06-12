<?php
/**
 * Minimal, dependency-free PDF 1.4 writer.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Forms\Documents\Pdf;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Pdf_Document_Writer
 *
 * A tiny self-contained PDF generator that emits a valid multi-page PDF 1.4
 * document from plain text lines using the standard Helvetica font (no font
 * embedding, no external libraries, no binaries). It is intentionally small:
 * the Document Generation Engine produces text-based field listings and this
 * writer renders them into a portable artifact the storage layer can persist.
 *
 * Input is a list of "sections"; every section starts on a fresh page and
 * long sections wrap onto additional pages automatically.
 */
final class Pdf_Document_Writer {

	private const PAGE_WIDTH  = 612;
	private const PAGE_HEIGHT = 792;
	private const MARGIN_X    = 54;
	private const MARGIN_TOP  = 56;
	private const MARGIN_BOT  = 54;
	private const FONT_SIZE   = 11;
	private const LEADING     = 15;

	/**
	 * Count the page objects in a PDF byte string.
	 *
	 * Matches the page node (/Type /Page) without matching the page tree
	 * (/Type /Pages). Works for the documents this writer produces and for
	 * typical uncompressed PDFs.
	 *
	 * @param string $bytes PDF bytes.
	 * @return int
	 */
	public static function count_pages( string $bytes ): int {
		return (int) preg_match_all( '#/Type\s*/Page\b#', $bytes );
	}

	/**
	 * Build a PDF document from text sections.
	 *
	 * @param array<int, array<int, string>> $sections One array of lines per logical section.
	 * @return string Raw PDF bytes.
	 */
	public function build( array $sections ): string {
		$pages = $this->paginate( $sections );

		$objects    = array();
		$objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
		$objects[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';

		$kids   = array();
		$obj_id = 4;

		foreach ( $pages as $lines ) {
			$page_id    = $obj_id++;
			$content_id = $obj_id++;
			$kids[]     = $page_id . ' 0 R';

			$objects[ $page_id ] = sprintf(
				'<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %d %d] /Resources << /Font << /F1 3 0 R >> >> /Contents %d 0 R >>',
				self::PAGE_WIDTH,
				self::PAGE_HEIGHT,
				$content_id
			);

			$stream                 = $this->content_stream( $lines );
			$objects[ $content_id ] = '<< /Length ' . strlen( $stream ) . " >>\nstream\n" . $stream . "\nendstream";
		}

		$objects[2] = '<< /Type /Pages /Kids [' . implode( ' ', $kids ) . '] /Count ' . count( $pages ) . ' >>';

		ksort( $objects );

		return $this->assemble( $objects );
	}

	/**
	 * Expand sections into physical pages, wrapping long sections.
	 *
	 * @param array<int, array<int, string>> $sections Sections.
	 * @return array<int, array<int, string>>
	 */
	private function paginate( array $sections ): array {
		$per_page = $this->lines_per_page();
		$pages    = array();

		foreach ( $sections as $lines ) {
			$lines = array_values( array_map( 'strval', $lines ) );

			if ( empty( $lines ) ) {
				$pages[] = array();
				continue;
			}

			foreach ( array_chunk( $lines, $per_page ) as $chunk ) {
				$pages[] = $chunk;
			}
		}

		if ( empty( $pages ) ) {
			$pages[] = array();
		}

		return $pages;
	}

	/**
	 * Maximum text lines that fit on one page.
	 *
	 * @return int
	 */
	private function lines_per_page(): int {
		$usable = self::PAGE_HEIGHT - self::MARGIN_TOP - self::MARGIN_BOT;

		return (int) max( 1, floor( $usable / self::LEADING ) );
	}

	/**
	 * Build the text content stream for a single page.
	 *
	 * @param array<int, string> $lines Lines.
	 * @return string
	 */
	private function content_stream( array $lines ): string {
		$x = self::MARGIN_X;
		$y = self::PAGE_HEIGHT - self::MARGIN_TOP;

		$out  = "BT\n";
		$out .= '/F1 ' . self::FONT_SIZE . " Tf\n";
		$out .= self::LEADING . " TL\n";
		$out .= sprintf( "1 0 0 1 %d %d Tm\n", $x, $y );

		$first = true;

		foreach ( $lines as $line ) {
			$text = $this->escape( (string) $line );

			if ( $first ) {
				$out  .= '(' . $text . ") Tj\n";
				$first = false;
				continue;
			}

			$out .= 'T*(' . $text . ") Tj\n";
		}

		$out .= 'ET';

		return $out;
	}

	/**
	 * Assemble objects into the final PDF byte string with xref + trailer.
	 *
	 * @param array<int, string> $objects Object bodies keyed by id (contiguous from 1).
	 * @return string
	 */
	private function assemble( array $objects ): string {
		$pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";

		$offsets = array();
		$max_id  = max( array_keys( $objects ) );

		for ( $id = 1; $id <= $max_id; $id++ ) {
			if ( ! isset( $objects[ $id ] ) ) {
				continue;
			}

			$offsets[ $id ] = strlen( $pdf );
			$pdf           .= $id . " 0 obj\n" . $objects[ $id ] . "\nendobj\n";
		}

		$xref_offset = strlen( $pdf );
		$size        = $max_id + 1;

		$pdf .= "xref\n0 " . $size . "\n";
		$pdf .= "0000000000 65535 f \n";

		for ( $id = 1; $id <= $max_id; $id++ ) {
			if ( isset( $offsets[ $id ] ) ) {
				$pdf .= sprintf( "%010d 00000 n \n", $offsets[ $id ] );
			} else {
				$pdf .= "0000000000 65535 f \n";
			}
		}

		$pdf .= "trailer\n<< /Size " . $size . " /Root 1 0 R >>\n";
		$pdf .= 'startxref' . "\n" . $xref_offset . "\n%%EOF";

		return $pdf;
	}

	/**
	 * Escape a string for a PDF literal string and drop control bytes.
	 *
	 * @param string $value Raw value.
	 * @return string
	 */
	private function escape( string $value ): string {
		$value = str_replace( array( '\\', '(', ')' ), array( '\\\\', '\\(', '\\)' ), $value );

		return (string) preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $value );
	}
}
