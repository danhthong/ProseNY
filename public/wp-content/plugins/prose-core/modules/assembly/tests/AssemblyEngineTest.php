<?php
/**
 * Document Assembly Engine tests.
 *
 * @package ProSeCore
 */

use PHPUnit\Framework\TestCase;
use ProSe\Core\Assembly\Assembly_Service;
use ProSe\Core\Assembly\Data_Resolver;
use ProSe\Core\Assembly\Document_Assembler;
use ProSe\Core\Assembly\Form_Loader;
use ProSe\Core\Assembly\Manifest_Builder;
use ProSe\Core\Assembly\Package_Loader;
use ProSe\Core\Assembly\Validator;
use ProSe\Core\Forms\Classification\Vocabulary;
use ProSe\Core\Forms\Forms_Catalog;

/**
 * Class AssemblyEngineTest
 */
class AssemblyEngineTest extends TestCase {

	private const PACKAGE_WITH_CHILDREN = 'PKG_UNCONTESTED_WITH_CHILDREN';

	/**
	 * Document assembler.
	 *
	 * @var Document_Assembler
	 */
	private Document_Assembler $assembler;

	/**
	 * Set up.
	 */
	protected function setUp(): void {
		Forms_Catalog::reset_cache();
		$this->assembler = new Document_Assembler();
	}

	/**
	 * Valid package produces manifest, forms, flattened data, and missing_data array.
	 */
	public function test_valid_package_assembly(): void {
		$result = $this->assembler->assemble(
			array(
				'client' => array(
					'full_name' => 'John Doe',
				),
				'spouse' => array(
					'full_name' => 'Jane Doe',
				),
			),
			self::PACKAGE_WITH_CHILDREN
		);

		$this->assertTrue( $result['success'] );
		$this->assertSame( self::PACKAGE_WITH_CHILDREN, $result['assembly']['package_id'] );

		$manifest = $result['assembly']['manifest'];
		$this->assertSame( self::PACKAGE_WITH_CHILDREN, $manifest['package_id'] );
		$this->assertSame( 7, $manifest['form_count'] );
		$this->assertCount( 7, $manifest['forms'] );

		foreach ( $manifest['forms'] as $entry ) {
			$this->assertArrayHasKey( 'form_id', $entry );
			$this->assertArrayHasKey( 'required', $entry );
			$this->assertArrayHasKey( 'status', $entry );
			$this->assertTrue( $entry['required'] );

			$expected_status = null !== ( new Forms_Catalog() )->by_code( $entry['form_id'] )
				? 'ready'
				: 'not_found';
			$this->assertSame( $expected_status, $entry['status'] );
		}

		$this->assertSame( 'John Doe', $result['assembly']['data']['client.full_name'] );
		$this->assertSame( 'Jane Doe', $result['assembly']['data']['spouse.full_name'] );
		$this->assertIsArray( $result['assembly']['missing_data'] );
		$this->assertSame( array(), $result['assembly']['missing_data'] );
		$ready_forms = array_filter(
			$manifest['forms'],
			static function ( array $entry ): bool {
				return 'ready' === $entry['status'];
			}
		);
		$this->assertCount( count( $ready_forms ), $result['assembly']['forms'] );
	}

	/**
	 * Optional forms are not assumed required.
	 */
	public function test_required_flag_honored_for_optional_forms(): void {
		$loader = new class() extends Package_Loader {
			/**
			 * @param string $package_id Package id.
			 * @return array<string, mixed>|null
			 */
			public function load( string $package_id ): ?array {
				unset( $package_id );

				return array(
					'package_id'   => 'PKG_TEST_OPTIONAL',
					'package_name' => 'Test Optional Package',
					'court'        => 'supreme_court',
					'forms'        => array(
						array(
							'form_id'  => 'UD-1',
							'required' => true,
						),
						array(
							'form_id'  => 'UD-8',
							'required' => false,
						),
					),
				);
			}
		};

		$assembler = new Document_Assembler( null, $loader );
		$result    = $assembler->assemble(
			array( 'client' => array( 'full_name' => 'John Doe' ) ),
			'PKG_TEST_OPTIONAL'
		);

		$this->assertTrue( $result['success'] );

		$by_id = array();

		foreach ( $result['assembly']['manifest']['forms'] as $entry ) {
			$by_id[ $entry['form_id'] ] = $entry;
		}

		$this->assertTrue( $by_id['UD-1']['required'] );
		$this->assertFalse( $by_id['UD-8']['required'] );
	}

