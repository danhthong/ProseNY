<?php
/**
 * Tests for the Overlay Rendering foundation: layout loading, registry,
 * validation, the coordinate canvas, and the overlay renderer.
 *
 * Runs database-free.
 *
 * @package ProSeCore
 */

use PHPUnit\Framework\TestCase;
use ProSe\Core\Forms\Documents\Overlay\Coordinate_Map_Loader;
use ProSe\Core\Forms\Documents\Overlay\Form_Layout_Registry;
use ProSe\Core\Forms\Documents\Overlay\Layout_Validation_Service;
use ProSe\Core\Forms\Documents\Overlay\Overlay_Pdf_Canvas;
use ProSe\Core\Forms\Documents\Overlay\Overlay_Render_Result;
use ProSe\Core\Forms\Documents\Overlay\Overlay_Renderer;
use ProSe\Core\Forms\Documents\Overlay\Pdf_Layout_Debugger;
use ProSe\Core\Forms\Documents\Overlay\Pdf_Page_Geometry;
use ProSe\Core\Forms\Documents\Overlay\Pdf_Rasterizer;

/**
 * Class OverlayRenderingTest
 */
class OverlayRenderingTest extends TestCase {

	/**
	 * Layouts directory shipped with the plugin.
	 *
	 * @return string
	 */
	private function layouts_dir(): string {
		return PROSE_CORE_PATH . 'modules/forms/documents/overlay/layouts/';
	}

	/**
	 * The coordinate map loader applies defaults.
	 */
	public function test_loader_applies_defaults(): void {
		$loader = new Coordinate_Map_Loader();
		$json   = wp_json_encode(
			array(
				'form_code' => 'TST',
				'fields'    => array(
					array(
						'key' => 'name',
						'x'   => 10,
						'y'   => 20,
					),
					array(
						'key'       => 'note',
						'x'         => 30,
						'y'         => 40,
						'page'      => 2,
						'font_size' => 8,
						'multiline' => true,
						'max_width' => 200,
					),
				),
			)
		);

		$layout = $loader->load_string( (string) $json, 'test' );

		$this->assertSame( 'TST', $layout['form_code'] );
		$this->assertSame( 2, $layout['pages'] );
		$this->assertCount( 2, $layout['fields'] );

		$name = $layout['fields'][0];
		$this->assertSame( 'name', $name['key'] );
		$this->assertSame( 'name', $name['label'] );
		$this->assertSame( 'name', $name['source'] );
		$this->assertSame( 1, $name['page'] );
		$this->assertSame( Coordinate_Map_Loader::DEFAULT_FONT_SIZE, $name['font_size'] );
		$this->assertFalse( $name['multiline'] );
		$this->assertFalse( $name['checkbox'] );

		$note = $layout['fields'][1];
		$this->assertSame( 8.0, $note['font_size'] );
		$this->assertTrue( $note['multiline'] );
		$this->assertSame( 200.0, $note['max_width'] );
	}

	/**
	 * Invalid JSON raises.
	 */
	public function test_loader_rejects_invalid_json(): void {
		$this->expectException( \RuntimeException::class );
		( new Coordinate_Map_Loader() )->load_string( 'not json', 'bad' );
	}

	/**
	 * The registry resolves the shipped UD-1 layout.
	 */
	public function test_registry_loads_ud1(): void {
		$registry = new Form_Layout_Registry( $this->layouts_dir() );

		$this->assertTrue( $registry->has( 'UD-1' ) );
		$this->assertFalse( $registry->has( 'ZZ-9' ) );
		$this->assertContains( 'UD-1', $registry->codes() );

		$layout = $registry->load( 'UD-1' );
		$this->assertSame( 'UD-1', $layout['form_code'] );

		$keys = array_column( $layout['fields'], 'key' );
		$this->assertSame(
			array( 'county', 'plaintiff_name', 'defendant_name', 'grounds', 'ancillary_relief' ),
			$keys
		);
	}

	/**
	 * The shipped UD-1 layout validates cleanly.
	 */
	public function test_ud1_layout_is_valid(): void {
		$registry = new Form_Layout_Registry( $this->layouts_dir() );
		$layout   = $registry->load( 'UD-1' );

		$result = ( new Layout_Validation_Service() )->validate( $layout );

		$this->assertTrue( $result['valid'], implode( '; ', $result['errors'] ) );
		$this->assertEmpty( $result['errors'] );
	}

