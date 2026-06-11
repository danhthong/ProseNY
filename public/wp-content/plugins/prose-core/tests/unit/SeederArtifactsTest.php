<?php
/**
 * Tests that seeder JSON artifacts exist and are valid.
 *
 * @package ProSeCore
 */

use PHPUnit\Framework\TestCase;
use ProSe\Core\Forms\Database\Import\Seeder_Artifact_Loader;

/**
 * Class SeederArtifactsTest
 */
class SeederArtifactsTest extends TestCase {

	/**
	 * All five pipeline artifacts load without error.
	 */
	public function test_all_artifacts_load(): void {
		$loader   = new Seeder_Artifact_Loader();
		$artifacts = $loader->load_all();

		$this->assertArrayHasKey( 'workflow', $artifacts );
		$this->assertArrayHasKey( 'node', $artifacts );
		$this->assertArrayHasKey( 'package', $artifacts );
		$this->assertArrayHasKey( 'form_package', $artifacts );
		$this->assertArrayHasKey( 'alias', $artifacts );

		$this->assertSame( '1.2.0', $artifacts['package']['catalog_version'] );
		$this->assertNotEmpty( $artifacts['workflow']['workflows'] );
		$this->assertNotEmpty( $artifacts['node']['nodes'] );
		$this->assertNotEmpty( $artifacts['package']['packages'] );
		$this->assertNotEmpty( $artifacts['form_package']['mappings'] );
	}
}