	/**
	 * Empty package ID returns missing_package.
	 */
	public function test_missing_package_id(): void {
		$result = $this->assembler->assemble(
			array( 'client' => array( 'full_name' => 'John Doe' ) ),
			''
		);

		$this->assertFalse( $result['success'] );
		$this->assertSame( 'missing_package', $result['error']['code'] );
	}

	/**
	 * Invalid package ID format returns invalid_package.
	 */
	public function test_invalid_package_id_format(): void {
		$result = $this->assembler->assemble(
			array( 'client' => array( 'full_name' => 'John Doe' ) ),
			'not-a-package'
		);

		$this->assertFalse( $result['success'] );
		$this->assertSame( 'invalid_package', $result['error']['code'] );
	}

	/**
	 * Unknown package returns package_not_found.
	 */
	public function test_package_not_found(): void {
		$result = $this->assembler->assemble(
			array( 'client' => array( 'full_name' => 'John Doe' ) ),
			'PKG_DOES_NOT_EXIST'
		);

		$this->assertFalse( $result['success'] );
		$this->assertSame( 'package_not_found', $result['error']['code'] );
	}

	/**
	 * Package with no forms returns invalid_package.
	 */
	public function test_package_without_forms(): void {
		$loader = new class() extends Package_Loader {
			/**
			 * @param string $package_id Package id.
			 * @return array<string, mixed>|null
			 */
			public function load( string $package_id ): ?array {
				return array(
					'package_id'   => $package_id,
					'package_name' => 'Empty Package',
					'court'        => 'supreme_court',
					'forms'        => array(),
				);
			}
		};

		$assembler = new Document_Assembler( null, $loader );
		$result    = $assembler->assemble(
			array( 'client' => array( 'full_name' => 'John Doe' ) ),
			'PKG_EMPTY_FORMS'
		);

		$this->assertFalse( $result['success'] );
		$this->assertSame( 'invalid_package', $result['error']['code'] );
	}

	/**
	 * Missing form definitions surface not_found status in the manifest.
	 */
	public function test_missing_form_definition(): void {
		$loader = new class() extends Package_Loader {
			/**
			 * @param string $package_id Package id.
			 * @return array<string, mixed>|null
			 */
			public function load( string $package_id ): ?array {
				unset( $package_id );

				return array(
					'package_id'   => 'PKG_MISSING_FORM',
					'package_name' => 'Missing Form Package',
					'court'        => 'supreme_court',
					'forms'        => array(
						array(
							'form_id'  => 'UD-1',
							'required' => true,
						),
						array(
							'form_id'  => 'FORM_DOES_NOT_EXIST',
							'required' => true,
						),
					),
				);
			}
		};

		$assembler = new Document_Assembler( null, $loader );
		$result    = $assembler->assemble(
			array( 'client' => array( 'full_name' => 'John Doe' ) ),
			'PKG_MISSING_FORM'
		);

		$this->assertTrue( $result['success'] );

		$statuses = array_column( $result['assembly']['manifest']['forms'], 'status', 'form_id' );
		$this->assertSame( 'ready', $statuses['UD-1'] );
		$this->assertSame( 'not_found', $statuses['FORM_DOES_NOT_EXIST'] );
		$this->assertCount( 1, $result['assembly']['forms'] );
	}

	/**
	 * Empty intake returns missing_intake.
	 */
	public function test_missing_intake(): void {
		$result = $this->assembler->assemble( array(), self::PACKAGE_WITH_CHILDREN );

		$this->assertFalse( $result['success'] );
		$this->assertSame( 'missing_intake', $result['error']['code'] );
	}

