<?php
/**
 * Tests for the Court Filing Packet Engine: form fill, filing order bundling,
 * PDF merge, manifest, packet storage and audit metadata.
 *
 * Runs database-free against the Document Generation Engine outputs.
 *
 * @package ProSeCore
 */

use PHPUnit\Framework\TestCase;
use ProSe\Core\Forms\Classification\Vocabulary;
use ProSe\Core\Forms\Documents\Document_Generation_Service;
use ProSe\Core\Forms\Documents\Filing\Court_Pdf_Fill_Service;
use ProSe\Core\Forms\Documents\Filing\Filing_Packet;
use ProSe\Core\Forms\Documents\Filing\Filing_Packet_Service;
use ProSe\Core\Forms\Documents\Filing\Package_Pdf_Bundler;
use ProSe\Core\Forms\Documents\Filing\Pdf_Merge_Service;
use ProSe\Core\Forms\Documents\Pdf\Pdf_Document_Writer;
use ProSe\Core\Forms\Documents\Pdf\Pdf_Storage_Service;
use ProSe\Core\Forms\Engine\Case_Catalog;
use ProSe\Core\Forms\Engine\Case_Service;
use ProSe\Core\Forms\Engine\Case_State;

/**
 * Class FilingPacketEngineTest
 */
class FilingPacketEngineTest extends TestCase {

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
			prose_test_remove_tree( $dir );
		}

		$this->temp_dirs = array();
		parent::tearDown();
	}

	/**
	 * A unique temporary storage service.
	 *
	 * @return Pdf_Storage_Service
	 */
	private function temp_storage(): Pdf_Storage_Service {
		$dir               = prose_test_temp_dir( 'prose-packet' );
		$this->temp_dirs[] = $dir;

		return new Pdf_Storage_Service( $dir, 'https://example.test/docs' );
	}

	/**
	 * Uncontested divorce (no children) case with service recorded.
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
		$state->set_case_id( 7001 );
		$state->set_county( Vocabulary::COUNTY_NEW_YORK );
		$state->set_court_routing( Vocabulary::ROUTE_SUPREME_COURT );
		$service->record_event( $state, Case_Catalog::EVENT_SERVICE_COMPLETED, array( 'date' => '2026-02-01 00:00:00' ) );

		return $state;
	}

	/**
	 * Custody case.
	 *
	 * @return Case_State
	 */
	private function custody_case(): Case_State {
		$service = new Case_Service();
		$state   = $service->create_case(
			Vocabulary::WF_CUSTODY,
			array(
				'petitioner_name'  => 'Maria Cruz',
				'respondent_name'  => 'Luis Cruz',
				'children_count'   => 2,
				'relief_requested' => 'Sole legal and physical custody',
			)
		);
		$state->set_case_id( 7002 );
		$state->set_county( Vocabulary::COUNTY_KINGS );
		$state->set_court_routing( Vocabulary::ROUTE_FAMILY_COURT );

		return $state;
	}

	/**
	 * The fill service fills a single form into a valid PDF descriptor.
	 */
	public function test_single_form_fill(): void {
		$gen      = new Document_Generation_Service();
		$filler   = new Court_Pdf_Fill_Service();
		$document = $gen->generate_form( $this->uncontested_case(), 'UD-1', Vocabulary::PKG_UNCONTESTED_NO_CHILDREN );

		$filled = $filler->fill( $document );

		$this->assertSame( 'UD-1', $filled['form_code'] );
		$this->assertContains( $filled['strategy'], array( Court_Pdf_Fill_Service::STRATEGY_ACROFORM, Court_Pdf_Fill_Service::STRATEGY_BUILTIN ) );
		$this->assertGreaterThan( 0, $filled['field_count'] );
		$this->assertStringStartsWith( '%PDF-1.4', (string) $filled['bytes'] );
		$this->assertStringContainsString( 'PETITIONER_NAME', (string) $filled['bytes'] );
		$this->assertGreaterThanOrEqual( 1, $filled['page_count'] );
	}

	/**
	 * The bundler orders forms by package catalog filing order.
	 */
	public function test_bundler_filing_order(): void {
		$gen     = new Document_Generation_Service();
		$bundle  = $gen->generate_package( $this->uncontested_case(), Vocabulary::PKG_UNCONTESTED_NO_CHILDREN );
		$bundler = new Package_Pdf_Bundler();

		$this->assertSame(
			array( 'UD-1', 'UD-2', 'UD-3', 'UD-4', 'UD-6', 'UD-7' ),
			$bundler->filing_order( $bundle )
		);

		$bundled = $bundler->bundle( $bundle );
		$this->assertCount( 6, $bundled['forms'] );
		$this->assertSame( 'UD-1', $bundled['forms'][0]['form_code'] );
		$this->assertSame( 'UD-7', $bundled['forms'][5]['form_code'] );
	}

	/**
	 * The merge service composes forms into one multi-page PDF, one page each.
	 */
	public function test_merge_produces_single_multipage_pdf(): void {
		$gen     = new Document_Generation_Service();
		$bundle  = $gen->generate_package( $this->uncontested_case(), Vocabulary::PKG_UNCONTESTED_NO_CHILDREN );
		$bundler = new Package_Pdf_Bundler();
		$merge   = new Pdf_Merge_Service();

		$bundled = $bundler->bundle( $bundle );
		$merged  = $merge->merge( $bundled['forms'] );

		$this->assertStringStartsWith( '%PDF-1.4', (string) $merged['bytes'] );
		$this->assertStringEndsWith( '%%EOF', (string) $merged['bytes'] );
		$this->assertSame( 6, $merged['page_count'] );
		$this->assertSame( Pdf_Merge_Service::STRATEGY_BUILTIN, $merged['strategy'] );
		$this->assertSame( 6, Pdf_Document_Writer::count_pages( (string) $merged['bytes'] ) );
	}

	/**
	 * The service produces a stored packet with manifest and audit metadata.
	 */
	public function test_filing_packet_generation(): void {
		$gen     = new Document_Generation_Service();
		$service = new Filing_Packet_Service( $gen, null, null, $this->temp_storage() );

		$packet = $service->generate(
			$this->uncontested_case(),
			Vocabulary::PKG_UNCONTESTED_NO_CHILDREN,
			array( 'filename' => 'packet.pdf' )
		);

		$this->assertInstanceOf( Filing_Packet::class, $packet );
		$this->assertTrue( $packet->is_success() );
		$this->assertSame( Vocabulary::PKG_UNCONTESTED_NO_CHILDREN, $packet->package_key() );
		$this->assertSame( array( 'UD-1', 'UD-2', 'UD-3', 'UD-4', 'UD-6', 'UD-7' ), $packet->forms() );
		$this->assertSame( 6, $packet->page_count() );

		// Packet PDF is stored and valid.
		$this->assertFileExists( $packet->file_path() );
		$bytes = (string) file_get_contents( $packet->file_path() );
		$this->assertStringStartsWith( '%PDF-1.4', $bytes );
		$this->assertStringStartsWith( 'sha256:', $packet->checksum() );

		// Manifest matches the catalog filing order and page count.
		$manifest = $packet->manifest();
		$this->assertSame( Vocabulary::PKG_UNCONTESTED_NO_CHILDREN, $manifest['package_key'] );
		$this->assertSame( array( 'UD-1', 'UD-2', 'UD-3', 'UD-4', 'UD-6', 'UD-7' ), $manifest['forms'] );
		$this->assertSame( 6, $manifest['page_count'] );
		$this->assertCount( 6, $manifest['forms_detail'] );

		// Manifest is stored on disk as JSON.
		$this->assertFileExists( $packet->manifest_path() );
		$decoded = json_decode( (string) file_get_contents( $packet->manifest_path() ), true );
		$this->assertSame( 6, $decoded['page_count'] );

		// Audit metadata carried from the bundle.
		$audit = $packet->audit();
		$this->assertSame( 7001, (int) $audit['source_case_id'] );
		$this->assertSame( Vocabulary::PKG_UNCONTESTED_NO_CHILDREN, (string) $audit['source_package_id'] );
	}

	/**
	 * A custody packet merges the family-court forms in filing order.
	 */
	public function test_custody_packet(): void {
		$gen     = new Document_Generation_Service();
		$service = new Filing_Packet_Service( $gen, null, null, $this->temp_storage() );

		$packet = $service->generate( $this->custody_case(), Vocabulary::PKG_CUSTODY_PETITION );

		$this->assertSame( array( 'FC-1', 'FC-3' ), $packet->forms() );
		$this->assertSame( 2, $packet->page_count() );
		$this->assertSame( 0, $packet->missing_count() );
		$this->assertFileExists( $packet->file_path() );
	}

	/**
	 * Generating without storing yields bytes/checksum but no files.
	 */
	public function test_generate_without_storing(): void {
		$gen     = new Document_Generation_Service();
		$service = new Filing_Packet_Service( $gen, null, null, $this->temp_storage() );

		$packet = $service->generate(
			$this->uncontested_case(),
			Vocabulary::PKG_UNCONTESTED_NO_CHILDREN,
			array( 'store' => false )
		);

		$this->assertSame( '', $packet->file_path() );
		$this->assertSame( '', $packet->manifest_path() );
		$this->assertGreaterThan( 0, $packet->bytes() );
		$this->assertStringStartsWith( 'sha256:', $packet->checksum() );
	}

	/**
	 * Unknown packages produce an empty (non-crashing) packet.
	 */
	public function test_unknown_package_is_empty(): void {
		$gen     = new Document_Generation_Service();
		$service = new Filing_Packet_Service( $gen, null, null, $this->temp_storage() );

		$packet = $service->generate( $this->uncontested_case(), 'PKG_DOES_NOT_EXIST' );

		$this->assertInstanceOf( Filing_Packet::class, $packet );
		$this->assertSame( array(), $packet->forms() );
		$this->assertStringStartsWith( '%PDF-1.4', (string) file_get_contents( $packet->file_path() ) );
	}
}