	/**
	 * Validation flags bad coordinates and missing attributes.
	 */
	public function test_validation_catches_errors(): void {
		// Hand-built (raw) layout to exercise the validation guards directly.
		$layout = array(
			'form_code' => 'BAD',
			'template'  => '',
			'pages'     => 1,
			'page_size' => array(
				'width'  => 612.0,
				'height' => 792.0,
			),
			'fields'    => array(
				array(
					'key'       => '',
					'page'      => 1,
					'x'         => 10.0,
					'y'         => 10.0,
					'font_size' => 10.0,
					'multiline' => false,
					'checkbox'  => false,
					'max_width' => 0.0,
				),
				array(
					'key'       => 'dup',
					'page'      => 1,
					'x'         => 10.0,
					'y'         => 10.0,
					'font_size' => 10.0,
					'multiline' => false,
					'checkbox'  => false,
					'max_width' => 0.0,
				),
				array(
					'key'       => 'dup',
					'page'      => 5,
					'x'         => 10.0,
					'y'         => 10.0,
					'font_size' => 0.0,
					'multiline' => false,
					'checkbox'  => false,
					'max_width' => 0.0,
				),
			),
		);

		$result = ( new Layout_Validation_Service() )->validate( $layout );

		$this->assertFalse( $result['valid'] );

		$joined = implode( ' | ', $result['errors'] );
		$this->assertStringContainsString( 'missing key', $joined );
		$this->assertStringContainsString( 'duplicate key', $joined );
		$this->assertStringContainsString( 'exceeds layout page count', $joined );
		$this->assertStringContainsString( 'font_size must be > 0', $joined );
	}

	/**
	 * The coordinate canvas emits a valid PDF of the requested size.
	 */
	public function test_canvas_emits_valid_pdf(): void {
		$canvas = new Overlay_Pdf_Canvas( 612.0, 792.0, 1 );
		$canvas->text( 0, 100.0, 700.0, 11.0, 'Hello (overlay)' );
		$canvas->rect( 0, 90.0, 690.0, 120.0, 16.0 );

		$bytes = $canvas->render();

		$this->assertStringStartsWith( '%PDF-1.4', $bytes );
		$this->assertStringEndsWith( '%%EOF', $bytes );
		$this->assertStringContainsString( '/MediaBox [0 0 612 792]', $bytes );
		$this->assertStringContainsString( ' re ', $bytes );
	}

	/**
	 * The renderer places values and reports counts.
	 */
	public function test_renderer_renders_values(): void {
		$registry = new Form_Layout_Registry( $this->layouts_dir() );
		$renderer = new Overlay_Renderer( $registry );

		$result = $renderer->render(
			'UD-1',
			array(
				'petitioner_name'  => 'Jane Doe',
				'respondent_name'  => 'John Doe',
				'county'           => 'New York',
				'grounds'          => 'DRL 170(7)',
				'relief_requested' => 'Equitable distribution and other relief.',
			)
		);

		$this->assertInstanceOf( Overlay_Render_Result::class, $result );
		$this->assertSame( Overlay_Render_Result::MODE_OVERLAY, $result->mode() );
		$this->assertSame( 5, $result->field_count() );
		$this->assertSame( 5, $result->rendered_count() );
		$this->assertSame( 0, $result->skipped_count() );
		$this->assertStringStartsWith( '%PDF-1.4', $result->pdf() );
		$this->assertStringContainsString( 'Jane Doe', $result->pdf() );
		$this->assertSame( 612.0, $result->page_size()['width'] );
	}

	/**
	 * Missing values are skipped, not rendered.
	 */
	public function test_renderer_skips_missing_values(): void {
		$registry = new Form_Layout_Registry( $this->layouts_dir() );
		$renderer = new Overlay_Renderer( $registry );

		$result = $renderer->render( 'UD-1', array( 'petitioner_name' => 'Jane Doe' ) );

		$this->assertSame( 1, $result->rendered_count() );
		$this->assertSame( 4, $result->skipped_count() );
	}

	/**
	 * The debug overlay renders boundaries for every field.
	 */
	public function test_debug_overlay(): void {
		$registry = new Form_Layout_Registry( $this->layouts_dir() );
		$renderer = new Overlay_Renderer( $registry );

		$result = $renderer->render_debug( 'UD-1', array( 'grid' => false ) );

		$this->assertSame( Overlay_Render_Result::MODE_DEBUG, $result->mode() );
		$this->assertSame( 5, $result->field_count() );
		$this->assertStringStartsWith( '%PDF-1.4', $result->pdf() );
		// Rectangles are drawn (re ... S operator present).
		$this->assertStringContainsString( ' re ', $result->pdf() );
	}

	/**
	 * Sample dummy data for the sprint.
	 *
	 * @return array<string, string>
	 */
	private function dummy_values(): array {
		return array(
			'petitioner_name'  => 'Jane Doe',
			'respondent_name'  => 'John Doe',
			'county'           => 'New York County',
			'grounds'          => 'DRL 170(7) - irretrievable breakdown',
			'relief_requested' => 'Restoration of maiden name',
		);
	}

	/**
	 * Resolve the official UD-1 template path, if present.
	 *
	 * @return string
	 */
	private function ud1_template(): string {
		$path = realpath( PROSE_CORE_PATH . '../../../wp-content/uploads/prose/forms/ud-1.pdf' );

		return false === $path ? '' : $path;
	}

