<?php
/**
 * Case context builder tests.
 *
 * @package ProSeCore
 */

use PHPUnit\Framework\TestCase;
use ProSe\Core\Ai_Intake\Case_Context_Builder;
use ProSe\Core\Ai_Intake\Intake_State;
use ProSe\Core\Routing\Workflow_Catalog;

/**
 * Class CaseContextBuilderTest
 */
class CaseContextBuilderTest extends TestCase {

	/**
	 * Set up.
	 */
	protected function setUp(): void {
		Workflow_Catalog::reset_cache();
	}

	/**
	 * Canonical context exposes one workflow_state current_stage for all surfaces.
	 */
	public function test_builds_canonical_workflow_state(): void {
		$intake = Intake_State::from_array(
			array(
				'facts' => array(
					'issue'         => array( 'value' => 'divorce', 'confidence' => 0.95 ),
					'spouse_agrees' => array( 'value' => true, 'confidence' => 0.95 ),
					'children'      => array( 'value' => false, 'confidence' => 0.95 ),
					'county'        => array( 'value' => 'Queens', 'confidence' => 0.95 ),
				),
			)
		);
		$intake->set_workflow( 'uncontested_divorce_no_children_nyc' );

		$context = ( new Case_Context_Builder() )->build(
			array(
				'intake'          => $intake,
				'case_profile'    => array(),
				'procedural_node' => '',
				'completion'      => 100,
				'missing_payload' => array(
					'all'          => array(),
					'conversation' => array(),
					'resolved'     => array(
						'candidate_workflows' => array(),
						'routing_confidence'  => 1.0,
					),
					'completion'   => 100,
				),
			)
		);

		$this->assertSame(
			$context['workflow_state']['current_stage']['id'] ?? '',
			$context['case_memory']['current_stage']['id'] ?? ''
		);
		$this->assertSame( 'confirmed', $context['workflow_assessment']['status'] ?? '' );
	}

	/**
	 * Alias child facts collapse to one confirmed acknowledgment line.
	 */
	public function test_confirmed_facts_deduplicate_child_aliases(): void {
		$intake = Intake_State::from_array(
			array(
				'facts' => array(
					'children'                => array( 'value' => false, 'confidence' => 0.95 ),
					'has_minor_children'      => array( 'value' => false, 'confidence' => 0.95 ),
					'minor_children_involved' => array( 'value' => false, 'confidence' => 0.95 ),
					'child_count'             => array( 'value' => 0, 'confidence' => 0.95 ),
				),
			)
		);
		$intake->set_workflow( 'uncontested_divorce_no_children_nyc' );

		$context = ( new Case_Context_Builder() )->build(
			array(
				'intake'          => $intake,
				'case_profile'    => array(),
				'procedural_node' => '',
				'completion'      => 100,
				'missing_payload' => array(
					'all'          => array(),
					'conversation' => array(),
					'resolved'     => array(
						'candidate_workflows' => array(),
						'routing_confidence'  => 1.0,
					),
					'completion'   => 100,
				),
			)
		);

		$confirmed = (array) ( $context['workflow_assessment']['confirmed_facts'] ?? array() );

		$this->assertCount( 1, $confirmed );
		$this->assertStringContainsString( 'No children under 21', (string) ( $confirmed[0] ?? '' ) );
	}
}