	/**
	 * Case-insensitive form codes resolve from the Forms Repository.
	 */
	public function test_form_loader_case_insensitive(): void {
		$loader = new Form_Loader();
		$form   = $loader->load( 'ud-1' );

		$this->assertIsArray( $form );
		$this->assertSame( 'UD-1', $form['id'] );
		$this->assertSame( 'Summons With Notice', $form['title'] );
	}

	/**
	 * Missing data paths are returned when required paths are not present.
	 */
	public function test_missing_data_paths(): void {
		$filter = static function ( array $paths ): array {
			return array( 'client.full_name', 'spouse.full_name' );
		};

		add_filter( 'prose_assembly_required_data_paths', $filter );

		$result = $this->assembler->assemble(
			array(
				'client' => array(
					'full_name' => 'John Doe',
				),
			),
			self::PACKAGE_WITH_CHILDREN
		);

		remove_filter( 'prose_assembly_required_data_paths', $filter );

		$this->assertTrue( $result['success'] );
		$this->assertSame( array( 'spouse.full_name' ), $result['assembly']['missing_data'] );
	}

	/**
	 * Data resolver flattens nested lists with numeric indexes.
	 */
	public function test_data_resolver_flattens_lists(): void {
		$resolver = new Data_Resolver();
		$flat     = $resolver->resolve(
			array(
				'children' => array(
					array( 'name' => 'Alex' ),
					array( 'name' => 'Sam' ),
				),
			)
		);

		$this->assertSame( 'Alex', $flat['children.0.name'] );
		$this->assertSame( 'Sam', $flat['children.1.name'] );
	}

	/**
	 * Assembly service delegates to the document assembler.
	 */
	public function test_assembly_service_build(): void {
		$service = new Assembly_Service();
		$result  = $service->build(
			array( 'client' => array( 'full_name' => 'John Doe' ) ),
			self::PACKAGE_WITH_CHILDREN
		);

		$this->assertTrue( $result['success'] );
		$this->assertSame( self::PACKAGE_WITH_CHILDREN, $result['assembly']['package_id'] );
	}

	/**
	 * Package loader reads the vocabulary catalog for known packages.
	 */
	public function test_package_loader_reads_catalog(): void {
		$loader  = new Package_Loader();
		$package = $loader->load( self::PACKAGE_WITH_CHILDREN );

		$this->assertIsArray( $package );
		$this->assertSame( self::PACKAGE_WITH_CHILDREN, $package['package_id'] );
		$this->assertCount( 7, $package['forms'] );
	}

	/**
	 * Manifest builder preserves required flags and statuses.
	 */
	public function test_manifest_builder(): void {
		$builder  = new Manifest_Builder();
		$manifest = $builder->build(
			'PKG_TEST',
			array(
				array(
					'form_id'  => 'UD-1',
					'required' => true,
				),
				array(
					'form_id'  => 'MISSING',
					'required' => false,
				),
			),
			array(
				'UD-1' => array(
					'id'       => 'UD-1',
					'title'    => 'Summons',
					'court'    => 'supreme_court',
					'category' => 'divorce',
				),
				'MISSING' => null,
			)
		);

		$this->assertSame( 2, $manifest['form_count'] );
		$this->assertSame( 'ready', $manifest['forms'][0]['status'] );
		$this->assertTrue( $manifest['forms'][0]['required'] );
		$this->assertSame( 'not_found', $manifest['forms'][1]['status'] );
		$this->assertFalse( $manifest['forms'][1]['required'] );
	}

	/**
	 * Validator rejects malformed intake types.
	 */
	public function test_validator_malformed_intake(): void {
		$validator = new Validator();
		$result    = $validator->validate_request( 'not-an-array', 'PKG_UNCONTESTED_WITH_CHILDREN' );

		$this->assertFalse( $result['valid'] );
		$this->assertSame( 'malformed_intake', $result['error']['code'] );
	}
}
