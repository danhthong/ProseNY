<?php
/**
 * Guidance Engine tests.
 *
 * @package ProSeCore
 */

use PHPUnit\Framework\TestCase;
use ProSe\Core\Guidance\County_Guidance_Resolver;
use ProSe\Core\Guidance\Guidance_Engine;
use ProSe\Core\Guidance\Guidance_Repository;
use ProSe\Core\Guidance\Guidance_Service;
use ProSe\Core\Guidance\Step_Resolver;
use ProSe\Core\Guidance\Validator;
use ProSe\Core\Routing\Workflow_Catalog;

/**
 * Class GuidanceEngineTest
 */
class GuidanceEngineTest extends TestCase {

	/**
	 * Temp storage directory.
	 *
	 * @var string
	 */
	private string $temp_dir;

	/**
	 * Set up.
	 */
	protected function setUp(): void {
		Workflow_Catalog::reset_cache();
		$this->temp_dir = sys_get_temp_dir() . '/prose-guidance-test-' . uniqid( '', true );
		mkdir( $this->temp_dir, 0777, true );
		mkdir( $this->temp_dir . '/counties', 0777, true );
	}

	/**
	 * Tear down.
	 */
	protected function tearDown(): void {
		$this->delete_dir( $this->temp_dir );
	}

	/**
	 * Valid workflow guidance returns ordered enriched steps.
	 */
	public function test_valid_workflow_guidance(): void {
		$service = $this->make_service_with_seed_data();
		$result  = $service->get_guidance( 'uncontested_divorce_children_nyc', 'Kings' );

		$this->assertTrue( $result['success'] );
		$this->assertSame( 'uncontested_divorce_children_nyc', $result['guidance']['workflow'] );
		$this->assertNotEmpty( $result['guidance']['steps'] );

		$first = $result['guidance']['steps'][0];
		$this->assertSame( 1, $first['order'] );
		$this->assertSame( 'commencement', $first['id'] );
		$this->assertSame( 'Commencement', $first['title'] );
		$this->assertNotSame( '', $first['description'] );
		$this->assertIsArray( $first['tips'] );
		$this->assertIsArray( $first['warnings'] );
		$this->assertIsArray( $first['related_forms'] );
		$this->assertIsArray( $first['resources'] );
		$this->assertNull( $first['estimated_time'] );
		$this->assertSame( 'Kings', $result['guidance']['county_guidance']['county'] );
	}

	/**
	 * Missing stage guidance emits warning but still returns the step.
	 */
	public function test_missing_stage_guidance(): void {
		$repository = new Guidance_Repository( $this->temp_dir, $this->temp_dir . '/missing-seed' );
		$service    = $this->make_service( $repository );
		$result     = $service->get_guidance( 'uncontested_divorce_children_nyc' );

		$this->assertTrue( $result['success'] );

		$warning_codes = array_column( $result['guidance']['warnings'], 'code' );
		$this->assertContains( Validator::WARN_GUIDANCE_MISSING, $warning_codes );

		$service_step = null;
		foreach ( $result['guidance']['steps'] as $step ) {
			if ( 'service' === $step['id'] ) {
				$service_step = $step;
				break;
			}
		}

		$this->assertNotNull( $service_step );
		$this->assertSame( 'Service', $service_step['title'] );
		$this->assertSame( '', $service_step['description'] );
	}

	/**
	 * Malformed guidance file emits malformed warning.
	 */
	public function test_malformed_guidance_file(): void {
		file_put_contents( $this->temp_dir . '/service.json', '{not-json' );
		$repository = new Guidance_Repository( $this->temp_dir, $this->temp_dir . '/missing-seed' );
		$service    = $this->make_service( $repository );
		$result     = $service->get_guidance( 'uncontested_divorce_children_nyc' );

		$this->assertTrue( $result['success'] );

		$warning_codes = array_column( $result['guidance']['warnings'], 'code' );
		$this->assertContains( Validator::WARN_MALFORMED_GUIDANCE_FILE, $warning_codes );
	}

	/**
	 * County guidance resolves for known and unknown counties.
	 */
	public function test_county_guidance(): void {
		$this->copy_county_seed( 'kings.json' );
		$repository = new Guidance_Repository( $this->temp_dir, $this->temp_dir . '/missing-seed' );
		$resolver   = new County_Guidance_Resolver( $repository );
		$known      = $resolver->resolve( 'Kings' );
		$unknown    = $resolver->resolve( 'Westchester' );

		$this->assertSame( 'Kings', $known['county_guidance']['county'] );
		$this->assertSame( array(), $known['county_guidance']['filing_notes'] );
		$this->assertSame( 'Westchester', $unknown['county_guidance']['county'] );
		$this->assertSame( array(), $unknown['county_guidance']['special_requirements'] );
	}

	/**
	 * Unknown workflow returns structured error envelope.
	 */
	public function test_workflow_not_found(): void {
		$service = $this->make_service_with_seed_data();
		$result  = $service->get_guidance( 'workflow_does_not_exist' );

		$this->assertFalse( $result['success'] );
		$this->assertSame( Validator::CODE_WORKFLOW_NOT_FOUND, $result['error']['code'] );
	}

