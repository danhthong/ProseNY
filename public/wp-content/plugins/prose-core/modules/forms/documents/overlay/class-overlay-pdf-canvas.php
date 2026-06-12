<?php
/**
 * Overlay PDF canvas — draw absolute-positioned text and rectangles.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Forms\Documents\Overlay;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Overlay_Pdf_Canvas
 *
 * A minimal, dependency-free PDF 1.4 canvas that draws text and rectangles at
 * absolute coordinates (PDF user space: origin bottom-left, points). Pages are
 * sized to match the official court document so the rendered layer can later be
 * stamped onto the original PDF without scaling.
 *
 * It uses the standard Helvetica font (no embedding) and emits one content
 * stream per page.
 */
final class Overlay_Pdf_Canvas {

	/**
	 * Page width in points.
	 *
	 * @var float
	 */
	private float $width;

	/**
	 * Page height in points.
	 *
	 * @var float
	 */
	private float $height;

	/**
	 * Drawing operations keyed by page index.
	 *
	 * @var array<int, string[]>
	 */
	private array $ops = array();

	/**
	 * Number of pages.
	 *
	 * @var int
	 */
	private int $page_count;

	/**
	 * Constructor.
	 *
	 * @param float $width      Page width (points).
	 * @param float $height     Page height (points).
	 * @param int   $page_count Number of pages (>= 1).
	 */
	public function __construct( float $width, float $height, int $page_count = 1 ) {
		$this->width      = $width > 0 ? $width : Pdf_Page_Geometry::LETTER_WIDTH;
		$this->height     = $height > 0 ? $height : Pdf_Page_Geometry::LETTER_HEIGHT;
		$this->page_count = max( 1, $page_count );
	}

	/**
	 * Page height (points).
	 *
	 * @return float
	 */
	public function height(): float {
		return $this->height;
	}

	/**
	 * Draw a single line of text. Coordinates are in PDF user space
	 * (origin bottom-left).
	 *
	 * @param int    $page Page index (0-based).
	 * @param float  $x    X position.
	 * @param float  $y    Y position (baseline).
	 * @param float  $size Font size.
	 * @param string $text Text.
	 * @return void
	 */
	public function text( int $page, float $x, float $y, float $size, string $text ): void {
		$this->ops[ $page ][] = sprintf(
			'BT /F1 %s Tf %s %s Td (%s) Tj ET',
			$this->num( $size ),
			$this->num( $x ),
			$this->num( $y ),
			$this->escape( $text )
		);
	}

	/**
	 * Draw a colored single line of text (RGB 0..1).
	 *
	 * @param int    $page Page index.
	 * @param float  $x    X.
	 * @param float  $y    Y (baseline).
	 * @param float  $size Font size.
	 * @param string $text Text.
	 * @param float  $r    Red.
	 * @param float  $g    Green.
	 * @param float  $b    Blue.
	 * @return void
	 */
	public function text_rgb( int $page, float $x, float $y, float $size, string $text, float $r, float $g, float $b ): void {
		$this->ops[ $page ][] = sprintf(
			'%s %s %s rg BT /F1 %s Tf %s %s Td (%s) Tj ET 0 0 0 rg',
			$this->num( $r ),
			$this->num( $g ),
			$this->num( $b ),
			$this->num( $size ),
			$this->num( $x ),
			$this->num( $y ),
			$this->escape( $text )
		);
	}

	/**
	 * Draw a stroked rectangle.
	 *
	 * @param int   $page   Page index.
	 * @param float $x      X (bottom-left).
	 * @param float $y      Y (bottom-left).
	 * @param float $w      Width.
	 * @param float $h      Height.
	 * @param float $stroke Stroke gray level (0..1).
	 * @return void
	 */
	public function rect( int $page, float $x, float $y, float $w, float $h, float $stroke = 0.6 ): void {
		$this->ops[ $page ][] = sprintf(
			'%s G 0.5 w %s %s %s %s re S 0 G',
			$this->num( $stroke ),
			$this->num( $x ),
			$this->num( $y ),
			$this->num( $w ),
			$this->num( $h )
		);
	}

