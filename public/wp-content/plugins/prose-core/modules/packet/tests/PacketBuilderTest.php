<?php
/**
 * Packet Builder tests.
 *
 * @package ProSeCore
 */

use PHPUnit\Framework\TestCase;
use ProSe\Core\Assembly\Package_Loader;
use ProSe\Core\Forms\Classification\Vocabulary;
use ProSe\Core\Packet\Packet_Manifest;
use ProSe\Core\Packet\Packet_Service;
use ProSe\Core\Packet\Packet_Store;
use ProSe\Core\Packet\Pdf_Merger;
use ProSe\Core\Packet\Pdf_Packet_Builder;
use ProSe\Core\Packet\Pdf_Parser;
use ProSe\Core\Packet\Pdf_Resolver;
use ProSe\Core\Packet\Pdf_Validator;
use ProSe\Core\Procedural\Procedural_Navigator;

/**
 * Class PacketBuilderTest
 */
class PacketBuilderTest extends TestCase {

	private const PACKAGE_ID = 'PKG_TEST_PACKET';

	/**
	 * Temp storage directory.
	 *
	 * @var string
	 */
	private string $temp_dir;

	/**
	 * Temp PDF directory.
	 *
	 * @var string
	 */
	private string $pdf_dir;

	/**
	 * Set up.
	 */
	protected function setUp(): void {
		$this->temp_dir = prose_test_temp_dir( 'prose-packet-test' );
		$this->pdf_dir  = $this->temp_dir . DIRECTORY_SEPARATOR . 'sources';
		mkdir( $this->pdf_dir, 0777, true );
	}

	/**
	 * Tear down.
	 */
	protected function tearDown(): void {
		prose_test_remove_tree( $this->temp_dir );
	}

	/**
	 * Valid packet generation produces cached PDF and ZIP artifacts.
	 */
	public function test_valid_packet_generation(): void {
		$paths    = $this->create_fixture_pdfs( array( 'UD-1', 'UD-2' ) );
		$service  = $this->make_service( $paths );
		$result   = $service->build(
			self::PACKAGE_ID,
			array(
				'force' => true,
			)
		);

		$this->assertTrue( $result['success'] );
		$this->assertSame( self::PACKAGE_ID, $result['packet']['package_id'] );
		$this->assertSame( 2, $result['packet']['form_count'] );
		$this->assertNotSame( '', $result['packet']['pdf_packet_url'] );
		$this->assertNotSame( '', $result['packet']['zip_packet_url'] );
		$this->assertFileExists( $this->temp_dir . '/pdf/' . self::PACKAGE_ID . '.pdf' );
		$this->assertFileExists( $this->temp_dir . '/zip/' . self::PACKAGE_ID . '.zip' );
	}

	/**
	 * Missing source PDF returns structured error.
	 */
	public function test_missing_pdf(): void {
		$paths   = $this->create_fixture_pdfs( array( 'UD-1' ) );
		$paths['UD-2'] = '';
		$service = $this->make_service( $paths );
		$result  = $service->build( self::PACKAGE_ID, array( 'force' => true ) );

		$this->assertFalse( $result['success'] );
		$this->assertSame( Pdf_Validator::CODE_PDF_MISSING, $result['error']['code'] );
	}

	/**
	 * Missing package returns structured error.
	 */
	public function test_missing_package(): void {
		$service = $this->make_service( array() );
		$result  = $service->build( 'PKG_DOES_NOT_EXIST', array( 'force' => true ) );

		$this->assertFalse( $result['success'] );
		$this->assertSame( Pdf_Validator::CODE_PACKAGE_NOT_FOUND, $result['error']['code'] );
	}

