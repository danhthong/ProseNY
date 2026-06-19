<?php
/**
 * Case timeline presenter tests.
 *
 * @package ProSeCore
 */

use PHPUnit\Framework\TestCase;
use ProSe\Core\Intake\Case_Timeline_Presenter;
use ProSe\Core\Routing\Workflow_Catalog;

/**
 * Class CaseTimelinePresenterTest
 */
class CaseTimelinePresenterTest extends TestCase {

	/**
	 * Presenter under test.
	 *
	 * @var Case_Timeline_Presenter
	 */
	private Case_Timeline_Presenter $presenter;

	/**
	 * Set up.
	 */
	protected function setUp(): void {
		Workflow_Catalog::reset_cache();
		$this->presenter = new Case_Timeline_Presenter();
	}

	/**
	 * Empty profile returns pending timeline.
	 */
	public function test_empty_profile_returns_pending_timeline(): void {
		$timeline = $this->presenter->from_case_profile( array() );

		$this->assertSame( 'pending', $timeline['status'] );
		$this->assertSame( array(), $timeline['stages'] );
		$this->assertSame( array(), $timeline['deadlines'] );
	}

	/**
	 * Contested divorce exposes workflow stages and deadlines.
	 */
	public function test_contested_divorce_timeline_has_stages_and_deadlines(): void {
		$timeline = $this->presenter->from_case_profile(
			array(
				'workflow' => 'contested_divorce_nyc',
				'facts'    => array(
					'children'      => true,
					'spouse_agrees' => false,
					'county'        => 'Queens',
				),
			)
		);

		$this->assertSame( 'contested_divorce_nyc', $timeline['workflow'] );
		$this->assertNotEmpty( $timeline['stages'] );
		$this->assertNotEmpty( $timeline['deadlines'] );
		$this->assertArrayHasKey( 'current_stage', $timeline );
		$this->assertContains( 'commencement', array_column( $timeline['stages'], 'id' ) );
	}

	/**
	 * Service event adds answer deadline.
	 */
	public function test_service_event_adds_answer_deadline(): void {
		$timeline = $this->presenter->from_case_profile(
			array(
				'workflow' => 'uncontested_divorce_no_children_nyc',
				'facts'    => array(
					'children'      => false,
					'spouse_agrees' => true,
				),
			),
			array(
				array(
					'type'        => 'SERVICE_COMPLETED',
					'occurred_at' => '2026-01-05 00:00:00',
				),
			)
		);

		$keys = array_column( $timeline['deadlines'], 'deadline_key' );
		$this->assertContains( 'ANSWER_DUE', $keys );
	}
}
