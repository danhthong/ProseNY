<?php
/**
 * Fact Store and Case Profile tests.
 *
 * @package ProSeCore
 */

use PHPUnit\Framework\TestCase;
use ProSe\Core\Routing\Case_Profile;
use ProSe\Core\Routing\Fact_Store;

/**
 * Class FactStoreTest
 */
class FactStoreTest extends TestCase {

	/**
	 * Later facts override earlier facts.
	 */
	public function test_merge_last_write_wins(): void {
		$store = Fact_Store::from_array( array( 'children' => false ) );
		$store->merge( array( 'children' => true ) );

		$this->assertTrue( $store->get( 'children' ) );
	}

	/**
	 * Fact store export round trip.
	 */
	public function test_export_round_trip(): void {
		$store = Fact_Store::from_array(
			array(
				'children'      => true,
				'child_count'   => 2,
				'spouse_agrees' => true,
			)
		);

		$restored = Fact_Store::from_array( $store->export() );

		$this->assertSame( $store->export(), $restored->export() );
	}

	/**
	 * Case profile serializes expected shape.
	 */
	public function test_case_profile_shape(): void {
		$profile = Case_Profile::from_array(
			array(
				'issue'   => 'custody',
				'court'   => 'family_court',
				'workflow'=> 'custody_nyc',
				'facts'   => array( 'children' => true ),
			)
		);

		$array = $profile->to_array();

		$this->assertArrayHasKey( 'issue', $array );
		$this->assertArrayHasKey( 'court', $array );
		$this->assertArrayHasKey( 'workflow', $array );
		$this->assertArrayHasKey( 'workflow_confidence', $array );
		$this->assertArrayHasKey( 'facts', $array );
		$this->assertArrayHasKey( 'missing_fields', $array );
		$this->assertArrayHasKey( 'candidate_workflows', $array );
		$this->assertArrayHasKey( 'progress', $array );
	}

	/**
	 * Workflow key falls back to facts when profile workflow is empty.
	 */
	public function test_workflow_key_falls_back_to_facts(): void {
		$profile = Case_Profile::from_array(
			array(
				'facts' => array(
					'workflow' => 'contested_divorce_nyc',
					'issue'    => 'divorce',
					'county'   => 'Queens',
				),
			)
		);

		$this->assertSame( 'contested_divorce_nyc', $profile->workflow_key() );
		$this->assertSame( 'divorce', $profile->issue_key() );
		$this->assertSame( 'Queens', $profile->county() );
		$this->assertSame( array( 'workflow' => 'contested_divorce_nyc', 'issue' => 'divorce', 'county' => 'Queens' ), $profile->plain_facts() );
	}
}
