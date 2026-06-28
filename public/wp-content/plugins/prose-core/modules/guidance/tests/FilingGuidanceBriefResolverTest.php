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

	/**
	 * Commencement brief exposes split download buttons for UD-1 vs UD-1A + UD-2.
	 */
	public function test_commencement_download_options(): void {
		$resolver = new Filing_Guidance_Brief_Resolver();
		$options  = $resolver->download_options(
			array(
				'workflow' => 'uncontested_divorce_no_children_nyc',
				'facts'    => array(
					'active_divorce' => false,
				),
				'stage'    => 'commencement',
			)
		);

		$this->assertCount( 2, $options );
		$this->assertSame( 'summons_with_notice', $options[0]['id'] );
		$this->assertSame( array( 'UD-1' ), $options[0]['form_codes'] );
		$this->assertSame( 'Get Documents (UD-1)', $options[0]['label'] );
		$this->assertStringContainsString( 'Option 1', $options[0]['title'] );
		$this->assertSame( 'summons_and_complaint', $options[1]['id'] );
		$this->assertSame( array( 'UD-1a', 'UD-2' ), $options[1]['form_codes'] );
		$this->assertSame( 'Get Documents (UD-1A and UD-2)', $options[1]['label'] );
		$this->assertStringContainsString( 'Option 2', $options[1]['title'] );
	}

	/**
	 * Stages without brief options fall back to one button for all stage forms.
	 */
	public function test_stage_default_download_option(): void {
		$resolver = new Filing_Guidance_Brief_Resolver();
		$options  = $resolver->download_options(
			array(
				'workflow'    => 'uncontested_divorce_no_children_nyc',
				'facts'       => array(),
				'stage'       => 'service',
				'stage_forms' => array(
					array( 'code' => 'UD-3', 'title' => 'Affirmation of Service' ),
				),
			)
		);

		$this->assertCount( 1, $options );
		$this->assertSame( 'stage_default', $options[0]['id'] );
		$this->assertSame( array( 'UD-3' ), $options[0]['form_codes'] );
		$this->assertSame( 'Get Documents (UD-3)', $options[0]['label'] );
	}
}
