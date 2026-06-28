<?php
/**
 * Case Summary Presenter tests.
 *
 * @package ProSeCore
 */

use PHPUnit\Framework\TestCase;
use ProSe\Core\Intake\Case_Summary_Presenter;
use ProSe\Core\Routing\Workflow_Catalog;

/**
 * Class CaseSummaryPresenterTest
 */
class CaseSummaryPresenterTest extends TestCase {

	/**
	 * Set up.
	 */
	protected function setUp(): void {
		Workflow_Catalog::reset_cache();
	}

	/**
	 * Structured summary includes current stage and forms.
	 */
	public function test_build_includes_current_stage_and_forms(): void {
		$presenter = new Case_Summary_Presenter();
		$summary   = $presenter->build(
			array(
				'workflow'      => 'uncontested_divorce_children_nyc',
				'facts'         => array(
					'spouse_agrees' => true,
					'child_count'   => 1,
					'county'        => 'Queens',
				),
				'stage_context' => array(
					'forms_visible' => true,
					'current_stage' => array(
						'id'    => 'calendar',
						'title' => 'Final Papers & Calendar',
					),
					'stage_forms'   => array(
						array(
							'code'     => 'UD-4',
							'title'    => 'Affidavit of Plaintiff',
							'required' => true,
						),
						array(
							'code'     => 'UD-5',
							'title'    => 'Note of Issue',
							'required' => false,
						),
					),
				),
				'roadmap'       => array(
					'completed_steps' => array(
						array( 'id' => 'commencement', 'title' => 'Commencement' ),
						array( 'id' => 'service', 'title' => 'Service' ),
					),
				),
				'procedural_node' => 'NODE_1010_JUDGMENT',
				'completion'      => 80,
				'court'           => 'supreme_court',
				'issue'           => 'divorce',
			)
		);

		$this->assertSame( 'calendar', $summary['current_stage']['id'] ?? '' );
		$this->assertSame( 'NODE_1010_JUDGMENT', $summary['procedural_node'] ?? '' );
		$this->assertCount( 2, $summary['current_forms'] ?? array() );
		$this->assertContains( 'Commencement', $summary['completed_stages'] ?? array() );
	}

	/**
	 * Prompt text merges case state ahead of conversation notes.
	 */
	public function test_merge_prompt_summary_includes_case_state(): void {
		$presenter = new Case_Summary_Presenter();
		$summary   = $presenter->build(
			array(
				'workflow'      => 'uncontested_divorce_children_nyc',
				'facts'         => array(
					'spouse_agrees' => true,
					'child_count'   => 1,
				),
				'stage_context' => array(
					'forms_visible' => true,
					'current_stage' => array(
						'id'    => 'calendar',
						'title' => 'Final Papers & Calendar',
					),
					'stage_forms'   => array(
						array(
							'code'     => 'UD-4',
							'title'    => 'Affidavit of Plaintiff',
							'required' => true,
						),
					),
				),
			)
		);

		$merged = $presenter->merge_prompt_summary( 'child_count: 1; spouse_agrees: yes', $summary );

		$this->assertStringContainsString( 'Case Summary', $merged );
		$this->assertStringContainsString( 'Current procedural stage', $merged );
		$this->assertStringContainsString( 'UD-4', $merged );
		$this->assertStringContainsString( 'child_count: 1', $merged );
	}

	/**
	 * Legacy stored Case Summary blocks are stripped back to conversation notes.
	 */
	public function test_extract_conversation_notes_strips_case_summary_block(): void {
		$presenter = new Case_Summary_Presenter();
		$legacy    = "Case Summary\nCurrent procedural stage: Service\n\nConversation notes: county: Queens";
		$notes     = $presenter->extract_conversation_notes( $legacy );

		$this->assertSame( 'county: Queens', $notes );
	}

	/**
	 * Completed stages are inferred from procedural node, not chat roadmap.
	 */
	public function test_completed_stages_from_procedural_node(): void {
		$presenter = new Case_Summary_Presenter();
		$summary   = $presenter->build(
			array(
				'workflow'        => 'uncontested_divorce_children_nyc',
				'facts'           => array( 'child_count' => 1 ),
				'procedural_node' => 'NODE_1010_JUDGMENT',
				'stage_context'   => array(
					'current_stage' => array( 'id' => 'calendar', 'title' => 'Final Papers & Calendar' ),
				),
				'roadmap'         => array(
					'completed_steps' => array(),
				),
			)
		);

		$this->assertContains( 'Commencement', $summary['completed_stages'] ?? array() );
		$this->assertContains( 'Service', $summary['completed_stages'] ?? array() );
	}

	/**
	 * Action rows expose current stage for the workspace summary panel.
	 */
	public function test_to_action_rows_includes_current_stage(): void {
		$presenter = new Case_Summary_Presenter();
		$rows      = $presenter->to_action_rows(
			array(
				'current_stage'    => array(
					'id'    => 'service',
					'title' => 'Service',
				),
				'completed_stages' => array( 'Commencement' ),
				'current_forms'    => array(
					array( 'code' => 'UD-3' ),
				),
			)
		);

		$labels = array_column( $rows, 'label' );

		$this->assertContains( 'Current stage', $labels );
		$this->assertContains( 'Completed stages', $labels );
		$this->assertContains( 'Forms for this step', $labels );
	}

	/**
	 * Multi-path commencement shows alternate filing options instead of a flat form list.
	 */
	public function test_to_action_rows_shows_commencement_path_options(): void {
		$presenter = new Case_Summary_Presenter();
		$rows      = $presenter->to_action_rows(
			array(
				'current_stage'    => array(
					'id'    => 'commencement',
					'title' => 'Starting the Case',
				),
				'download_options' => array(
					array(
						'id'         => 'summons_with_notice',
						'title'      => 'Option 1 — Summons With Notice (Form UD-1)',
						'label'      => 'Get Documents (UD-1)',
						'form_codes' => array( 'UD-1' ),
					),
					array(
						'id'         => 'summons_and_complaint',
						'title'      => 'Option 2 — Summons (UD-1a) + Verified Complaint (UD-2)',
						'label'      => 'Get Documents (UD-1A and UD-2)',
						'form_codes' => array( 'UD-1a', 'UD-2' ),
					),
				),
			)
		);

		$this->assertCount( 3, $rows );
		$this->assertSame( 'Current stage', $rows[0]['label'] );
		$this->assertStringContainsString( 'Option 1', (string) ( $rows[1]['label'] ?? '' ) );
		$this->assertSame( 'UD-1', (string) ( $rows[1]['value'] ?? '' ) );
		$this->assertStringContainsString( 'Option 2', (string) ( $rows[2]['label'] ?? '' ) );
		$this->assertSame( 'UD-1A and UD-2', (string) ( $rows[2]['value'] ?? '' ) );
	}
}
