<?php
/**
 * Procedural roadmap presenter tests.
 *
 * @package ProSeCore
 */

use PHPUnit\Framework\TestCase;
use ProSe\Core\Guidance\Procedural_Roadmap_Presenter;
use ProSe\Core\Routing\Workflow_Catalog;

/**
 * Class ProceduralRoadmapPresenterTest
 */
class ProceduralRoadmapPresenterTest extends TestCase {

	/**
	 * Presenter.
	 *
	 * @var Procedural_Roadmap_Presenter
	 */
	private Procedural_Roadmap_Presenter $presenter;

	/**
	 * Set up.
	 */
	protected function setUp(): void {
		Workflow_Catalog::reset_cache();
		$this->presenter = new Procedural_Roadmap_Presenter();
	}

	/**
	 * Empty input returns hidden roadmap.
	 */
	public function test_empty_input_returns_hidden_roadmap(): void {
		$roadmap = $this->presenter->present(
			array(
				'issue'    => '',
				'facts'    => array(),
				'workflow' => '',
			)
		);

		$this->assertFalse( $roadmap['show'] );
	}

	/**
	 * Divorce intake roadmap includes steps when issue is known.
	 */
	public function test_divorce_intake_roadmap(): void {
		$roadmap = $this->presenter->present(
			array(
				'issue'              => 'divorce',
				'facts'              => array(
					'issue'                   => 'divorce',
					'county'                  => 'Queens',
					'residency_qualification' => '1_year_state',
				),
				'workflow'           => '',
				'missing_fields'     => array(
					array(
						'field'    => 'spouse_agrees',
						'question' => 'Do you and your spouse agree on major issues?',
					),
				),
				'completion'         => 25,
				'workflow_resolved'  => false,
				'intake_complete'    => false,
			)
		);

		$this->assertTrue( $roadmap['show'] );
		$this->assertSame( 'intake', $roadmap['mode'] );
		$this->assertNotEmpty( $roadmap['completed_steps'] );
		$this->assertNotEmpty( $roadmap['current_focus']['title'] );
		$this->assertNotEmpty( $roadmap['suggested_next_question'] );
		$this->assertNotEmpty( $roadmap['fingerprint'] );
	}

	/**
	 * Dashboard summary excludes full step lists.
	 */
	public function test_to_summary_excludes_full_steps(): void {
		$roadmap = $this->presenter->present(
			array(
				'issue'             => 'divorce',
				'facts'             => array( 'issue' => 'divorce' ),
				'workflow'          => '',
				'completion'        => 10,
				'workflow_resolved' => false,
			)
		);

		$summary = $this->presenter->to_summary( $roadmap, 'https://example.test/workspace' );

		$this->assertTrue( $summary['show'] );
		$this->assertArrayNotHasKey( 'completed_steps', $summary );
		$this->assertArrayNotHasKey( 'upcoming_steps', $summary );
		$this->assertArrayNotHasKey( 'required_forms', $summary );
		$this->assertSame( 'https://example.test/workspace', $summary['continue_case_url'] );
	}

	/**
	 * Fingerprint remains stable when only non-routing facts change.
	 */
	public function test_fingerprint_stable_for_non_routing_changes(): void {
		$base_input = array(
			'issue'             => 'divorce',
			'facts'             => array(
				'issue'  => 'divorce',
				'county' => 'Queens',
			),
			'workflow'          => '',
			'completion'        => 20,
			'workflow_resolved' => false,
			'intake_complete'   => false,
			'stage_context'     => array( 'forms_visible' => false ),
		);

		$first = $this->presenter->present( $base_input );

		$second_input              = $base_input;
		$second_input['facts']['plaintiff_information'] = 'Jane Doe';
		$second_input['completion'] = 22;

		$second = $this->presenter->present( $second_input );

		$this->assertSame( $first['fingerprint'], $second['fingerprint'] );
	}

	/**
	 * Fingerprint changes when routing-critical facts change.
	 */
	public function test_fingerprint_changes_for_routing_fact(): void {
		$first = $this->presenter->present(
			array(
				'issue'             => 'divorce',
				'facts'             => array( 'issue' => 'divorce' ),
				'workflow'          => '',
				'workflow_resolved' => false,
			)
		);

		$second = $this->presenter->present(
			array(
				'issue'             => 'divorce',
				'facts'             => array(
					'issue'  => 'divorce',
					'county' => 'Bronx',
				),
				'workflow'          => '',
				'workflow_resolved' => false,
			)
		);

		$this->assertNotSame( $first['fingerprint'], $second['fingerprint'] );
	}

