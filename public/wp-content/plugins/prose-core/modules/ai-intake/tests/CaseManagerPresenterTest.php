<?php
/**
 * Case Manager presenter tests.
 *
 * @package ProSeCore
 */

use PHPUnit\Framework\TestCase;
use ProSe\Core\Ai_Intake\Case_Manager_Presenter;
use ProSe\Core\Routing\Workflow_Catalog;

/**
 * Class CaseManagerPresenterTest
 */
class CaseManagerPresenterTest extends TestCase {

	/**
	 * Set up.
	 */
	protected function setUp(): void {
		Workflow_Catalog::reset_cache();
	}

	/**
	 * Snapshot pulls court, matter, stage, and progress from workflow data.
	 */
	public function test_renders_case_snapshot_from_workflow_data(): void {
		$presenter = new Case_Manager_Presenter();
		$snapshot  = $presenter->render_case_snapshot(
			array(
				'workflow'     => 'uncontested_divorce_no_children_nyc',
				'case_summary' => array(
					'workflow'        => 'uncontested_divorce_no_children_nyc',
					'workflow_title'  => 'Uncontested Divorce',
					'court_label'     => 'Supreme Court',
					'current_stage'   => array(
						'id'    => 'service',
						'title' => 'Service of Process',
					),
					'completed_stages' => array( 'Commencement' ),
				),
				'facts' => array(),
			)
		);

		$this->assertStringContainsString( 'Case Dashboard', $snapshot );
		$this->assertStringContainsString( 'Supreme Court', $snapshot );
		$this->assertStringContainsString( 'Service of Process', $snapshot );
		$this->assertStringContainsString( 'stages completed', $snapshot );
	}

	/**
	 * Stage timeline marks completed, current, and upcoming stages.
	 */
	public function test_renders_stage_timeline(): void {
		$presenter = new Case_Manager_Presenter();
		$timeline  = $presenter->render_stage_timeline(
			array(
				'workflow'     => 'uncontested_divorce_no_children_nyc',
				'case_summary' => array(
					'current_stage' => array(
						'id'    => 'service',
						'title' => 'Service of Process',
					),
				),
				'facts' => array(),
			)
		);

		$this->assertStringContainsString( 'Stage Timeline', $timeline );
		$this->assertStringContainsString( 'Current', $timeline );
		$this->assertStringContainsString( 'Service', $timeline );
	}

	/**
	 * Upcoming documents come from the next workflow stage definition.
	 */
	public function test_renders_upcoming_documents(): void {
		$presenter = new Case_Manager_Presenter();
		$block     = $presenter->render_upcoming_documents(
			array(
				'workflow'      => 'uncontested_divorce_no_children_nyc',
				'stage_context' => array(
					'future_stages' => array(
						array(
							'id'    => 'calendar',
							'title' => 'Final Papers & Calendar',
						),
					),
				),
			)
		);

		$this->assertStringContainsString( 'Upcoming Documents', $block );
		$this->assertStringContainsString( 'UD-5', $block );
		$this->assertStringContainsString( 'Final Papers', $block );
	}

	/**
	 * Closing acknowledgments do not receive appended blocks.
	 */
	public function test_skips_append_for_closing_message(): void {
		$presenter = new Case_Manager_Presenter();

		$this->assertFalse( $presenter->should_append( 'You are welcome!', 'thanks!' ) );
	}

	/**
	 * Meaningful replies receive appended snapshot sections.
	 */
	public function test_appends_sections_to_meaningful_reply(): void {
		$presenter = new Case_Manager_Presenter();
		$reply     = $presenter->append_sections(
			'Here is what service means for your case.',
			array(
				'message'      => 'What is service?',
				'workflow'     => 'uncontested_divorce_no_children_nyc',
				'case_summary' => array(
					'workflow'       => 'uncontested_divorce_no_children_nyc',
					'workflow_title' => 'Uncontested Divorce',
					'court_label'    => 'Supreme Court',
					'current_stage'  => array(
						'id'    => 'service',
						'title' => 'Service of Process',
					),
				),
				'stage_context' => array(
					'future_stages' => array(
						array(
							'id'    => 'calendar',
							'title' => 'Final Papers & Calendar',
						),
					),
				),
				'facts' => array(),
				'case_memory' => array(
					'workflow_assessment' => array(
						'status'       => 'confirmed',
						'status_label' => 'Confirmed',
						'workflow_title' => 'Uncontested matrimonial action in NYC Supreme Court where both parties agree to divorce and there are no minor children under 21.',
					),
				),
			)
		);

		$this->assertStringContainsString( 'Here is what service means', $reply );
		$this->assertStringContainsString( 'Case Dashboard', $reply );
		$this->assertStringContainsString( 'Current Assessment', $reply );
		$this->assertStringContainsString( 'Stage Timeline', $reply );
	}
}
