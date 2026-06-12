<?php
/**
 * Overlay renderer — draw resolved field values onto official-PDF geometry.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Forms\Documents\Overlay;

use ProSe\Core\Forms\Documents\Generated_Document;
use ProSe\Core\Forms\Documents\Pdf\Pdf_Storage_Service;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Overlay_Renderer
 *
 * Renders field values at calibrated coordinates onto a page sized to match the
 * official court PDF. When a rasterizer (Poppler `pdftoppm`) is available, the
 * official PDF page is rendered to a JPEG and composited as a layout-preserving
 * background behind crisp vector text — producing a filled, filing-ready PDF
 * (mode STAMPED). When no rasterizer is available it falls back to a standalone
 * overlay layer (mode OVERLAY). It never modifies the official PDF on disk.
 *
 * Layout coordinates use a top-left origin; the renderer converts to PDF user
 * space (origin bottom-left).
 */
final class Overlay_Renderer {

	/**
	 * Layout registry.
	 *
	 * @var Form_Layout_Registry
	 */
	private Form_Layout_Registry $registry;

	/**
	 * Storage service (optional).
	 *
	 * @var Pdf_Storage_Service|null
	 */
	private ?Pdf_Storage_Service $storage;

	/**
	 * Page rasterizer (optional).
	 *
	 * @var Pdf_Rasterizer|null
	 */
	private ?Pdf_Rasterizer $rasterizer;

	/**
	 * Constructor.
	 *
	 * @param Form_Layout_Registry|null $registry   Layout registry.
	 * @param Pdf_Storage_Service|null  $storage    Storage service.
	 * @param Pdf_Rasterizer|null       $rasterizer Page rasterizer.
	 */
	public function __construct( ?Form_Layout_Registry $registry = null, ?Pdf_Storage_Service $storage = null, ?Pdf_Rasterizer $rasterizer = null ) {
		$this->registry   = $registry ?? new Form_Layout_Registry();
		$this->storage    = $storage;
		$this->rasterizer = $rasterizer;
	}

	/**
	 * Render an overlay for a generated document.
	 *
	 * @param Generated_Document   $document Document.
	 * @param array<string, mixed> $options  Options.
	 * @return Overlay_Render_Result
	 */
	public function render_document( Generated_Document $document, array $options = array() ): Overlay_Render_Result {
		return $this->render( $document->form_code(), $document->values(), $options );
	}

	/**
	 * Render an overlay for a form code with a value map.
	 *
	 * @param string               $form_code Form code.
	 * @param array<string, mixed> $values    Canonical field values.
	 * @param array<string, mixed> $options   Options: template_path, filename, store.
	 * @return Overlay_Render_Result
	 */
	public function render( string $form_code, array $values, array $options = array() ): Overlay_Render_Result {
		$start    = microtime( true );
		$layout   = $this->registry->load( $form_code );
		$size     = $this->resolve_size( $layout, $options );
		$canvas   = new Overlay_Pdf_Canvas( $size['width'], $size['height'], (int) $layout['pages'] );
		$warnings = array();

		$counts = $this->draw_fields( $canvas, $layout, $size, $values );
		$bytes  = $canvas->render();

		return $this->result(
			Overlay_Render_Result::MODE_OVERLAY,
			$form_code,
			$layout,
			$size,
			count( $layout['fields'] ),
			$counts['rendered'],
			$counts['skipped'],
			$warnings,
			$bytes,
			$options,
			$this->elapsed_ms( $start )
		);
	}

	/**
	 * Render a filled, filing-ready PDF: composite the rasterized official PDF
	 * page(s) as a background, then overlay field values at coordinates.
	 *
	 * The original PDF is never modified. Requires a rasterizer (Poppler); when
	 * one is unavailable, falls back to a standalone overlay layer and records a
	 * warning.
	 *
	 * @param string               $form_code Form code.
	 * @param array<string, mixed> $values    Canonical field values.
	 * @param array<string, mixed> $options   Options: template_path, filename, store, dpi.
	 * @return Overlay_Render_Result
	 */
	public function render_filled( string $form_code, array $values, array $options = array() ): Overlay_Render_Result {
		$start    = microtime( true );
		$layout   = $this->registry->load( $form_code );
		$size     = $this->resolve_size( $layout, $options );
		$canvas   = new Overlay_Pdf_Canvas( $size['width'], $size['height'], (int) $layout['pages'] );
		$warnings = array();

		$mode = $this->apply_background( $canvas, $options, $warnings );

		$counts = $this->draw_fields( $canvas, $layout, $size, $values );
		$bytes  = $canvas->render();

		return $this->result(
			$mode,
			$form_code,
			$layout,
			$size,
			count( $layout['fields'] ),
			$counts['rendered'],
			$counts['skipped'],
			$warnings,
			$bytes,
			$options,
			$this->elapsed_ms( $start )
		);
	}

