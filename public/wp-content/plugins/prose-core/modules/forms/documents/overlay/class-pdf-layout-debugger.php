<?php
/**
 * PDF layout debugger — visual calibration tool for overlay coordinate maps.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Forms\Documents\Overlay;

use ProSe\Core\Forms\Documents\Pdf\Pdf_Storage_Service;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Pdf_Layout_Debugger
 *
 * Produces a calibration overlay ("grid" PDF) for a form's coordinate map:
 * a fine coordinate grid, X/Y rulers, per-field markers, labels, bounding boxes
 * and page dimensions — composited over the rasterized official PDF so a human
 * can read off accurate coordinates and align fields to the real blanks in
 * minutes.
 *
 * This is calibration tooling only. It uses the public canvas/rasterizer
 * primitives; it does not modify the overlay renderer or document generation.
 */
final class Pdf_Layout_Debugger {

	/**
	 * Default grid step in points.
	 */
	public const GRID_STEP = 25;

	/**
	 * Ruler label interval in points.
	 */
	private const RULER_STEP = 50;

	/**
	 * Heavy grid line interval in points.
	 */
	private const MAJOR_STEP = 100;

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
	 * Build a calibration grid overlay for a form.
	 *
	 * @param string               $form_code Form code (e.g. UD-1).
	 * @param array<string, mixed> $options   Options: template_path, filename,
	 *                                         store (bool), background (bool),
	 *                                         grid_step (int), dpi (int).
	 * @return array<string, mixed> Calibration report (includes 'pdf' bytes).
	 */
	public function generate( string $form_code, array $options = array() ): array {
		$layout    = $this->registry->load( $form_code );
		$size      = $this->resolve_size( $layout, $options );
		$step      = max( 5, (int) ( $options['grid_step'] ?? self::GRID_STEP ) );
		$pages     = (int) $layout['pages'];
		$canvas    = new Overlay_Pdf_Canvas( $size['width'], $size['height'], $pages );
		$warnings  = array();
		$composite = false;

		if ( ! isset( $options['background'] ) || (bool) $options['background'] ) {
			$composite = $this->apply_background( $canvas, $options, $warnings );
		}

		for ( $page = 0; $page < $pages; $page++ ) {
			$this->draw_grid( $canvas, $page, $size, $step );
			$this->draw_rulers( $canvas, $page, $size );
			$this->draw_page_dimensions( $canvas, $page, $size, $step, $composite );
		}

		$fields = array();

		foreach ( $layout['fields'] as $field ) {
			$page = min( (int) $field['page'] - 1, $pages - 1 );
			$this->draw_field( $canvas, $page, $size, $field );

			$fields[] = array(
				'key'       => (string) $field['key'],
				'label'     => (string) $field['label'],
				'source'    => (string) $field['source'],
				'page'      => (int) $field['page'],
				'x'         => (float) $field['x'],
				'y'         => (float) $field['y'],
				'font_size' => (float) $field['font_size'],
				'multiline' => (bool) $field['multiline'],
				'checkbox'  => (bool) $field['checkbox'],
			);
		}

		$bytes = $canvas->render();

		return $this->finalize( $form_code, $layout, $size, $step, $composite, $fields, $warnings, $bytes, $options );
	}

	/**
	 * Draw the coordinate grid.
	 *
	 * @param Overlay_Pdf_Canvas                 $canvas Canvas.
	 * @param int                                $page   Page index.
	 * @param array{width: float, height: float} $size   Page size.
	 * @param int                                $step   Grid step (points).
	 * @return void
	 */
	private function draw_grid( Overlay_Pdf_Canvas $canvas, int $page, array $size, int $step ): void {
		for ( $x = $step; $x < $size['width']; $x += $step ) {
			$gray = ( 0 === $x % self::MAJOR_STEP ) ? 0.72 : 0.9;
			$canvas->line( $page, $x, 0, $x, $size['height'], $gray );
		}

		for ( $y = $step; $y < $size['height']; $y += $step ) {
			$gray  = ( 0 === $y % self::MAJOR_STEP ) ? 0.72 : 0.9;
			$pdf_y = $size['height'] - $y;
			$canvas->line( $page, 0, $pdf_y, $size['width'], $pdf_y, $gray );
		}
	}

