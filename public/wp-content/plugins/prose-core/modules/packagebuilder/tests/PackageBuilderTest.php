<?php
/**
 * Package Builder tests (deterministic; consumes the JSON repositories).
 *
 * @package ProSeCore
 */

use PHPUnit\Framework\TestCase;
use ProSe\Core\Forms\Forms_Catalog;
use ProSe\Core\PackageBuilder\Asset_Source;
use ProSe\Core\PackageBuilder\Manifest_Status;
use ProSe\Core\PackageBuilder\Package_Builder;
use ProSe\Core\PackageBuilder\Package_Manifest;
use ProSe\Core\PackageBuilder\Package_Preview_Service;
use ProSe\Core\PackageBuilder\Package_Status;
use ProSe\Core\PackageBuilder\Package_Type;
use ProSe\Core\PackageBuilder\Package_Zip_Writer;
use ProSe\Core\Routing\Workflow_Catalog;

/**
 * Class PackageBuilderTest
 */
class PackageBuilderTest extends TestCase {

	private const WORKFLOW = 'uncontested_divorce_children_nyc';

	/**
	 * Package builder.
	 *
	 * @var Package_Builder
	 */
	private Package_Builder $builder;

	/**
	 * Set up.
	 */
	protected function setUp(): void {
		Workflow_Catalog::reset_cache();
		Forms_Catalog::reset_cache();
		$this->builder = new Package_Builder();
	}

	/**
	 * Manifest carries identity, type, snapshot, and draft status.
	 */
	public function test_manifest_identity_fields(): void {
		$manifest = $this->builder->build_manifest(
			array(
				'conversation_id' => 'conv-123',
				'workflow'        => self::WORKFLOW,
			)
		);

		$this->assertStringStartsWith( 'pkg_', $manifest['package_id'] );
		$this->assertSame( 'conv-123', $manifest['conversation_id'] );
		$this->assertSame( self::WORKFLOW, $manifest['workflow'] );
		$this->assertSame( Package_Type::BLANK, $manifest['package_type'] );
		$this->assertSame( Manifest_Status::DRAFT, $manifest['manifest_status'] );

		// Workflow snapshot is frozen from the definition.
		$this->assertArrayHasKey( 'required_forms', $manifest['workflow_snapshot'] );
		$this->assertArrayHasKey( 'optional_forms', $manifest['workflow_snapshot'] );
		$this->assertSame( self::WORKFLOW, $manifest['workflow_snapshot']['workflow'] );
	}

	/**
	 * Required and optional forms are resolved with stage + requirement.
	 */
	public function test_resolves_required_and_optional_forms(): void {
		$manifest = $this->builder->build_manifest( array( 'workflow' => self::WORKFLOW ) );

		$codes = array_column( $manifest['forms'], 'code' );

		$this->assertContains( 'UD-2', $codes, 'Required commencement form present.' );
		$this->assertContains( 'UD-8b', $codes, 'Optional form present.' );

		$by_code = array();
		foreach ( $manifest['forms'] as $form ) {
			$by_code[ $form['code'] ] = $form;
		}

		$this->assertSame( 'required', $by_code['UD-2']['requirement'] );
		$this->assertSame( 'commencement', $by_code['UD-2']['stage'] );
		$this->assertSame( 'optional', $by_code['UD-8b']['requirement'] );

		// Forward-compat seams present on every entry.
		$this->assertSame( 'not_applicable', $by_code['UD-2']['fill_status'] );
		$this->assertArrayHasKey( 'fields', $by_code['UD-2'] );
	}

	/**
	 * With no downloaded assets, required forms block readiness.
	 */
	public function test_incomplete_when_required_not_generation_ready(): void {
		$manifest = $this->builder->build_manifest( array( 'workflow' => self::WORKFLOW ) );

		$this->assertSame( Package_Status::INCOMPLETE, $manifest['package_status'] );
		$this->assertNotEmpty( $manifest['validation_errors'] );
	}

	/**
	 * Unknown workflow yields a required-level error.
	 */
	public function test_unknown_workflow_errors(): void {
		$manifest = $this->builder->build_manifest( array( 'workflow' => 'does_not_exist' ) );

		$this->assertSame( Package_Status::INCOMPLETE, $manifest['package_status'] );
		$this->assertNotEmpty( $manifest['validation_errors'] );
		$this->assertEmpty( $manifest['forms'] );
	}

	/**
	 * Filled package type is rejected in the MVP.
	 */
	public function test_filled_type_rejected(): void {
		$manifest = $this->builder->build_manifest(
			array(
				'workflow'     => self::WORKFLOW,
				'package_type' => Package_Type::FILLED,
			)
		);

		$this->assertSame( Package_Type::FILLED, $manifest['package_type'] );
		$this->assertSame( Package_Status::INCOMPLETE, $manifest['package_status'] );
		$this->assertNotEmpty( $manifest['validation_errors'] );
	}

	/**
	 * Build does not produce a ZIP when the package is incomplete.
	 */
	public function test_build_package_skips_zip_when_incomplete(): void {
		$result = $this->builder->build_package( array( 'workflow' => self::WORKFLOW ) );

		$this->assertSame( Package_Status::INCOMPLETE, $result['package_status'] );
		$this->assertSame( Manifest_Status::DRAFT, $result['manifest_status'] );
		$this->assertArrayNotHasKey( 'zip_path', $result );
	}