	/**
	 * Workflow progress reflects procedural stage position, not intake completion percent.
	 */
	public function test_workflow_progress_uses_stage_not_intake_completion(): void {
		$stage = ( new \ProSe\Core\Forms\Engine\Stage_Form_Presenter() )->present(
			array(
				'workflow'        => 'uncontested_divorce_children_nyc',
				'facts'           => array(
					'spouse_agrees' => true,
					'child_count'   => 1,
					'county'        => 'Queens',
				),
				'intake_complete' => true,
				'issue'           => 'divorce',
				'current_node'    => 'NODE_1010_JUDGMENT',
			)
		);

		$roadmap = $this->presenter->present(
			array(
				'issue'                 => 'divorce',
				'facts'                 => array(
					'spouse_agrees' => true,
					'child_count'   => 1,
					'county'        => 'Queens',
				),
				'workflow'              => 'uncontested_divorce_children_nyc',
				'workflow_resolved'     => true,
				'intake_complete'       => true,
				'completion'            => 7,
				'stage_context'         => $stage,
				'procedural_node'       => 'NODE_1010_JUDGMENT',
				'procedural_navigator'  => array(),
				'missing_fields'        => array(),
			)
		);

		$this->assertSame( 'workflow', $roadmap['mode'] );
		$this->assertSame( 'calendar', $roadmap['current_stage']['id'] ?? '' );
		$this->assertGreaterThan( 50, (int) ( $roadmap['progress_percentage'] ?? 0 ) );
		$this->assertNotSame( 7, (int) ( $roadmap['progress_percentage'] ?? 0 ) );
	}

	/**
	 * Change detection respects stored fingerprint.
	 */
	public function test_resolve_with_change_detection(): void {
		$input = array(
			'issue'             => 'divorce',
			'facts'             => array(
				'issue'  => 'divorce',
				'county' => 'Queens',
			),
			'workflow'          => '',
			'workflow_resolved' => false,
		);

		$initial = $this->presenter->resolve_with_change_detection( '', $input );
		$this->assertTrue( $initial['changed'] );

		$repeat = $this->presenter->resolve_with_change_detection( (string) $initial['fingerprint'], $input );
		$this->assertFalse( $repeat['changed'] );
	}

	/**
	 * Post-intake lifecycle overlay replaces intake roadmap steps.
	 */
	public function test_lifecycle_overlay_on_completed_intake(): void {
		$lifecycle = array(
			'show'       => true,
			'stage'      => 'awaiting_answer',
			'milestones' => array(
				array( 'id' => 'filed', 'label' => 'Filed', 'status' => 'completed' ),
				array( 'id' => 'served', 'label' => 'Served', 'status' => 'completed' ),
				array( 'id' => 'awaiting_answer', 'label' => 'Answer', 'status' => 'current' ),
			),
			'next_actions' => array(),
			'deadlines'    => array(),
		);

		$roadmap = $this->presenter->present(
			array(
				'issue'             => 'divorce',
				'facts'             => array(
					'issue'  => 'divorce',
					'county' => 'Queens',
				),
				'workflow'          => 'contested_divorce_nyc',
				'workflow_resolved' => true,
				'intake_complete'   => true,
				'completion'        => 100,
				'lifecycle'         => $lifecycle,
			)
		);

		$this->assertSame( 'awaiting_answer', $roadmap['lifecycle_stage'] );
		$this->assertNotEmpty( $roadmap['current_focus']['title'] );
		$this->assertSame( 'Answer', $roadmap['current_focus']['title'] );
	}

	/**
	 * Fingerprint changes when lifecycle stage changes.
	 */
	public function test_fingerprint_changes_on_lifecycle_stage(): void {
		$base = array(
			'issue'             => 'divorce',
			'facts'             => array(
				'issue'  => 'divorce',
				'county' => 'Queens',
			),
			'workflow'          => 'contested_divorce_nyc',
			'workflow_resolved' => true,
			'intake_complete'   => true,
			'completion'        => 100,
		);

		$before = $this->presenter->present(
			array_merge(
				$base,
				array(
					'lifecycle' => array(
						'show'       => true,
						'stage'      => 'filed',
						'milestones' => array(
							array( 'id' => 'filed', 'label' => 'Filed', 'status' => 'current' ),
						),
					),
				)
			)
		);

		$after = $this->presenter->present(
			array_merge(
				$base,
				array(
					'lifecycle' => array(
						'show'       => true,
						'stage'      => 'served',
						'milestones' => array(
							array( 'id' => 'served', 'label' => 'Served', 'status' => 'current' ),
						),
					),
				)
			)
		);

		$this->assertNotSame( $before['fingerprint'], $after['fingerprint'] );
	}
}
