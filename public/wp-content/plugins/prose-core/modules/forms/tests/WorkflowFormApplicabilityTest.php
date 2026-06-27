<?php
/**
 * Workflow form applicability tests.
 *
 * @package ProSeCore
 */

use PHPUnit\Framework\TestCase;
use ProSe\Core\Forms\Engine\Stage_Form_Presenter;
use ProSe\Core\Forms\Engine\Workflow_Form_Applicability_Service;
use ProSe\Core\Forms\Engine\Workflow_Progression_Service;
use ProSe\Core\Routing\Workflow_Catalog;

/**
 * Class WorkflowFormApplicabilityTest
 */
class WorkflowFormApplicabilityTest extends TestCase {

	/**
	 * @var Workflow_Form_Applicability_Service
	 */
	private Workflow_Form_Applicability_Service $service;

	/**
	 * Set up.
	 */
	protected function setUp(): void {
		Workflow_Catalog::reset_cache();
		$this->service = new Workflow_Form_Applicability_Service();
	}

	/**
	 * Child-support forms are skipped when there are no children.
	 */
	public function test_child_forms_skipped_without_children(): void {
		$result = $this->service->evaluate(
			array(
				'code'          => 'UD-8(3)',
				'required_when' => 'has_minor_children',
			),
			'uncontested_divorce_children_nyc',
			'calendar',
			array(
				'children'           => false,
				'has_minor_children' => false,
				'child_count'        => 0,
			)
		);

		$this->assertFalse( $result['applicable'] );
		$this->assertStringContainsString( 'no children', strtolower( $result['reason'] ) );
	}

	/**
	 * UD-4 is skipped for civil marriages via required_when.
	 */
	public function test_ud4_skipped_for_judge_marriage(): void {
		$result = $this->service->evaluate(
			array(
				'code'          => 'UD-4',
				'required_when' => 'religious_barrier_exists',
			),
			'uncontested_divorce_no_children_nyc',
			'calendar',
			array( 'barriers_to_remarriage' => false )
		);

		$this->assertFalse( $result['applicable'] );
		$this->assertStringContainsString( 'UD-4', $result['reason'] );
	}

	/**
	 * Commencement papers are not regenerated for an existing filed case.
	 */
	public function test_commencement_forms_skipped_for_existing_case(): void {
		$result = $this->service->evaluate(
			array(
				'code'          => 'UD-1',
				'required_when' => 'always',
			),
			'uncontested_divorce_no_children_nyc',
			'commencement',
			array(
				'active_divorce' => true,
				'case_status'    => 'FILED',
			)
		);

		$this->assertFalse( $result['applicable'] );
		$this->assertStringContainsString( 'already been started', $result['reason'] );
	}

	/**
	 * Default divorce excludes defendant affidavit via required_when on uncontested only.
	 */
	public function test_ud7_skipped_for_default_divorce(): void {
		$result = $this->service->evaluate(
			array(
				'code'          => 'UD-7',
				'required_when' => 'defendant_executes_affirmation',
			),
			'default_divorce_nyc',
			'judgment',
			array( 'spouse_responded' => false )
		);

		$this->assertFalse( $result['applicable'] );
		$this->assertStringContainsString( 'default divorce', strtolower( $result['reason'] ) );
	}

	/**
	 * Calendar stage for no-children case excludes child worksheet from package.
	 */
	public function test_calendar_stage_filters_child_worksheet_for_no_children_workflow(): void {
		$progression = new Workflow_Progression_Service();
		$forms       = $progression->get_stage_forms(
			'uncontested_divorce_no_children_nyc',
			'calendar',
			array(
				'children'           => false,
				'has_minor_children' => false,
			)
		);
		$codes       = array_column( $forms, 'code' );

		$this->assertContains( 'UD-5', $codes );
		$this->assertNotContains( 'UD-8(3)', $codes );
	}

	/**
	 * Existing filed case at commencement stage does not offer UD-1/UD-2.
	 */
	public function test_presenter_skips_commencement_forms_for_filed_case(): void {
		$presenter = new Stage_Form_Presenter();
		$context   = $presenter->present(
			array(
				'workflow'        => 'uncontested_divorce_no_children_nyc',
				'facts'           => array(
					'spouse_agrees'  => true,
					'children'       => false,
					'active_divorce' => true,
					'case_status'    => 'FILED',
				),
				'intake_complete' => true,
				'current_node'    => 'NODE_1001_DIVORCE_FILED',
			)
		);

		$codes = array_column( $context['stage_forms'], 'code' );

		$this->assertNotContains( 'UD-1', $codes );
		$this->assertNotContains( 'UD-2', $codes );
		$this->assertNotEmpty( $context['skipped_forms'] );
	}

	/**
	 * Workflow JSON required_when is loaded for children calendar forms.
	 */
	public function test_children_calendar_includes_ud8_when_children_known(): void {
		$progression = new Workflow_Progression_Service();
		$forms       = $progression->get_stage_forms(
			'uncontested_divorce_children_nyc',
			'calendar',
			array(
				'children'    => true,
				'child_count' => 2,
			)
		);
		$codes       = array_column( $forms, 'code' );

		$this->assertContains( 'UD-8(3)', $codes );
		$this->assertContains( 'UD-8a', $codes );
	}
}