	/**
	 * Preview projects counts and per-stage grouping with no disk writes.
	 */
	public function test_preview_dto_shape(): void {
		$preview = ( new Package_Preview_Service( $this->builder ) )->preview(
			array( 'workflow' => self::WORKFLOW )
		);

		$this->assertStringStartsWith( 'pkg_', $preview['package_id'] );
		$this->assertNotSame( '', $preview['workflow_title'] );
		$this->assertSame( 'commencement', $preview['stage_context']['current_stage']['id'] ?? '' );
		$this->assertSame( 2, $preview['counts']['required'], 'Commencement has two required forms.' );
		$this->assertSame( 0, $preview['counts']['optional'], 'Optional forms live in later stages; preview counts the current stage only.' );
		$this->assertNotEmpty( $preview['stages'] );
		$this->assertArrayHasKey( 'forms', $preview['stages'][0] );
	}

	/**
	 * Preview honors procedural_node and lists service forms for the active step.
	 */
	public function test_preview_service_stage_forms_with_procedural_node(): void {
		$preview = ( new Package_Preview_Service( $this->builder ) )->preview(
			array(
				'workflow'        => self::WORKFLOW,
				'procedural_node' => 'NODE_1002_SERVICE_COMPLETE',
				'facts'           => array(
					'spouse_agrees' => true,
					'children'      => true,
					'child_count'   => 1,
					'county'        => 'Kings',
				),
			)
		);

		$this->assertSame( 'service', $preview['stage_context']['current_stage']['id'] ?? '' );

		$current = null;

		foreach ( $preview['stages'] as $stage ) {
			if ( 'current' === ( $stage['status'] ?? '' ) ) {
				$current = $stage;
				break;
			}
		}

		$this->assertIsArray( $current );
		$codes = array_column( (array) ( $current['forms'] ?? array() ), 'code' );
		$this->assertContains( 'UD-3', $codes );
		$this->assertNotContains( 'UD-1', $codes );

		$commencement = null;

		foreach ( $preview['stages'] as $stage ) {
			if ( 'commencement' === ( $stage['stage'] ?? '' ) ) {
				$commencement = $stage;
				break;
			}
		}

		$this->assertSame( 'completed', $commencement['status'] ?? '' );
		$this->assertNotEmpty( $commencement['forms'] ?? array(), 'Completed stages keep forms for UI toggling.' );
	}

	/**
	 * ZIP writer produces an archive when assets are available.
	 */
	public function test_zip_writer_builds_archive(): void {
		if ( ! class_exists( '\ZipArchive' ) ) {
			$this->markTestSkipped( 'ZipArchive not available.' );
		}

		$tmp_asset = tempnam( sys_get_temp_dir(), 'prose_form_' ) . '.docx';
		file_put_contents( $tmp_asset, 'FIXTURE DOCX BYTES' );

		$manifest = new Package_Manifest( 'pkg_testzip', 'conv-1', self::WORKFLOW, Package_Type::BLANK, array() );
		$manifest->add_form(
			array(
				'code'             => 'UD-2',
				'title'            => 'Verified Complaint',
				'stage'            => 'commencement',
				'requirement'      => 'required',
				'asset_type'       => 'docx',
				'asset_path'       => $tmp_asset,
				'fillable_strategy' => 'docx_template',
				'generation_ready'  => true,
				'fill_status'      => 'not_applicable',
				'fields'           => array(),
			)
		);
		$manifest->set_manifest_status( Manifest_Status::READY );

		$out_dir = sys_get_temp_dir() . '/prose-package-test-' . uniqid();
		$writer  = new Package_Zip_Writer( new Fixture_Asset_Source(), $out_dir );
		$output  = $writer->write( $manifest );

		$this->assertNotSame( '', $output['zip_path'] );
		$this->assertFileExists( $output['zip_path'] );
		$this->assertContains( 'UD-2', $output['written_forms'] );

		$zip = new \ZipArchive();
		$this->assertTrue( true === $zip->open( $output['zip_path'] ) );
		$this->assertNotFalse( $zip->locateName( 'manifest.json' ) );
		$this->assertNotFalse( $zip->locateName( 'forms/UD-2.docx' ) );
		$zip->close();

		@unlink( $tmp_asset );
	}
}

/**
 * Minimal Asset_Source that returns the form's own asset_path (test fixture).
 */
final class Fixture_Asset_Source implements Asset_Source {

	/**
	 * @param array<string, mixed> $form_record Record.
	 * @param string               $code        Code.
	 * @param string               $stage       Stage.
	 * @param string               $requirement Requirement.
	 * @return array<string, mixed>
	 */
	public function resolve( array $form_record, string $code, string $stage, string $requirement ): array {
		unset( $form_record, $code, $stage, $requirement );
		return array();
	}

	/**
	 * @param array<string, mixed> $form_entry Entry.
	 * @return string|null
	 */
	public function open( array $form_entry ): ?string {
		$path = (string) ( $form_entry['asset_path'] ?? '' );
		return ( '' !== $path && is_readable( $path ) ) ? $path : null;
	}
}
