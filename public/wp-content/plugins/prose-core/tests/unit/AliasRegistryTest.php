<?php
/**
 * Tests for Alias_Registry.
 *
 * @package ProSeCore
 */

use PHPUnit\Framework\TestCase;
use ProSe\Core\Forms\Database\Import\Alias_Registry;

/**
 * Class AliasRegistryTest
 */
class AliasRegistryTest extends TestCase {

	/**
	 * Aliases resolve to canonical codes.
	 */
	public function test_alias_resolution(): void {
		$registry = new Alias_Registry();
		$registry->load_from_artifact(
			array(
				'registry_version' => '1.2.0',
				'aliases'          => array(
					array(
						'canonical_code' => 'UCCJEA-7',
						'alias_codes'    => array( '4-24', 'UIFSA-10' ),
						'scope'          => 'in_scope',
						'note'           => 'test',
					),
				),
				'deprecated'   => array(),
				'variant_sets' => array(),
			),
			false
		);

		$this->assertSame( 'UCCJEA-7', $registry->resolve( '4-24' ) );
		$this->assertSame( 'UCCJEA-7', $registry->resolve( 'UCCJEA-7' ) );
		$this->assertTrue( $registry->was_aliased( '4-24' ) );
		$this->assertFalse( $registry->was_aliased( 'UCCJEA-7' ) );
	}

	/**
	 * Validation catches alias-as-canonical conflict.
	 */
	public function test_validation_hard_failure_on_dual_role(): void {
		$registry = new Alias_Registry();
		$registry->load_from_artifact(
			array(
				'registry_version' => '1.2.0',
				'aliases'          => array(
					array(
						'canonical_code' => 'GF-15',
						'alias_codes'    => array( '3-44' ),
						'scope'          => 'mixed',
						'note'           => '',
					),
					array(
						'canonical_code' => '3-44',
						'alias_codes'    => array( 'GF-15' ),
						'scope'          => 'mixed',
						'note'           => '',
					),
				),
				'deprecated'   => array(),
				'variant_sets' => array(),
			),
			false
		);

		$result = $registry->validate();
		$this->assertNotEmpty( $result['hard'] );
	}
}
