<?php
/**
 * Tests for the PDF Renderer: field mapping, template lookup, PDF generation,
 * bundle generation, and storage metadata.
 *
 * Runs database-free against the Document Generation Engine outputs.
 *
 * @package ProSeCore
 */

use PHPUnit\Framework\TestCase;
use ProSe\Core\Forms\Classification\Vocabulary;
use ProSe\Core\Forms\Documents\Document_Generation_Service;
use ProSe\Core\Forms\Documents\Pdf\Pdf_Document_Writer;
use ProSe\Core\Forms\Documents\Pdf\Pdf_Field_Mapper;
use ProSe\Core\Forms\Documents\Pdf\Pdf_Render_Result;
use ProSe\Core\Forms\Documents\Pdf\Pdf_Renderer;
use ProSe\Core\Forms\Documents\Pdf\Pdf_Storage_Service;
use ProSe\Core\Forms\Documents\Pdf\Pdf_Template_Registry;
use ProSe\Core\Forms\Engine\Case_Catalog;
use ProSe\Core\Forms\Engine\Case_Service;
use ProSe\Core\Forms\Engine\Case_State;

/**
 * Class PdfRendererTest
 */
class PdfRendererTest extends TestCase {

	/**
	 * Temporary storage directories created during a test.
	 *
	 * @var string[]
	 */
	private array $temp_dirs = array();

	/**
	 * Remove any temporary artifacts.
	 */
	protected function tearDown(): void {
		foreach ( $this->temp_dirs as $dir ) {
			$this->remove_tree( $dir );
		}

		$this->temp_dirs = array();
		parent::tearDown();
	}

	/**
	 * Recursively remove a directory tree.
	 *
	 * @param string $dir Directory.
	 * @return void
	 */
	private function remove_tree( string $dir ): void {
		if ( ! is_dir( $dir ) ) {
			return;
		}

		foreach ( (array) glob( $dir . '/*' ) as $path ) {
			if ( is_dir( $path ) ) {
				$this->remove_tree( $path );
			} else {
				unlink( $path );
			}
		}

		rmdir( $dir );
	}

	/**
	 * A unique temporary storage service.
	 *
	 * @return Pdf_Storage_Service
	 */
	private function temp_storage(): Pdf_Storage_Service {
		$dir               = sys_get_temp_dir() . '/prose-pdf-' . uniqid( '', true );
		$this->temp_dirs[] = $dir;

		return new Pdf_Storage_Service( $dir, 'https://example.test/docs' );
	}

	/**
	 * Uncontested divorce case with service recorded.
	 *
	 * @return Case_State
	 */
	private function uncontested_case(): Case_State {
		$service = new Case_Service();
		$state   = $service->create_case(
			Vocabulary::WF_UNCONTESTED_DIVORCE,
			array(
				'petitioner_name' => 'Jane Doe',
				'respondent_name' => 'John Doe',
				'marriage_date'   => '2010-06-01',
				'children'        => false,
			)
		);
		$state->set_case_id( 5001 );
		$state->set_county( Vocabulary::COUNTY_NEW_YORK );
		$state->set_court_routing( Vocabulary::ROUTE_SUPREME_COURT );
		$service->record_event( $state, Case_Catalog::EVENT_SERVICE_COMPLETED, array( 'date' => '2026-02-01 00:00:00' ) );

		return $state;
	}