	/**
	 * Composite the rasterized official PDF as a per-page background.
	 *
	 * @param Overlay_Pdf_Canvas   $canvas   Canvas.
	 * @param array<string, mixed> $options  Options (template_path, dpi).
	 * @param string[]             $warnings Warnings (by reference).
	 * @return string Render mode (STAMPED when composited, else OVERLAY).
	 */
	private function apply_background( Overlay_Pdf_Canvas $canvas, array $options, array &$warnings ): string {
		$template_path = (string) ( $options['template_path'] ?? '' );

		if ( '' === $template_path || ! is_readable( $template_path ) ) {
			$warnings[] = 'official template not found; produced overlay layer without background';
			return Overlay_Render_Result::MODE_OVERLAY;
		}

		$rasterizer = $this->rasterizer ?? new Pdf_Rasterizer( (int) ( $options['dpi'] ?? 200 ) );

		if ( ! $rasterizer->available() ) {
			$warnings[] = 'no rasterizer (pdftoppm) available; produced overlay layer without background';
			return Overlay_Render_Result::MODE_OVERLAY;
		}

		$pages = $rasterizer->to_jpeg_pages( $template_path );

		if ( array() === $pages ) {
			$warnings[] = 'rasterizer produced no pages; produced overlay layer without background';
			return Overlay_Render_Result::MODE_OVERLAY;
		}

		foreach ( $pages as $index => $jpeg ) {
			$canvas->set_background( $index, $jpeg );
		}

		return Overlay_Render_Result::MODE_STAMPED;
	}

	/**
	 * Draw all layout field values onto the canvas.
	 *
	 * @param Overlay_Pdf_Canvas                 $canvas Canvas.
	 * @param array<string, mixed>               $layout Layout.
	 * @param array{width: float, height: float} $size   Page size.
	 * @param array<string, mixed>               $values Values.
	 * @return array{rendered: int, skipped: int}
	 */
	private function draw_fields( Overlay_Pdf_Canvas $canvas, array $layout, array $size, array $values ): array {
		$rendered = 0;
		$skipped  = 0;

		foreach ( $layout['fields'] as $field ) {
			$page  = min( (int) $field['page'] - 1, (int) $layout['pages'] - 1 );
			$value = $values[ $field['source'] ] ?? ( $values[ $field['key'] ] ?? null );

			if ( $field['checkbox'] ) {
				if ( $this->is_checked( $value ) ) {
					$canvas->text( $page, (float) $field['x'], $this->baseline( $size['height'], $field ), (float) $field['font_size'], 'X' );
					++$rendered;
				} else {
					++$skipped;
				}
				continue;
			}

			$text = $this->stringify( $value );

			if ( '' === $text ) {
				++$skipped;
				continue;
			}

			$this->draw_text_block( $canvas, $page, $size['height'], $field, $text );
			++$rendered;
		}

		return array(
			'rendered' => $rendered,
			'skipped'  => $skipped,
		);
	}

	/**
	 * Elapsed milliseconds since a start microtime.
	 *
	 * @param float $start Start (microtime float).
	 * @return int
	 */
	private function elapsed_ms( float $start ): int {
		return (int) round( ( microtime( true ) - $start ) * 1000 );
	}

