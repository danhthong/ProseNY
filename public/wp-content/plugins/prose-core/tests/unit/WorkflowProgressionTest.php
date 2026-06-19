<?php
/**
 * Workflow progression service tests.
 *
 * @package ProSeCore
 */

use PHPUnit\Framework\TestCase;
use ProSe\Core\Forms\Classification\Vocabulary;
use ProSe\Core\Forms\Engine\Case_Catalog;
use ProSe\Core\Forms\Engine\Workflow_Progression_Service;
use ProSe\Core\Routing\Workflow_Catalog;

/**
 * Class WorkflowProgressionTest
 */
class WorkflowProgressionTest extends TestCase {

	/**
	 * @var Workflow_Progression_Service
	 */
	private Workflow_Progression_Service $progression;

	/**
	 * Set up.
	 */
	protected function setUp(): void {
		Workflow_Catalog::reset_cache();
		$this->progression = new Workflow_Progression_Service();
	}

	/**
	 * Contested divorce exposes the full stage and node graph from JSON.
	 */
	public function test_contested_divorce_stage_graph(): void {
		$key = 'contested_divorce_nyc';

		$this->assertSame(
			array(
				'commencement',
				'service',
				'answer',
				'preliminary_conference',
				'discovery',
				'compliance_conference',
				'settlement',
				'trial',
				'judgment',
			),
			$this->progression->get_stages( $key )
		);

		$this->assertCount( 9, $this->progression->get_node_sequence( $key ) );
		$this->assertSame(
			'commencement',
			$this->progression->get_current_stage( $key, Vocabulary::NODE_1001_DIVORCE_FILED )
		);
		$this->assertSame(
			'service',
			$this->progression->get_next_stage( $key, 'commencement' )
		);
	}

	/**
	 * Contested divorce advances from compliance conference to settlement via edges.
	 */
	public function test_contested_divorce_settlement_branch(): void {
		$key = 'contested_divorce_nyc';

		$node = $this->progression->advance(
			$key,
			Vocabulary::NODE_1007_COMPLIANCE_CONFERENCE,
			Case_Catalog::COND_EVENT,
			Case_Catalog::EVENT_SETTLEMENT_REACHED
		);

		$this->assertSame( Vocabulary::NODE_1008_SETTLEMENT, $node );

		$node = $this->progression->advance(
			$key,
			Vocabulary::NODE_1008_SETTLEMENT,
			Case_Catalog::COND_EVENT,
			Case_Catalog::EVENT_JUDGMENT_ENTERED
		);

		$this->assertSame( Vocabulary::NODE_1010_JUDGMENT, $node );
	}

	/**
	 * Uncontested divorce resolves enum variants using intake context.
	 */
	public function test_uncontested_divorce_enum_resolution(): void {
		$this->assertSame(
			'uncontested_divorce_children_nyc',
			$this->progression->resolve_workflow_key( Vocabulary::WF_UNCONTESTED_DIVORCE, array( 'children' => true ) )
		);

		$this->assertSame(
			'uncontested_divorce_no_children_nyc',
			$this->progression->resolve_workflow_key( Vocabulary::WF_UNCONTESTED_DIVORCE, array( 'children' => false ) )
		);
	}

	/**
	 * Stage forms are read from the same JSON used by the package builder.
	 */
	public function test_commencement_forms_for_contested_divorce(): void {
		$forms = $this->progression->get_stage_forms( 'contested_divorce_nyc', 'commencement' );
		$codes = array_column( $forms, 'code' );

		$this->assertContains( 'UD-1', $codes );
		$this->assertContains( 'UD-2', $codes );
	}
}