	/**
	 * Field mapping: canonical keys map to PDF names and typed values.
	 */
	public function test_field_mapping(): void {
		$mapper = new Pdf_Field_Mapper();

		$this->assertSame( 'PETITIONER_NAME', $mapper->pdf_field_name( 'petitioner_name' ) );
		$this->assertSame( 'RESPONDENT_NAME', $mapper->pdf_field_name( 'respondent_name' ) );
		$this->assertSame( 'COUNTY', $mapper->pdf_field_name( 'county' ) );
		$this->assertSame( 'UNMAPPED_KEY', $mapper->pdf_field_name( 'unmapped_key' ) );

		$this->assertSame( Pdf_Field_Mapper::TYPE_TEXT, $mapper->field_type( 'petitioner_name' ) );
		$this->assertSame( Pdf_Field_Mapper::TYPE_DATE, $mapper->field_type( 'marriage_date' ) );
		$this->assertSame( Pdf_Field_Mapper::TYPE_CHECKBOX, $mapper->field_type( 'has_children' ) );
		$this->assertSame( Pdf_Field_Mapper::TYPE_RADIO, $mapper->field_type( 'grounds' ) );
		$this->assertSame( Pdf_Field_Mapper::TYPE_MULTILINE, $mapper->field_type( 'relief_requested' ) );

		$this->assertSame( '[X]', $mapper->format_value( true, Pdf_Field_Mapper::TYPE_CHECKBOX ) );
		$this->assertSame( '[ ]', $mapper->format_value( false, Pdf_Field_Mapper::TYPE_CHECKBOX ) );
		$this->assertSame( '2010-06-01', $mapper->format_value( '2010-06-01 00:00:00', Pdf_Field_Mapper::TYPE_DATE ) );
		$this->assertSame( '', $mapper->format_value( null, Pdf_Field_Mapper::TYPE_TEXT, false ) );
	}

	/**
	 * Template lookup: known forms resolve; unknown forms fall back.
	 */
	public function test_template_lookup(): void {
		$registry = new Pdf_Template_Registry();

		$this->assertTrue( $registry->has( 'UD-1' ) );
		$this->assertTrue( $registry->has( 'FC-7' ) );
		$this->assertFalse( $registry->has( 'ZZ-9' ) );

		$ud1 = $registry->resolve( 'UD-1' );
		$this->assertSame( 'UD-1', $ud1['form_code'] );
		$this->assertSame( '1.0', $ud1['template_version'] );
		$this->assertSame( Pdf_Template_Registry::RENDERER_BUILTIN, $ud1['renderer_type'] );
		$this->assertStringEndsWith( 'UD-1.pdf', $ud1['template_path'] );

		$unknown = $registry->resolve( 'ZZ-9' );
		$this->assertSame( '0.0', $unknown['template_version'] );
	}

	/**
	 * The low-level writer emits a structurally valid PDF.
	 */
	public function test_writer_emits_valid_pdf(): void {
		$writer = new Pdf_Document_Writer();
		$bytes  = $writer->build( array( array( 'Hello', 'World (test)' ) ) );

		$this->assertStringStartsWith( '%PDF-1.4', $bytes );
		$this->assertStringContainsString( 'startxref', $bytes );
		$this->assertStringEndsWith( '%%EOF', $bytes );
	}

	/**
	 * Single form renders to a PDF with correct counts and metadata.
	 */
	public function test_single_form_render(): void {
		$gen      = new Document_Generation_Service();
		$renderer = new Pdf_Renderer( null, null, $this->temp_storage() );

		$ud1    = $gen->generate_form( $this->uncontested_case(), 'UD-1', Vocabulary::PKG_UNCONTESTED_NO_CHILDREN );
		$result = $renderer->render_document( $ud1, array( 'filename' => 'ud1.pdf' ) );

		$this->assertInstanceOf( Pdf_Render_Result::class, $result );
		$this->assertTrue( $result->is_success() );
		$this->assertSame( Pdf_Render_Result::SCOPE_FORM, $result->scope() );
		$this->assertSame( 'pdf', $result->format() );
		$this->assertSame( 'UD-1', $result->template() );
		$this->assertSame( '1.0', $result->template_version() );

		$this->assertSame( 4, $result->field_count() );
		$this->assertSame( 4, $result->resolved_count() );
		$this->assertSame( 0, $result->missing_count() );

		$this->assertGreaterThan( 0, $result->bytes() );
		$this->assertStringStartsWith( 'sha256:', $result->checksum() );

		// The artifact exists on disk and is a real PDF.
		$this->assertFileExists( $result->file_path() );
		$bytes = (string) file_get_contents( $result->file_path() );
		$this->assertStringStartsWith( '%PDF-1.4', $bytes );
		$this->assertStringContainsString( 'PETITIONER_NAME', $bytes );
	}

