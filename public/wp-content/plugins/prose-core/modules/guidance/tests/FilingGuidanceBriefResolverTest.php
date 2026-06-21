<?php
/**
 * Filing Guidance Brief Resolver tests.
 *
 * @package ProSeCore
 */

use PHPUnit\Framework\TestCase;
use ProSe\Core\Guidance\Filing_Guidance_Brief_Resolver;
use ProSe\Core\Routing\Workflow_Catalog;

/**
 * Class FilingGuidanceBriefResolverTest
 */
class FilingGuidanceBriefResolverTest extends TestCase {

	/**
	 * Set up.
	 */
	protected function setUp(): void {
		Workflow_Catalog::reset_cache();
	}

	/**
	 * Active divorce scenario warns against a new UD-1/UD-2 filing.
	 */
	public function test_active_divorce_scenario(): void {
		$resolver = new Filing_Guidance_Brief_Resolver();
		$brief    = $resolver->resolve(
			array(
				'workflow' => 'contested_divorce_nyc',
				'facts'    => array(
					'county'         => 'Queens',
					'active_divorce' => true,
					'children'       => false,
				),
				'stage'    => 'commencement',
			)
		);

		$this->assertIsArray( $brief );
		$this->assertSame( 'active_case_exists', $brief['scenario_id'] );
		$text = $resolver->format( $brief );
		$this->assertStringContainsString( 'Do not start a second divorce case', $text );
	}

	/**
	 * New case scenario explains UD-1 and UD-2 paths.
	 */
	public function test_new_case_scenario(): void {
		$resolver = new Filing_Guidance_Brief_Resolver();
		$brief    = $resolver->resolve(
			array(
				'workflow' => 'contested_divorce_nyc',
				'facts'    => array(
					'county'         => 'Queens',
					'active_divorce' => false,
				),
				'stage'    => 'commencement',
			)
		);

		$this->assertIsArray( $brief );
		$this->assertSame( 'new_commencement', $brief['scenario_id'] );
		$text = $resolver->format( $brief );
		$this->assertStringContainsString( 'UD-1', $text );
		$this->assertStringContainsString( 'UD-2', $text );
	}
}