	/**
	 * Draw X (top) and Y (left) rulers with top-left coordinate labels.
	 *
	 * @param Overlay_Pdf_Canvas                 $canvas Canvas.
	 * @param int                                $page   Page index.
	 * @param array{width: float, height: float} $size   Page size.
	 * @return void
	 */
	private function draw_rulers( Overlay_Pdf_Canvas $canvas, int $page, array $size ): void {
		for ( $x = self::RULER_STEP; $x < $size['width']; $x += self::RULER_STEP ) {
			$canvas->text_rgb( $page, $x + 1.0, $size['height'] - 9.0, 5.0, (string) $x, 0.6, 0.6, 0.6 );
			$canvas->text_rgb( $page, $x + 1.0, 4.0, 5.0, (string) $x, 0.6, 0.6, 0.6 );
		}

		for ( $y = self::RULER_STEP; $y < $size['height']; $y += self::RULER_STEP ) {
			$pdf_y = $size['height'] - $y;
			$canvas->text_rgb( $page, 2.0, $pdf_y + 1.0, 5.0, (string) $y, 0.6, 0.6, 0.6 );
			$canvas->text_rgb( $page, $size['width'] - 16.0, $pdf_y + 1.0, 5.0, (string) $y, 0.6, 0.6, 0.6 );
		}
	}

	/**
	 * Draw the page dimensions and grid legend.
	 *
	 * @param Overlay_Pdf_Canvas                 $canvas    Canvas.
	 * @param int                                $page      Page index.
	 * @param array{width: float, height: float} $size      Page size.
	 * @param int                                $step      Grid step.
	 * @param bool                               $composite Whether the official PDF is behind.
	 * @return void
	 */
	private function draw_page_dimensions( Overlay_Pdf_Canvas $canvas, int $page, array $size, int $step, bool $composite ): void {
		$label = sprintf(
			'Page %s x %s pt  -  grid %d pt  -  origin top-left%s',
			$this->n( $size['width'] ),
			$this->n( $size['height'] ),
			$step,
			$composite ? '  -  official PDF background' : ''
		);

		$box_w = 320.0;
		$canvas->rect( $page, $size['width'] - $box_w - 6.0, $size['height'] - 20.0, $box_w, 14.0, 0.4 );
		$canvas->text_rgb( $page, $size['width'] - $box_w - 2.0, $size['height'] - 16.0, 7.0, $label, 0.0, 0.0, 0.8 );
	}

	/**
	 * Draw a single field: marker, bounding box, label and coordinates.
	 *
	 * @param Overlay_Pdf_Canvas                 $canvas Canvas.
	 * @param int                                $page   Page index.
	 * @param array{width: float, height: float} $size   Page size.
	 * @param array<string, mixed>               $field  Normalized field.
	 * @return void
	 */
	private function draw_field( Overlay_Pdf_Canvas $canvas, int $page, array $size, array $field ): void {
		$fx     = (float) $field['x'];
		$fy     = (float) $field['y'];
		$anchor = $size['height'] - $fy;

		$width  = (float) $field['max_width'] > 0 ? (float) $field['max_width'] : 150.0;
		$height = max( (float) $field['line_height'], (float) $field['font_size'] + 2.0 );
		$box_y  = $size['height'] - $fy - $height;

		// Bounding box.
		$canvas->rect( $page, $fx, $box_y, $width, $height, 0.55 );

		// Crosshair marker at the field anchor (dark, grayscale).
		$canvas->line( $page, $fx - 7.0, $anchor, $fx + 7.0, $anchor, 0.1 );
		$canvas->line( $page, $fx, $anchor - 7.0, $fx, $anchor + 7.0, 0.1 );
		$canvas->rect( $page, $fx - 1.5, $anchor - 1.5, 3.0, 3.0, 0.1 );

		// Label "[key]" above the box (red).
		$canvas->text_rgb( $page, $fx, $size['height'] - $fy + 4.0, 7.0, '[' . $field['key'] . ']', 0.85, 0.0, 0.0 );

		// Coordinate readout below the box (blue).
		$coord = sprintf(
			'x=%s y=%s p%d s%s%s',
			$this->n( $fx ),
			$this->n( $fy ),
			(int) $field['page'],
			$this->n( $field['font_size'] ),
			$field['multiline'] ? ' ML' : ''
		);
		$canvas->text_rgb( $page, $fx, $box_y - 8.0, 6.0, $coord, 0.0, 0.0, 0.85 );
	}

