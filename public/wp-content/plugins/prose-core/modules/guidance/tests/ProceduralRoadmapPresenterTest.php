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
					'issue'  => 'divorce',
					'county' => 'Queens',
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
}