	/**
	 * Coverage calculation reports expected percentages.
	 */
	public function test_coverage_calculation(): void {
		$this->copy_all_stage_seed_files();
		$repository = new Guidance_Repository( $this->temp_dir, $this->temp_dir . '/missing-seed' );
		$service    = $this->make_service( $repository );
		$coverage   = $service->coverage();

		$this->assertGreaterThan( 0, $coverage['workflow_count'] );
		$this->assertGreaterThan( 0, $coverage['stage_count'] );
		$this->assertSame( 100, $coverage['coverage_percent'] );
		$this->assertSame( 0, $coverage['missing_stages'] );
	}

	/**
	 * Optional schema fields are preserved when present and defaulted when absent.
	 */
	public function test_optional_schema_fields(): void {
		$payload = array(
			'id'             => 'service',
			'title'          => 'Serve Papers',
			'description'    => 'Serve the other party.',
			'related_forms'  => array( 'UD-1' ),
			'resources'      => array( array( 'label' => 'NYS Courts', 'url' => 'https://example.test' ) ),
			'estimated_time' => '1-2 weeks',
		);
		file_put_contents(
			$this->temp_dir . '/service.json',
			(string) wp_json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES )
		);
		$this->copy_stage_seed( 'commencement.json' );
		$this->copy_stage_seed( 'calendar.json' );
		$this->copy_stage_seed( 'judgment.json' );

		$repository = new Guidance_Repository( $this->temp_dir, $this->temp_dir . '/missing-seed' );
		$service    = $this->make_service( $repository );
		$result     = $service->get_guidance( 'uncontested_divorce_children_nyc' );

		$this->assertTrue( $result['success'] );

		$service_step = null;
		$calendar_step = null;
		foreach ( $result['guidance']['steps'] as $step ) {
			if ( 'service' === $step['id'] ) {
				$service_step = $step;
			}
			if ( 'calendar' === $step['id'] ) {
				$calendar_step = $step;
			}
		}

		$this->assertNotNull( $service_step );
		$this->assertSame( array( 'UD-1' ), $service_step['related_forms'] );
		$this->assertCount( 1, $service_step['resources'] );
		$this->assertSame( '1-2 weeks', $service_step['estimated_time'] );

		$this->assertNotNull( $calendar_step );
		$this->assertSame( array(), $calendar_step['related_forms'] );
		$this->assertSame( array(), $calendar_step['resources'] );
		$this->assertNull( $calendar_step['estimated_time'] );
	}

	/**
	 * Repository normalization keeps unknown extra fields.
	 */
	public function test_repository_preserves_extra_fields(): void {
		$repository = new Guidance_Repository( $this->temp_dir, $this->temp_dir . '/missing-seed' );
		$normalized = $repository->normalize_stage(
			'service',
			array(
				'id'          => 'service',
				'title'       => 'Service',
				'description' => 'Serve papers.',
				'future_ui'   => array( 'enabled' => true ),
			)
		);

		$this->assertSame( array( 'enabled' => true ), $normalized['future_ui'] );
	}

	/**
	 * Build service using plugin seed data.
	 *
	 * @return Guidance_Service
	 */
	private function make_service_with_seed_data(): Guidance_Service {
		return $this->make_service( new Guidance_Repository( $this->temp_dir ) );
	}

	/**
	 * Build service with repository.
	 *
	 * @param Guidance_Repository $repository Repository.
	 * @return Guidance_Service
	 */
	private function make_service( Guidance_Repository $repository ): Guidance_Service {
		$catalog   = new Workflow_Catalog();
		$validator = new Validator();

		return new Guidance_Service(
			new Guidance_Engine(
				new Step_Resolver( $catalog, $repository, $validator ),
				new County_Guidance_Resolver( $repository, $validator ),
				$validator
			),
			$repository,
			$catalog,
			$validator
		);
	}

	/**
	 * Copy one stage seed file into temp uploads.
	 *
	 * @param string $filename Filename.
	 * @return void
	 */
	private function copy_stage_seed( string $filename ): void {
		$source = PROSE_CORE_PATH . 'modules/guidance/data/stages/' . $filename;
		copy( $source, $this->temp_dir . '/' . $filename );
	}

	/**
	 * Copy all stage seed files into temp uploads.
	 *
	 * @return void
	 */
	private function copy_all_stage_seed_files(): void {
		foreach ( glob( PROSE_CORE_PATH . 'modules/guidance/data/stages/*.json' ) ?: array() as $file ) {
			copy( $file, $this->temp_dir . '/' . basename( $file ) );
		}
	}

	/**
	 * Copy one county seed file into temp uploads.
	 *
	 * @param string $filename Filename.
	 * @return void
	 */
	private function copy_county_seed( string $filename ): void {
		$source = PROSE_CORE_PATH . 'modules/guidance/data/counties/' . $filename;
		copy( $source, $this->temp_dir . '/counties/' . $filename );
	}

	/**
	 * Recursively delete a directory.
	 *
	 * @param string $dir Directory path.
	 * @return void
	 */
	private function delete_dir( string $dir ): void {
		if ( ! is_dir( $dir ) ) {
			return;
		}

		$items = scandir( $dir );
		if ( false === $items ) {
			return;
		}

		foreach ( $items as $item ) {
			if ( '.' === $item || '..' === $item ) {
				continue;
			}

			$path = $dir . '/' . $item;
			if ( is_dir( $path ) ) {
				$this->delete_dir( $path );
			} else {
				unlink( $path );
			}
		}

		rmdir( $dir );
	}
}