	/**
	 * Empty package returns empty_packet error.
	 */
	public function test_empty_package(): void {
		$loader  = new Fixture_Package_Loader(
			array(
				'package_id'   => self::PACKAGE_ID,
				'package_name' => 'Test Packet',
				'court'        => 'supreme_court',
				'forms'        => array(),
			)
		);
		$service = $this->make_service( array(), $loader );
		$result  = $service->build( self::PACKAGE_ID, array( 'force' => true ) );

		$this->assertFalse( $result['success'] );
		$this->assertSame( Pdf_Validator::CODE_EMPTY_PACKET, $result['error']['code'] );
	}

	/**
	 * Corrupted PDF is rejected.
	 */
	public function test_corrupted_pdf(): void {
		$bad = $this->pdf_dir . '/bad.pdf';
		file_put_contents( $bad, 'not-a-pdf' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

		$service = $this->make_service(
			array(
				'UD-1' => $bad,
			)
		);
		$result  = $service->build( self::PACKAGE_ID, array( 'force' => true ) );

		$this->assertFalse( $result['success'] );
		$this->assertSame( Pdf_Validator::CODE_PDF_CORRUPTED, $result['error']['code'] );
	}

	/**
	 * Cached packets are reused when fingerprint is unchanged.
	 */
	public function test_packet_caching(): void {
		$paths   = $this->create_fixture_pdfs( array( 'UD-1', 'UD-2' ) );
		$service = $this->make_service( $paths );

		$first = $service->build( self::PACKAGE_ID, array( 'force' => true ) );
		$this->assertTrue( $first['success'] );

		$pdf_mtime = filemtime( $this->temp_dir . '/pdf/' . self::PACKAGE_ID . '.pdf' );

		sleep( 1 );

		$second = $service->build( self::PACKAGE_ID );

		$this->assertTrue( $second['success'] );
		$this->assertSame(
			$pdf_mtime,
			filemtime( $this->temp_dir . '/pdf/' . self::PACKAGE_ID . '.pdf' ),
			'Cached PDF should not be regenerated when fingerprint is unchanged.'
		);
	}

	/**
	 * Fingerprint change triggers regeneration.
	 */
	public function test_cache_invalidates_on_source_change(): void {
		$paths   = $this->create_fixture_pdfs( array( 'UD-1' ) );
		$service = $this->make_service( $paths );
		$service->build( self::PACKAGE_ID, array( 'force' => true ) );

		$first_mtime = filemtime( $this->temp_dir . '/pdf/' . self::PACKAGE_ID . '.pdf' );

		sleep( 1 );
		touch( $paths['UD-1'] );

		$paths['UD-2'] = $this->make_pdf( 'UD-2' );
		$service       = $this->make_service( $paths );
		$service->build( self::PACKAGE_ID, array( 'force' => true ) );

		$this->assertNotSame(
			$first_mtime,
			filemtime( $this->temp_dir . '/pdf/' . self::PACKAGE_ID . '.pdf' )
		);
	}

	/**
	 * ZIP packet contains individual blank PDFs.
	 */
	public function test_zip_contains_individual_pdfs(): void {
		$paths   = $this->create_fixture_pdfs( array( 'UD-1', 'UD-2' ) );
		$service = $this->make_service( $paths );
		$result  = $service->build(
			self::PACKAGE_ID,
			array(
				'force'     => true,
				'build_pdf' => false,
				'build_zip' => true,
			)
		);

		$this->assertTrue( $result['success'] );

		if ( ! class_exists( '\ZipArchive' ) ) {
			$this->markTestSkipped( 'ZipArchive not available.' );
		}

		$zip = new ZipArchive();
		$this->assertTrue( $zip->open( $this->temp_dir . '/zip/' . self::PACKAGE_ID . '.zip' ) );
		$this->assertNotFalse( $zip->locateName( 'UD-1.pdf' ) );
		$this->assertNotFalse( $zip->locateName( 'UD-2.pdf' ) );
		$zip->close();
	}

	/**
	 * Availability check is read-only and does not generate artifacts.
	 */
	public function test_availability_does_not_generate(): void {
		$paths   = $this->create_fixture_pdfs( array( 'UD-1' ) );
		$service = $this->make_service( $paths );

		$this->assertFalse( $service->is_available( self::PACKAGE_ID ) );
		$this->assertFileDoesNotExist( $this->temp_dir . '/pdf/' . self::PACKAGE_ID . '.pdf' );

		$service->build( self::PACKAGE_ID, array( 'force' => true ) );
		$this->assertTrue( $service->is_available( self::PACKAGE_ID ) );
	}

	/**
	 * Navigator exposes read-only packet availability.
	 */
	public function test_navigator_packet_availability(): void {
		$paths   = $this->create_fixture_pdfs( array( 'UD-1', 'UD-2' ) );
		$service = $this->make_service( $paths );
		$service->build( self::PACKAGE_ID, array( 'force' => true ) );

		$navigator = new Procedural_Navigator();
		$result    = $navigator->navigate(
			array(
				'issue'  => 'divorce',
				'facts'  => array(
					'children'      => true,
					'spouse_agrees' => true,
				),
				'county' => 'Kings',
			)
		);

		$this->assertTrue( $result['success'] );
		$this->assertArrayHasKey( 'packet', $result['navigation'] );
		$this->assertSame( 'PKG_UNCONTESTED_WITH_CHILDREN', $result['navigation']['packet']['package_id'] );
		$this->assertFalse( $result['navigation']['packet']['available'] );
	}

	/**
	 * Pure-PHP merger combines real single-page PDFs into a multi-page packet.
	 */
	public function test_pure_php_merger_combines_pages(): void {
		$a = $this->pdf_dir . '/a.pdf';
		$b = $this->pdf_dir . '/b.pdf';
		file_put_contents( $a, $this->valid_single_page_pdf( 'Form A' ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents( $b, $this->valid_single_page_pdf( 'Form B' ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

		$merged = ( new Pdf_Merger() )->merge( array( $a, $b ) );

		$this->assertNotNull( $merged );
		$this->assertSame( '%PDF-', substr( (string) $merged, 0, 5 ) );
		$this->assertNotFalse( strpos( (string) $merged, '%%EOF' ) );

		$parser = new Pdf_Parser( (string) $merged );
		$this->assertCount( 2, $parser->get_page_numbers() );
	}

	/**
	 * Merger fails gracefully on unreadable input.
	 */
	public function test_pure_php_merger_returns_null_on_bad_input(): void {
		$this->assertNull( ( new Pdf_Merger() )->merge( array( '/no/such/file.pdf' ) ) );
		$this->assertNull( ( new Pdf_Merger() )->merge( array() ) );
	}

	/**
	 * Build a minimal but valid single-page PDF with a classic xref table.
	 *
	 * @param string $text Page text.
	 * @return string
	 */
	private function valid_single_page_pdf( string $text ): string {
		$objs       = array();
		$objs[1]    = '<</Type/Catalog/Pages 2 0 R>>';
		$objs[2]    = '<</Type/Pages/Kids[3 0 R]/Count 1>>';
		$objs[3]    = '<</Type/Page/Parent 2 0 R/MediaBox[0 0 612 792]/Resources<</Font<</F1 5 0 R>>>>/Contents 4 0 R>>';
		$stream     = 'BT /F1 24 Tf 72 720 Td (' . $text . ') Tj ET';
		$objs[4]    = '<</Length ' . strlen( $stream ) . ">>\nstream\n" . $stream . "\nendstream";
		$objs[5]    = '<</Type/Font/Subtype/Type1/BaseFont/Helvetica>>';

		$pdf     = "%PDF-1.4\n";
		$offsets = array();

		for ( $i = 1; $i <= 5; $i++ ) {
			$offsets[ $i ] = strlen( $pdf );
			$pdf          .= $i . " 0 obj\n" . $objs[ $i ] . "\nendobj\n";
		}

		$xref = strlen( $pdf );
		$pdf .= "xref\n0 6\n0000000000 65535 f \n";

		for ( $i = 1; $i <= 5; $i++ ) {
			$pdf .= sprintf( "%010d 00000 n \n", $offsets[ $i ] );
		}

		$pdf .= "trailer\n<</Size 6/Root 1 0 R>>\nstartxref\n" . $xref . "\n%%EOF\n";

		return $pdf;
	}

	/**
	 * Build a packet service with fixture dependencies.
	 *
	 * @param array<string, string>   $paths  Form id => pdf path map.
	 * @param Package_Loader|null     $loader Optional package loader.
	 * @return Packet_Service
	 */
	private function make_service( array $paths, ?Package_Loader $loader = null ): Packet_Service {
		$store = new Packet_Store( $this->temp_dir, 'http://example.test/packets/' );
		$loader = $loader ?? new Fixture_Package_Loader(
			array(
				'package_id'   => self::PACKAGE_ID,
				'package_name' => 'Test Packet',
				'court'        => 'supreme_court',
				'forms'        => array_map(
					static function ( string $form_id ): array {
						return array(
							'form_id'  => $form_id,
							'required' => true,
						);
					},
					array_keys( $paths )
				),
			)
		);

		$resolver = new Fixture_Pdf_Resolver( $paths );
		$validator = new Pdf_Validator();
		$manifests = new Packet_Manifest();
		$builder   = new Pdf_Packet_Builder(
			$loader,
			$resolver,
			$validator,
			$store,
			$manifests,
			static function ( array $pdf_paths ): ?string {
				$merged = '';

				foreach ( $pdf_paths as $path ) {
					$merged .= (string) file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
				}

				return '' === $merged ? null : $merged;
			}
		);

		return new Packet_Service( $builder, $store, $loader, $resolver, $validator, $manifests );
	}

	/**
	 * Create fixture PDFs for form ids.
	 *
	 * @param array<int, string> $form_ids Form ids.
	 * @return array<string, string>
	 */
	private function create_fixture_pdfs( array $form_ids ): array {
		$paths = array();

		foreach ( $form_ids as $form_id ) {
			$paths[ $form_id ] = $this->make_pdf( $form_id );
		}

		return $paths;
	}

	/**
	 * Create a minimal valid PDF file.
	 *
	 * @param string $form_id Form id used in filename.
	 * @return string Absolute path.
	 */
	private function make_pdf( string $form_id ): string {
		$path = $this->pdf_dir . '/' . strtolower( $form_id ) . '.pdf';
		$body = "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n";
		file_put_contents( $path, $body ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

		return $path;
	}
}

/**
 * Fixture PDF resolver for tests.
 */
class Fixture_Pdf_Resolver extends Pdf_Resolver {

	/**
	 * Path map.
	 *
	 * @var array<string, string>
	 */
	private array $map;

	/**
	 * Constructor.
	 *
	 * @param array<string, string> $map Form id => path.
	 */
	public function __construct( array $map ) {
		$this->map = $map;
	}

	/**
	 * Resolve form id to path.
	 *
	 * @param string $form_id Form id.
	 * @return array{form_id: string, pdf_path: string}
	 */
	public function resolve( string $form_id ): array {
		$form_id = strtoupper( trim( $form_id ) );

		return array(
			'form_id'  => $form_id,
			'pdf_path' => (string) ( $this->map[ $form_id ] ?? '' ),
		);
	}
}

/**
 * Fixture package loader for tests.
 */
class Fixture_Package_Loader extends Package_Loader {

	/**
	 * Package definition.
	 *
	 * @var array<string, mixed>|null
	 */
	private ?array $definition;

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed>|null $definition Package definition.
	 */
	public function __construct( ?array $definition ) {
		$this->definition = $definition;
	}

	/**
	 * Load package definition.
	 *
	 * @param string $package_id Package id.
	 * @return array<string, mixed>|null
	 */
	public function load( string $package_id ): ?array {
		if ( null === $this->definition ) {
			return null;
		}

		if ( (string) ( $this->definition['package_id'] ?? '' ) !== $package_id ) {
			return null;
		}

		return $this->definition;
	}
}