	/**
	 * Composite the rasterized official PDF as a per-page background.
	 *
	 * @param Overlay_Pdf_Canvas   $canvas   Canvas.
	 * @param array<string, mixed> $options  Options (template_path, dpi).
	 * @param string[]             $warnings Warnings (by reference).
	 * @return bool True when composited.
	 */
	private function apply_background( Overlay_Pdf_Canvas $canvas, array $options, array &$warnings ): bool {
		$template_path = (string) ( $options['template_path'] ?? '' );

		if ( '' === $template_path || ! is_readable( $template_path ) ) {
			$warnings[] = 'official template not found; grid drawn without background';
			return false;
		}

		$rasterizer = $this->rasterizer ?? new Pdf_Rasterizer( (int) ( $options['dpi'] ?? 150 ) );

		if ( ! $rasterizer->available() ) {
			$warnings[] = 'no rasterizer (pdftoppm) available; grid drawn without background';
			return false;
		}

		$pages = $rasterizer->to_jpeg_pages( $template_path );

		if ( array() === $pages ) {
			$warnings[] = 'rasterizer produced no pages; grid drawn without background';
			return false;
		}

		foreach ( $pages as $index => $jpeg ) {
			$canvas->set_background( $index, $jpeg );
		}

		return true;
	}

	/**
	 * Resolve the page size: layout -> official template -> Letter.
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
	 * Assemble the calibration report and optionally store the PDF.
	 *
	 * @param string                             $form_code Form code.
	 * @param array<string, mixed>               $layout    Layout.
	 * @param array{width: float, height: float} $size      Page size.
	 * @param int                                $step      Grid step.
	 * @param bool                               $composite Composited.
	 * @param array<int, array<string, mixed>>   $fields    Field summaries.
	 * @param string[]                           $warnings  Warnings.
	 * @param string                             $bytes     PDF bytes.
	 * @param array<string, mixed>               $options   Options.
	 * @return array<string, mixed>
	 */
	private function finalize(
		string $form_code,
		array $layout,
		array $size,
		int $step,
		bool $composite,
		array $fields,
		array $warnings,
		string $bytes,
		array $options
	): array {
		$checksum  = 'sha256:' . hash( 'sha256', $bytes );
		$file_path = '';
		$download  = '';

		if ( ( $options['store'] ?? true ) && $this->storage instanceof Pdf_Storage_Service ) {
			$filename  = (string) ( $options['filename'] ?? ( $form_code . '-grid.pdf' ) );
			$stored    = $this->storage->store( $bytes, $filename );
			$file_path = (string) $stored['file_path'];
			$download  = (string) $stored['download_url'];
			$checksum  = (string) $stored['checksum'];
		}

		return array(
			'form_code'    => $form_code,
			'template'     => (string) $layout['template'],
			'page_size'    => $size,
			'grid_step'    => $step,
			'composited'   => $composite,
			'field_count'  => count( $fields ),
			'fields'       => $fields,
			'file_path'    => $file_path,
			'download_url' => $download,
			'checksum'     => $checksum,
			'bytes'        => strlen( $bytes ),
			'warnings'     => $warnings,
			'pdf'          => $bytes,
		);
	}

	/**
	 * Format a number compactly.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	private function n( $value ): string {
		return rtrim( rtrim( number_format( (float) $value, 1, '.', '' ), '0' ), '.' );
	}
}