	/**
	 * The filled render without a template falls back to an overlay layer.
	 */
	public function test_render_filled_without_template_falls_back(): void {
		$registry = new Form_Layout_Registry( $this->layouts_dir() );
		$renderer = new Overlay_Renderer( $registry );

		$result = $renderer->render_filled( 'UD-1', $this->dummy_values() );

		$this->assertSame( Overlay_Render_Result::MODE_OVERLAY, $result->mode() );
		$this->assertNotEmpty( $result->warnings() );
		$this->assertSame( 5, $result->rendered_count() );
		$this->assertSame( 0, $result->skipped_count() );
		$this->assertStringContainsString( 'Jane Doe', $result->pdf() );
		$this->assertGreaterThanOrEqual( 0, $result->render_duration_ms() );
	}

	/**
	 * The filled render composites the official PDF as a JPEG background when a
	 * rasterizer and the official template are available.
	 */
	public function test_render_filled_composites_official_pdf(): void {
		$template = $this->ud1_template();

		if ( '' === $template ) {
			$this->markTestSkipped( 'Official ud-1.pdf not available.' );
		}

		if ( ! ( new Pdf_Rasterizer() )->available() ) {
			$this->markTestSkipped( 'pdftoppm (Poppler) not available.' );
		}

		$registry = new Form_Layout_Registry( $this->layouts_dir() );
		$renderer = new Overlay_Renderer( $registry );

		$result = $renderer->render_filled(
			'UD-1',
			$this->dummy_values(),
			array(
				'template_path' => $template,
				'dpi'           => 150,
			)
		);

		$this->assertSame( Overlay_Render_Result::MODE_STAMPED, $result->mode() );
		$this->assertSame( 5, $result->rendered_count() );
		$this->assertSame( 0, $result->skipped_count() );
		$this->assertStringContainsString( '/DCTDecode', $result->pdf() );
		$this->assertStringContainsString( '/XObject', $result->pdf() );
		$this->assertStringContainsString( 'Jane Doe', $result->pdf() );
	}

	/**
	 * The layout debugger draws a grid, rulers, markers, labels, boxes and dims.
	 */
	public function test_layout_debugger_generates_grid(): void {
		$registry = new Form_Layout_Registry( $this->layouts_dir() );
		$debugger = new Pdf_Layout_Debugger( $registry );

		$report = $debugger->generate( 'UD-1', array( 'background' => false ) );

		$this->assertSame( 'UD-1', $report['form_code'] );
		$this->assertSame( 5, $report['field_count'] );
		$this->assertSame( Pdf_Layout_Debugger::GRID_STEP, $report['grid_step'] );
		$this->assertFalse( $report['composited'] );
		$this->assertCount( 5, $report['fields'] );

		$pdf = (string) $report['pdf'];
		$this->assertStringStartsWith( '%PDF-1.4', $pdf );
		$this->assertStringContainsString( ' re ', $pdf );          // bounding boxes / markers.
		$this->assertStringContainsString( ' l S', $pdf );          // grid / crosshair lines.
		$this->assertStringContainsString( '[county]', $pdf );      // field label.
		$this->assertStringContainsString( 'x=', $pdf );            // coordinate readout.
		$this->assertStringContainsString( 'Page', $pdf );          // page dimensions.
	}

	/**
	 * The grid step is configurable.
	 */
	public function test_layout_debugger_custom_grid_step(): void {
		$registry = new Form_Layout_Registry( $this->layouts_dir() );
		$debugger = new Pdf_Layout_Debugger( $registry );

		$report = $debugger->generate(
			'UD-1',
			array(
				'background' => false,
				'grid_step'  => 50,
			)
		);

		$this->assertSame( 50, $report['grid_step'] );
	}

	/**
	 * The debugger composites the official PDF when a rasterizer is available.
	 */
	public function test_layout_debugger_composites_official_pdf(): void {
		$template = $this->ud1_template();

		if ( '' === $template ) {
			$this->markTestSkipped( 'Official ud-1.pdf not available.' );
		}

		if ( ! ( new Pdf_Rasterizer() )->available() ) {
			$this->markTestSkipped( 'pdftoppm (Poppler) not available.' );
		}

		$registry = new Form_Layout_Registry( $this->layouts_dir() );
		$debugger = new Pdf_Layout_Debugger( $registry );

		$report = $debugger->generate(
			'UD-1',
			array(
				'template_path' => $template,
				'dpi'           => 150,
			)
		);

		$this->assertTrue( $report['composited'] );
		$this->assertStringContainsString( '/DCTDecode', (string) $report['pdf'] );
	}

	/**
	 * Page geometry defaults to US Letter.
	 */
	public function test_page_geometry_default(): void {
		$size = Pdf_Page_Geometry::default_size();

		$this->assertSame( 612.0, $size['width'] );
		$this->assertSame( 792.0, $size['height'] );
		$this->assertSame( $size, Pdf_Page_Geometry::size( '/nonexistent/file.pdf' ) );
	}
}
