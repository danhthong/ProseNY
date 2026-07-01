<?php
/**
 * Case stage integrity tests.
 *
 * @package ProSeCore
 */

use PHPUnit\Framework\TestCase;
use ProSe\Core\Forms\Classification\Vocabulary;
use ProSe\Core\Intake\Case_Stage_Integrity;
use ProSe\Core\Routing\Workflow_Catalog;

/**
 * Class CaseStageIntegrityTest
 */
class CaseStageIntegrityTest extends TestCase {

	/**
	 * Set up.
	 */
	protected function setUp(): void {
		Workflow_Catalog::reset_cache();
	}

	/**
	 * Reconcile aligns roadmap, workflow_state, and case_memory to the same stage.
	 */
	public function test_reconcile_aligns_stage_representations(): void {
		$profile = array(
			'workflow'        => 'uncontested_divorce_no_children_nyc',
			'issue'           => 'divorce',
			'court'           => 'supreme_court',
			'procedural_node' => Vocabulary::NODE_1010_JUDGMENT,
			'facts'           => array(
				'children'      => false,
				'spouse_agrees' => true,
			),
			'completed_documents' => array(
				array( 'stage_id' => 'commencement' ),
				array( 'stage_id' => 'service' ),
				array( 'stage_id' => 'calendar' ),
			),
		);

		$reconciled = ( new Case_Stage_Integrity() )->reconcile_case_profile( $profile, true );
		$integrity  = new Case_Stage_Integrity();
		$validation = $integrity->validate_stage_snapshot(
			array(
				'roadmap'        => is_array( $reconciled['roadmap'] ?? null ) ? $reconciled['roadmap'] : array(),
				'workflow_state' => is_array( $reconciled['workflow_state'] ?? null ) ? $reconciled['workflow_state'] : array(),
				'case_memory'    => is_array( $reconciled['case_memory'] ?? null ) ? $reconciled['case_memory'] : array(),
				'stage_context'  => array(
					'current_stage' => is_array( $reconciled['workflow_state']['current_stage'] ?? null )
						? $reconciled['workflow_state']['current_stage']
						: array(),
				),
				'transition'     => array(
					'current_stage' => is_array( $reconciled['workflow_state']['current_stage'] ?? null )
						? $reconciled['workflow_state']['current_stage']
						: array(),
				),
				'brief_stage'    => (string) ( $reconciled['workflow_state']['current_stage']['id'] ?? '' ),
			)
		);

		$this->assertTrue( $validation['valid'] );
		$this->assertSame( 'judgment', $validation['canonical_stage'] );
		$this->assertSame( 'judgment', $reconciled['workflow_state']['current_stage']['id'] ?? '' );
		$this->assertSame( 'judgment', $reconciled['case_memory']['current_stage']['id'] ?? '' );
	}
}
