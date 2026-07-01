<?php
/**
 * Stage transition guidance service tests.
 *
 * @package ProSeCore
 */

use PHPUnit\Framework\TestCase;
use ProSe\Core\Ai_Intake\AI_Settings;
use ProSe\Core\Ai_Intake\Stage_Transition_Guidance_Service;
use ProSe\Core\Routing\Workflow_Catalog;

/**
 * Class StageTransitionGuidanceServiceTest
 */
class StageTransitionGuidanceServiceTest extends TestCase {

	/**
	 * Set up.
	 */
	protected function setUp(): void {
		Workflow_Catalog::reset_cache();
	}

	/**
	 * Builds a structured payload and returns AI guidance for the new stage.
	 */
	public function test_generates_guidance_from_completion_result(): void {
		$settings = new class() extends AI_Settings {
			public function get( string $key, $default = null ) {
				if ( 'api_key' === $key ) {
					return 'sk-test-key';
				}

				return parent::get( $key, $default );
			}
		};

		$service = new Stage_Transition_Guidance_Service( $settings );
		$result  = $service->generate(
			array(
				'advanced'        => true,
				'completed_stage' => 'service',
				'message'         => 'Thanks — service is complete.',
				'case_profile'    => array(
					'workflow' => 'uncontested_divorce_no_children_nyc',
					'facts'    => array(
						'county'        => 'Queens',
						'children'      => false,
						'spouse_agrees' => true,
					),
				),
				'stage_context'   => array(
					'forms_visible' => true,
					'current_stage' => array(
						'id'          => 'calendar',
						'title'       => 'Final Papers & Calendar',
						'description' => 'Prepare final submission package.',
					),
					'stage_forms'   => array(
						array(
							'code'     => 'UD-5',
							'title'    => 'Affirmation of Regularity',
							'required' => true,
						),
					),
					'future_stages' => array(
						array(
							'id'    => 'judgment',
							'title' => 'Judgment',
						),
					),
				),
				'actions'         => array(
					'workflow'      => 'uncontested_divorce_no_children_nyc',
					'package_label' => 'Uncontested Divorce (No Children)',
					'summary'       => array(
						array(
							'label' => 'Current stage',
							'value' => 'Final Papers & Calendar',
						),
					),
				),
			)
		);

		$this->assertTrue( $result['ai_used'] );
		$this->assertNotEmpty( $result['guidance'] );
		$this->assertStringContainsString( 'Before continuing', $result['guidance'] );
		$this->assertNotEmpty( $result['checklist'] );
	}

	/**
	 * Falls back to the deterministic message when AI is unavailable.
	 */
	public function test_falls_back_without_api_key(): void {
		$settings = new class() extends AI_Settings {
			public function get( string $key, $default = null ) {
				if ( 'api_key' === $key ) {
					return '';
				}

				return parent::get( $key, $default );
			}
		};

		$service = new Stage_Transition_Guidance_Service( $settings );
		$result  = $service->generate(
			array(
				'message'       => 'Deterministic fallback message.',
				'stage_context' => array(
					'current_stage' => array(
						'id'    => 'service',
						'title' => 'Service of Process',
					),
				),
			)
		);

		$this->assertFalse( $result['ai_used'] );
		$this->assertNotEmpty( $result['guidance'] );
		$this->assertStringContainsString( 'Before continuing', $result['guidance'] );
	}
}