	/**
	 * Render a calibration debug overlay: field boundaries, labels, coordinates.
	 *
	 * @param string               $form_code Form code.
	 * @param array<string, mixed> $options   Options: template_path, filename, store, grid.
	 * @return Overlay_Render_Result
	 */
	public function render_debug( string $form_code, array $options = array() ): Overlay_Render_Result {
		$start    = microtime( true );
		$layout   = $this->registry->load( $form_code );
		$size     = $this->resolve_size( $layout, $options );
		$canvas   = new Overlay_Pdf_Canvas( $size['width'], $size['height'], (int) $layout['pages'] );
		$values   = (array) ( $options['values'] ?? array() );
		$warnings = array();

		$background = (bool) ( $options['background'] ?? false );

		if ( $background ) {
			$this->apply_background( $canvas, $options, $warnings );
		}

		$grid = isset( $options['grid'] ) ? (bool) $options['grid'] : ! $background;

		if ( $grid ) {
			$this->draw_grid( $canvas, $size, (int) $layout['pages'] );
		}

		foreach ( $layout['fields'] as $field ) {
			$page   = min( (int) $field['page'] - 1, (int) $layout['pages'] - 1 );
			$width  = (float) $field['max_width'] > 0 ? (float) $field['max_width'] : 150.0;
			$height = max( (float) $field['line_height'], (float) $field['font_size'] + 2.0 );

			$box_y = $size['height'] - (float) $field['y'] - $height;

			$canvas->rect( $page, (float) $field['x'], $box_y, $width, $height, 0.55 );

			// Field label "[key]" above the box, in red.
			$canvas->text_rgb( $page, (float) $field['x'], $size['height'] - (float) $field['y'] + 3.0, 7.0, '[' . $field['key'] . ']', 0.8, 0.0, 0.0 );

			// Resolved value at the field baseline (black), if provided.
			$value = $this->stringify( $values[ $field['source'] ] ?? ( $values[ $field['key'] ] ?? null ) );

			if ( '' !== $value ) {
				$this->draw_text_block( $canvas, $page, $size['height'], $field, $value );
			}

			// Coordinate marker below the box, in blue.
			$coord = sprintf( '(%s,%s) p%d s%s%s', $this->n( $field['x'] ), $this->n( $field['y'] ), (int) $field['page'], $this->n( $field['font_size'] ), $field['multiline'] ? ' ML' : '' );
			$canvas->text_rgb( $page, (float) $field['x'], $box_y - 8.0, 6.0, $coord, 0.0, 0.0, 0.8 );
		}

		$bytes = $canvas->render();

		return $this->result(
			Overlay_Render_Result::MODE_DEBUG,
			$form_code,
			$layout,
			$size,
			count( $layout['fields'] ),
			count( $layout['fields'] ),
			0,
			$warnings,
			$bytes,
			$options,
			$this->elapsed_ms( $start )
		);
	}

	/**
	 * Build a render result, optionally storing the bytes.
	 *
	 * @param string                             $mode       Render mode.
	 * @param string                             $form_code  Form code.
	 * @param array<string, mixed>               $layout     Layout.
	 * @param array{width: float, height: float} $size Page size.
	 * @param int                                $field_count Total fields.
	 * @param int                                $rendered   Rendered count.
	 * @param int                                $skipped    Skipped count.
	 * @param string[]                           $warnings   Warnings.
	 * @param string                             $bytes      PDF bytes.
	 * @param array<string, mixed>               $options    Options.
	 * @param int                                $duration_ms Render duration (ms).
	 * @return Overlay_Render_Result
	 */
	private function result(
		string $mode,
		string $form_code,
		array $layout,
		array $size,
		int $field_count,
		int $rendered,
		int $skipped,
		array $warnings,
		string $bytes,
		array $options,
		int $duration_ms = 0
	): Overlay_Render_Result {
		$checksum  = 'sha256:' . hash( 'sha256', $bytes );
		$file_path = '';
		$download  = '';
		$store     = (bool) ( $options['store'] ?? false );

		if ( $store && $this->storage instanceof Pdf_Storage_Service ) {
			$filename  = (string) ( $options['filename'] ?? ( $form_code . '-' . $mode . '.pdf' ) );
			$stored    = $this->storage->store( $bytes, $filename );
			$file_path = (string) $stored['file_path'];
			$download  = (string) $stored['download_url'];
			$checksum  = (string) $stored['checksum'];
		}

		return new Overlay_Render_Result(
			array(
				'success'            => true,
				'mode'               => $mode,
				'form_code'          => $form_code,
				'template'           => (string) $layout['template'],
				'page_count'         => (int) $layout['pages'],
				'page_size'          => $size,
				'field_count'        => $field_count,
				'rendered_count'     => $rendered,
				'skipped_count'      => $skipped,
				'file_path'          => $file_path,
				'download_url'       => $download,
				'checksum'           => $checksum,
				'bytes'              => strlen( $bytes ),
				'warnings'           => $warnings,
				'render_duration_ms' => $duration_ms,
				'pdf'                => $bytes,
			)
		);
	}

	/**
	 * Resolve the overlay page size: layout -> official template -> Letter.
	 *
	 * @param array<string, mixed> $layout  Layout.
	 * @param array<string, mixed> $options Options.
	 * @return array{width: float, height: float}
	 */
	private function resolve_size( array $layout, array $options ): array {
		$width  = (float) ( $layout['page_size']['width'] ?? 0 );
		$height = (float) ( $layout['page_size']['height'] ?? 0 );

		if ( $width > 0 && $height > 0 ) {
			return array(
				'width'  => $width,
				'height' => $height,
			);
		}

		$template_path = (string) ( $options['template_path'] ?? '' );

		if ( '' !== $template_path && is_readable( $template_path ) ) {
			return Pdf_Page_Geometry::size( $template_path );
		}

		return Pdf_Page_Geometry::default_size();
	}