	/**
	 * Storage metadata: file path, download url and checksum are reported.
	 */
	public function test_storage_metadata(): void {
		$storage = $this->temp_storage();
		$bytes   = "%PDF-1.4\n% sample\n%%EOF";

		$meta = $storage->store( $bytes, 'sample/doc.pdf' );

		$this->assertFileExists( $meta['file_path'] );
		$this->assertStringEndsWith( 'sample/doc.pdf', $meta['file_path'] );
		$this->assertSame( 'https://example.test/docs/sample/doc.pdf', $meta['download_url'] );
		$this->assertSame( 'sha256:' . hash( 'sha256', $bytes ), $meta['checksum'] );
		$this->assertSame( strlen( $bytes ), $meta['bytes'] );
		$this->assertTrue( $meta['stored'] );
		$this->assertSame( $bytes, (string) file_get_contents( $meta['file_path'] ) );
	}

	/**
	 * Package bundle renders to a single combined PDF.
	 */
	public function test_package_bundle_render(): void {
		$gen      = new Document_Generation_Service();
		$renderer = new Pdf_Renderer( null, null, $this->temp_storage() );

		$bundle = $gen->generate_package( $this->uncontested_case(), Vocabulary::PKG_UNCONTESTED_NO_CHILDREN );
		$result = $renderer->render_package( $bundle, array( 'filename' => 'pkg.pdf' ) );

		$this->assertSame( Pdf_Render_Result::SCOPE_PACKAGE, $result->scope() );
		$this->assertSame( Vocabulary::PKG_UNCONTESTED_NO_CHILDREN, $result->package_key() );
		$this->assertGreaterThan( 0, $result->field_count() );
		$this->assertLessThanOrEqual( $result->field_count(), $result->resolved_count() );
		// A fully generated package has no missing *required* fields.
		$this->assertSame( 0, $result->missing_count() );

		$this->assertFileExists( $result->file_path() );
		$bytes = (string) file_get_contents( $result->file_path() );
		$this->assertStringStartsWith( '%PDF-1.4', $bytes );
		// The combined PDF contains the package cover and per-form fields.
		$this->assertStringContainsString( Vocabulary::PKG_UNCONTESTED_NO_CHILDREN, $bytes );
		$this->assertStringContainsString( 'PETITIONER_NAME', $bytes );
		// Multiple pages (cover + forms).
		$this->assertGreaterThan( 1, substr_count( $bytes, '/Type /Page ' ) );
	}

	/**
	 * Audit metadata is carried into the render result.
	 */
	public function test_audit_metadata_stored(): void {
		$gen      = new Document_Generation_Service();
		$renderer = new Pdf_Renderer( null, null, $this->temp_storage() );

		$bundle = $gen->generate_package( $this->uncontested_case(), Vocabulary::PKG_UNCONTESTED_NO_CHILDREN );
		$result = $renderer->render_package( $bundle, array( 'filename' => 'pkg.pdf' ) );
		$audit  = $result->audit();

		$this->assertSame( 5001, $audit['source_case_id'] );
		$this->assertSame( Vocabulary::PKG_UNCONTESTED_NO_CHILDREN, $audit['source_package_id'] );
		$this->assertArrayHasKey( 'generated_at', $audit );
		$this->assertArrayHasKey( 'template_version', $audit );
	}

	/**
	 * The renderer satisfies the generic document provider abstraction.
	 */
	public function test_document_provider_contract(): void {
		$gen      = new Document_Generation_Service();
		$renderer = new Pdf_Renderer( null, null, $this->temp_storage() );

		$this->assertSame( 'pdf', $renderer->get_id() );
		$this->assertSame( 'pdf', $renderer->format() );
		$this->assertTrue( $renderer->is_available() );
		$this->assertTrue( $renderer->supports_form( 'UD-1' ) );

		$ud1 = $gen->generate_form( $this->uncontested_case(), 'UD-1', Vocabulary::PKG_UNCONTESTED_NO_CHILDREN );
		$this->assertTrue( $renderer->supports( $ud1 ) );

		$array = $renderer->render( $ud1, array( 'store' => false ) );
		$this->assertIsArray( $array );
		$this->assertArrayHasKey( 'checksum', $array );
		$this->assertArrayHasKey( 'field_count', $array );
		$this->assertSame( '', $array['file_path'] );
	}
}