	/**
	 * Draw a line.
	 *
	 * @param int   $page Page index.
	 * @param float $x1   Start X.
	 * @param float $y1   Start Y.
	 * @param float $x2   End X.
	 * @param float $y2   End Y.
	 * @param float $gray Gray level (0..1).
	 * @return void
	 */
	public function line( int $page, float $x1, float $y1, float $x2, float $y2, float $gray = 0.85 ): void {
		$this->ops[ $page ][] = sprintf(
			'%s G 0.3 w %s %s m %s %s l S 0 G',
			$this->num( $gray ),
			$this->num( $x1 ),
			$this->num( $y1 ),
			$this->num( $x2 ),
			$this->num( $y2 )
		);
	}

	/**
	 * Render the canvas to PDF bytes.
	 *
	 * @return string
	 */
	public function render(): string {
		$objects   = array();
		$kids      = array();
		$font_obj  = 3 + ( $this->page_count * 2 );
		$object_id = 3;

		for ( $page = 0; $page < $this->page_count; $page++ ) {
			$content_id = $object_id + 1;
			$kids[]     = $object_id . ' 0 R';

			$objects[ $object_id ] = sprintf(
				'<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %s %s] /Resources << /Font << /F1 %d 0 R >> >> /Contents %d 0 R >>',
				$this->num( $this->width ),
				$this->num( $this->height ),
				$font_obj,
				$content_id
			);

			$stream                 = implode( "\n", $this->ops[ $page ] ?? array() );
			$objects[ $content_id ] = sprintf(
				"<< /Length %d >>\nstream\n%s\nendstream",
				strlen( $stream ),
				$stream
			);

			$object_id += 2;
		}

		$objects[1]           = '<< /Type /Catalog /Pages 2 0 R >>';
		$objects[2]           = sprintf( '<< /Type /Pages /Kids [%s] /Count %d >>', implode( ' ', $kids ), $this->page_count );
		$objects[ $font_obj ] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>';

		return $this->assemble( $objects );
	}

	/**
	 * Assemble PDF objects into final bytes with xref + trailer.
	 *
	 * @param array<int, string> $objects Objects keyed by id.
	 * @return string
	 */
	private function assemble( array $objects ): string {
		ksort( $objects );

		$pdf     = "%PDF-1.4\n";
		$offsets = array();

		foreach ( $objects as $id => $body ) {
			$offsets[ $id ] = strlen( $pdf );
			$pdf           .= $id . " 0 obj\n" . $body . "\nendobj\n";
		}

		$max_id  = max( array_keys( $objects ) );
		$xref_at = strlen( $pdf );

		$pdf .= "xref\n0 " . ( $max_id + 1 ) . "\n";
		$pdf .= "0000000000 65535 f \n";

		for ( $id = 1; $id <= $max_id; $id++ ) {
			if ( isset( $offsets[ $id ] ) ) {
				$pdf .= sprintf( "%010d 00000 n \n", $offsets[ $id ] );
			} else {
				$pdf .= "0000000000 65535 f \n";
			}
		}

		$pdf .= "trailer\n<< /Size " . ( $max_id + 1 ) . " /Root 1 0 R >>\n";
		$pdf .= "startxref\n" . $xref_at . "\n%%EOF";

		return $pdf;
	}

	/**
	 * Format a float for PDF output.
	 *
	 * @param float $value Value.
	 * @return string
	 */
	private function num( float $value ): string {
		$formatted = number_format( $value, 2, '.', '' );

		return rtrim( rtrim( $formatted, '0' ), '.' );
	}

	/**
	 * Escape a PDF literal string (WinAnsi / ASCII).
	 *
	 * @param string $text Text.
	 * @return string
	 */
	private function escape( string $text ): string {
		$text = (string) preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $text );

		return strtr(
			$text,
			array(
				'\\' => '\\\\',
				'('  => '\\(',
				')'  => '\\)',
				"\r" => '',
				"\n" => ' ',
			)
		);
	}
}
