<?php
/**
 * Stage Form Presenter tests.
 *
 * @package ProSeCore
 */

use PHPUnit\Framework\TestCase;
use ProSe\Core\Forms\Engine\Stage_Form_Presenter;
use ProSe\Core\Routing\Workflow_Catalog;

/**
 * Class StageFormPresenterTest
 */
class StageFormPresenterTest extends TestCase {

	/**
	 * Presenter under test.
	 *
	 * @var Stage_Form_Presenter
	 */
	private Stage_Form_Presenter $presenter;

	/**
	 * Set up.
	 */
	protected function setUp(): void {
		Workflow_Catalog::reset_cache();
		$this->presenter = new Stage_Form_Presenter();
	}

	/**
	 * Divorce opener with no facts hides all forms.
	 */
	public function test_no_forms_before_workflow_resolution(): void {
		$context = $this->presenter->present(
			array(
				'workflow'        => '',
				'facts'           => array( 'issue' => 'divorce' ),
				'intake_complete' => false,
			)
		);

		$this->assertFalse( $context['forms_visible'] );
		$this->assertSame( array(), $context['stage_forms'] );
		$this->assertArrayHasKey( 'next_action', $context );
	}

	/**
	 * Resolved workflow before intake complete explains case type without forms.
	 */
	public function test_no_forms_when_workflow_resolved_but_intake_incomplete(): void {
		$context = $this->presenter->present(
			array(
				'workflow'        => 'uncontested_divorce_no_children_nyc',
				'facts'           => array(
					'spouse_agrees' => true,
					'children'      => false,
				),
				'intake_complete' => false,
			)
		);

		$this->assertFalse( $context['forms_visible'] );
		$this->assertSame( array(), $context['stage_forms'] );
		$this->assertSame( 'case_type', $context['next_action']['type'] );
	}

	/**
	 * After intake complete only commencement forms are visible.
	 */
	public function test_commencement_forms_only_after_intake_complete(): void {
		$context = $this->presenter->present(
			array(
				'workflow'        => 'uncontested_divorce_no_children_nyc',
				'facts'           => array(
					'spouse_agrees' => true,
					'children'      => false,
					'county'        => 'Queens',
				),
				'intake_complete' => true,
			)
		);

		$this->assertTrue( $context['forms_visible'] );
		$this->assertSame( 'commencement', $context['current_stage']['id'] );
		$codes = array_column( $context['stage_forms'], 'code' );
		$this->assertContains( 'UD-1', $codes );
		$this->assertContains( 'UD-2', $codes );
		$this->assertNotContains( 'UD-3', $codes );
		$this->assertNotContains( 'UD-11', $codes );
	}

	/**
	 * Stage advancement unlocks service forms.
	 */
	public function test_service_stage_after_commencement_complete(): void {
		$workflow = 'uncontested_divorce_no_children_nyc';
		$facts    = array(
			'spouse_agrees' => true,
			'children'      => false,
		);
		$node     = 'NODE_1001_DIVORCE_FILED';
		$advanced = $this->presenter->advance_after_stage( $workflow, $node, 'commencement', $facts );

		$context = $this->presenter->present(
			array(
				'workflow'        => $workflow,
				'facts'           => $facts,
				'intake_complete' => true,
				'current_node'    => $advanced,
			)
		);

		$this->assertSame( 'service', $context['current_stage']['id'] );
		$codes = array_column( $context['stage_forms'], 'code' );
		$this->assertContains( 'UD-3', $codes );
		$this->assertNotContains( 'UD-1', $codes );
	}

	/**
	 * Current stage form codes helper returns empty when gated.
	 */
	public function test_current_stage_form_codes_empty_when_gated(): void {
		$codes = $this->presenter->current_stage_form_codes(
			array(
				'workflow'        => 'uncontested_divorce_no_children_nyc',
				'intake_complete' => false,
			)
		);

		$this->assertSame( array(), $codes );
	}
}