	/**
	 * Draw a (possibly wrapped/multiline) text block.
	 *
	 * @param Overlay_Pdf_Canvas   $canvas Canvas.
	 * @param int                  $page   Page index.
	 * @param float                $height Page height.
	 * @param array<string, mixed> $field  Field.
	 * @param string               $text   Text.
	 * @return void
	 */
	private function draw_text_block( Overlay_Pdf_Canvas $canvas, int $page, float $height, array $field, string $text ): void {
		$lines = $field['multiline']
			? $this->wrap( $text, (float) $field['max_width'], (float) $field['font_size'] )
			: array( $text );

		$y = $this->baseline( $height, $field );

		foreach ( $lines as $line ) {
			$canvas->text( $page, (float) $field['x'], $y, (float) $field['font_size'], $line );
			$y -= (float) $field['line_height'];
		}
	}

	/**
	 * Convert a top-left field y to a PDF baseline y.
	 *
	 * @param float                $height Page height.
	 * @param array<string, mixed> $field  Field.
	 * @return float
	 */
	private function baseline( float $height, array $field ): float {
		return $height - (float) $field['y'] - (float) $field['font_size'];
	}

	/**
	 * Wrap text to a maximum width (approximate, Helvetica ~0.5em average).
	 *
	 * @param string $text      Text.
	 * @param float  $max_width Max width in points (0 = no wrap, split newlines only).
	 * @param float  $font_size Font size.
	 * @return string[]
	 */
	private function wrap( string $text, float $max_width, float $font_size ): array {
		$paragraphs = preg_split( '/\r\n|\r|\n/', $text );

		if ( false === $paragraphs || array() === $paragraphs ) {
			$paragraphs = array( $text );
		}

		if ( $max_width <= 0 ) {
			return $paragraphs;
		}

		$char_width = $font_size * 0.5;
		$max_chars  = (int) max( 1, floor( $max_width / max( 0.1, $char_width ) ) );
		$lines      = array();

		foreach ( $paragraphs as $paragraph ) {
			$words   = preg_split( '/\s+/', trim( $paragraph ) );
			$words   = false === $words ? array() : $words;
			$current = '';

			foreach ( $words as $word ) {
				$candidate = '' === $current ? $word : $current . ' ' . $word;

				if ( strlen( $candidate ) > $max_chars && '' !== $current ) {
					$lines[] = $current;
					$current = $word;
				} else {
					$current = $candidate;
				}
			}

			$lines[] = $current;
		}

		return $lines;
	}

	/**
	 * Draw a light calibration grid + ruler ticks.
	 *
	 * @param Overlay_Pdf_Canvas                 $canvas Canvas.
	 * @param array{width: float, height: float} $size   Page size.
	 * @param int                                $pages  Page count.
	 * @return void
	 */
	private function draw_grid( Overlay_Pdf_Canvas $canvas, array $size, int $pages ): void {
		$step = 50.0;

		for ( $page = 0; $page < $pages; $page++ ) {
			for ( $x = $step; $x < $size['width']; $x += $step ) {
				$canvas->line( $page, $x, 0, $x, $size['height'], 0.9 );
				$canvas->text_rgb( $page, $x + 1.0, $size['height'] - 8.0, 5.0, (string) (int) $x, 0.6, 0.6, 0.6 );
			}

			for ( $y = $step; $y < $size['height']; $y += $step ) {
				$canvas->line( $page, 0, $size['height'] - $y, $size['width'], $size['height'] - $y, 0.9 );
				$canvas->text_rgb( $page, 2.0, $size['height'] - $y + 1.0, 5.0, (string) (int) $y, 0.6, 0.6, 0.6 );
			}
		}
	}

	/**
	 * Whether a value represents a checked state.
	 *
	 * @param mixed $value Value.
	 * @return bool
	 */
	private function is_checked( $value ): bool {
		if ( is_bool( $value ) ) {
			return $value;
		}

		return in_array( strtolower( trim( (string) $value ) ), array( '1', 'yes', 'true', 'x', 'on', 'checked' ), true );
	}

	/**
	 * Stringify a value for rendering.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	private function stringify( $value ): string {
		if ( null === $value || is_bool( $value ) ) {
			return is_bool( $value ) ? ( $value ? 'Yes' : '' ) : '';
		}

		if ( is_array( $value ) ) {
			return implode( ', ', array_map( 'strval', $value ) );
		}

		return trim( (string) $value );
	}

	/**
	 * Format a number compactly for debug labels.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	private function n( $value ): string {
		return rtrim( rtrim( number_format( (float) $value, 1, '.', '' ), '0' ), '.' );
	}
}
