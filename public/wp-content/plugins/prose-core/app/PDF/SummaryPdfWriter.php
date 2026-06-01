<?php
/**
 * Minimal PDF writer for draft forms when official templates are missing.
 *
 * @package ProseCore
 */

namespace Prose\Core\PDF;

final class SummaryPdfWriter {

	/**
	 * @param array<int, string> $lines
	 */
	public function write( string $title, array $lines, string $output_path ): string {
		wp_mkdir_p( dirname( $output_path ) );

		$stream = "BT\n/F1 14 Tf\n50 750 Td (" . $this->escape( $title ) . ") Tj\n";
		$y      = 720;

		foreach ( $lines as $line ) {
			$stream .= "0 -16 Td (" . $this->escape( $line ) . ") Tj\n";
			$y -= 16;
			if ( $y < 72 ) {
				break;
			}
		}
		$stream .= "ET";

		$len  = strlen( $stream );
		$pdf  = "%PDF-1.4\n";
		$pdf .= "1 0 obj<< /Type /Catalog /Pages 2 0 R >>endobj\n";
		$pdf .= "2 0 obj<< /Type /Pages /Kids [3 0 R] /Count 1 >>endobj\n";
		$pdf .= "3 0 obj<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Contents 4 0 R /Resources<< /Font<< /F1 5 0 R >> >> >>endobj\n";
		$pdf .= "4 0 obj<< /Length {$len} >>stream\n{$stream}\nendstream endobj\n";
		$pdf .= "5 0 obj<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>endobj\n";
		$pdf .= "xref\n0 6\n0000000000 65535 f \n";
		$pdf .= "trailer<< /Size 6 /Root 1 0 R >>\nstartxref\n0\n%%EOF";

		file_put_contents( $output_path, $pdf );

		return $output_path;
	}

	private function escape( string $text ): string {
		return str_replace(
			array( '\\', '(', ')' ),
			array( '\\\\', '\\(', '\\)' ),
			$text
		);
	}
}
