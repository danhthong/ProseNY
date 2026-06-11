<?php
/**
 * Tests for the Timeline Engine: generation, events, status, resolution.
 *
 * Runs database-free against Case_State, mirroring CaseEngineTest.
 *
 * @package ProSeCore
 */

use PHPUnit\Framework\TestCase;
use ProSe\Core\Forms\Classification\Vocabulary;
use ProSe\Core\Forms\Engine\Case_Catalog;
use ProSe\Core\Forms\Engine\Case_Deadline;
use ProSe\Core\Forms\Engine\Case_Service;
use ProSe\Core\Forms\Engine\Case_State;
use ProSe\Core\Forms\Engine\Case_Timeline_Resolver;
use ProSe\Core\Forms\Engine\Deadline_Catalog;
use ProSe\Core\Forms\Engine\Deadline_Generator;
use ProSe\Core\Forms\Engine\Deadline_Status;
use ProSe\Core\Forms\Engine\Timeline_Service;

/**
 * Class TimelineEngineTest
 */
class TimelineEngineTest extends TestCase {

	private const ANCHOR = '2026-01-01 00:00:00';

	/**
	 * Build an in-memory timeline service.
	 *
	 * @return Timeline_Service
	 */
	private function timeline(): Timeline_Service {
		return new Timeline_Service();
	}

	/**
	 * Build an in-memory case service.
	 *
	 * @return Case_Service
	 */
	private function case_service(): Case_Service {
		return new Case_Service();
	}

	/**
	 * Extract deadline keys from a deadline list.
	 *
	 * @param Case_Deadline[] $deadlines Deadlines.
	 * @return string[]
	 */
	private function keys( array $deadlines ): array {
		return array_map(
			static fn( Case_Deadline $deadline ): string => $deadline->deadline_key(),
			$deadlines
		);
	}

	/**
	 * Find a deadline by key.
	 *
	 * @param Case_Deadline[] $deadlines Deadlines.
	 * @param string          $key       Deadline key.
	 * @return Case_Deadline|null
	 */
	private function find_deadline( array $deadlines, string $key ): ?Case_Deadline {
		foreach ( $deadlines as $deadline ) {
			if ( $deadline->deadline_key() === $key ) {
				return $deadline;
			}
		}

		return null;
	}

	/**
	 * Uncontested divorce: answer due after service completed.
	 */
	public function test_uncontested_divorce_timeline(): void {
		$case_service = $this->case_service();
		$timeline     = $this->timeline();
		$state        = $case_service->create_case( Vocabulary::WF_UNCONTESTED_DIVORCE );

		$now       = strtotime( '2026-01-01 12:00:00' );
		$generated = $timeline->generate( $state, $now );

		$this->assertSame( array(), $this->keys( $generated ) );

		$case_service->record_event( $state, Case_Catalog::EVENT_SERVICE_COMPLETED );
		$created = $timeline->handle_event(
			$state,
			Case_Catalog::EVENT_SERVICE_COMPLETED,
			'2026-01-05 00:00:00',
			$now
		);

		$this->assertContains( 'ANSWER_DUE', $this->keys( $created ) );

		$answer = $this->find_deadline( $created, 'ANSWER_DUE' );
		$this->assertNotNull( $answer );
		$this->assertSame( '2026-01-25 00:00:00', $answer->due_date() );
		$this->assertSame( Deadline_Catalog::ACTION_FILE_ANSWER, $answer->next_action() );
	}

	/**
	 * Contested divorce: case-filed and service/answer deadlines.
	 */
	public function test_contested_divorce_timeline(): void {
		$case_service = $this->case_service();
		$timeline     = $this->timeline();
		$state        = $case_service->create_case( Vocabulary::WF_CONTESTED_DIVORCE );

		$now       = strtotime( self::ANCHOR );
		$generated = $timeline->generate( $state, $now );
		$keys      = $this->keys( $generated );

		$this->assertContains( 'PRELIMINARY_CONFERENCE', $keys );
		$this->assertContains( 'RJI_DUE', $keys );

		$prelim = $this->find_deadline( $generated, 'PRELIMINARY_CONFERENCE' );
		$this->assertNotNull( $prelim );
		$this->assertSame( '2026-01-31 00:00:00', $prelim->due_date() );

		$case_service->record_event( $state, Case_Catalog::EVENT_SERVICE_COMPLETED );
		$after_service = $timeline->handle_event(
			$state,
			Case_Catalog::EVENT_SERVICE_COMPLETED,
			'2026-01-10 00:00:00',
			$now
		);

		$this->assertContains( 'ANSWER_DUE', $this->keys( $after_service ) );

		$case_service->record_event( $state, Case_Catalog::EVENT_ANSWER_RECEIVED );
		$after_answer = $timeline->handle_event(
			$state,
			Case_Catalog::EVENT_ANSWER_RECEIVED,
			'2026-01-20 00:00:00',
			$now
		);

		$discovery = $this->find_deadline( $after_answer, 'DISCOVERY_DEADLINE' );
		$this->assertNotNull( $discovery );
		$this->assertSame( '2026-03-21 00:00:00', $discovery->due_date() );
		$this->assertSame(
			Deadline_Catalog::ACTION_SUBMIT_FINANCIAL_DISCLOSURE,
			$discovery->next_action()
		);
	}

	/**
	 * Custody: hearing prep deadline on hearing scheduled.
	 */
	public function test_custody_timeline(): void {
		$case_service = $this->case_service();
		$timeline     = $this->timeline();
		$state        = $case_service->create_case( Vocabulary::WF_CUSTODY );

		$now = strtotime( self::ANCHOR );

		$case_service->record_event( $state, Case_Catalog::EVENT_HEARING_SCHEDULED );
		$created = $timeline->handle_event(
			$state,
			Case_Catalog::EVENT_HEARING_SCHEDULED,
			'2026-02-01 00:00:00',
			$now
		);

		$this->assertContains( 'CUSTODY_HEARING_PREP', $this->keys( $created ) );

		$hearing = $this->find_deadline( $created, 'CUSTODY_HEARING_PREP' );
		$this->assertNotNull( $hearing );
		$this->assertSame( Deadline_Catalog::ACTION_SCHEDULE_HEARING, $hearing->next_action() );
	}

	/**
	 * Child support: financial disclosure on case filed.
	 */
	public function test_child_support_timeline(): void {
		$timeline = $this->timeline();
		$state    = $this->case_service()->create_case( Vocabulary::WF_CHILD_SUPPORT );

		$now       = strtotime( self::ANCHOR );
		$generated = $timeline->generate( $state, $now );

		$this->assertContains( 'FINANCIAL_DISCLOSURE', $this->keys( $generated ) );

		$disclosure = $this->find_deadline( $generated, 'FINANCIAL_DISCLOSURE' );
		$this->assertNotNull( $disclosure );
		$this->assertSame( '2026-01-31 00:00:00', $disclosure->due_date() );
		$this->assertSame(
			Deadline_Catalog::ACTION_SUBMIT_FINANCIAL_DISCLOSURE,
			$disclosure->next_action()
		);
	}

	/**
	 * Order of protection: OP hearing on case filed.
	 */
	public function test_order_of_protection_timeline(): void {
		$case_service = $this->case_service();
		$timeline     = $this->timeline();
		$state        = $case_service->create_case( Vocabulary::WF_ORDER_OF_PROTECTION );

		$now       = strtotime( self::ANCHOR );
		$generated = $timeline->generate( $state, $now );

		$this->assertContains( 'OP_HEARING', $this->keys( $generated ) );

		$case_service->record_event( $state, Case_Catalog::EVENT_HEARING_SCHEDULED );
		$after_hearing = $timeline->handle_event(
			$state,
			Case_Catalog::EVENT_HEARING_SCHEDULED,
			'2026-02-15 00:00:00',
			$now
		);

		$this->assertContains( 'OP_FINAL_HEARING', $this->keys( $after_hearing ) );
	}

	/**
	 * Event-driven recalculation replaces prior deadline for same key+trigger.
	 */
	public function test_deadline_recalculation_on_event(): void {
		$timeline = $this->timeline();
		$state    = $this->case_service()->create_case( Vocabulary::WF_UNCONTESTED_DIVORCE );

		$now = strtotime( self::ANCHOR );

		$first = $timeline->handle_event(
			$state,
			Case_Catalog::EVENT_SERVICE_COMPLETED,
			'2026-01-05 00:00:00',
			$now
		);

		$second = $timeline->handle_event(
			$state,
			Case_Catalog::EVENT_SERVICE_COMPLETED,
			'2026-01-10 00:00:00',
			$now
		);

		$answer_first  = $this->find_deadline( $first, 'ANSWER_DUE' );
		$answer_second = $this->find_deadline( $second, 'ANSWER_DUE' );

		$this->assertNotNull( $answer_first );
		$this->assertNotNull( $answer_second );
		$this->assertSame( '2026-01-25 00:00:00', $answer_first->due_date() );
		$this->assertSame( '2026-01-30 00:00:00', $answer_second->due_date() );

		$resolved = $timeline->build_timeline( $state, $now );
		$this->assertCount( 1, $resolved->upcoming_deadlines() );
		$this->assertSame( '2026-01-30 00:00:00', $resolved->upcoming_deadlines()[0]->due_date() );
	}

	/**
	 * Status transitions for all open statuses.
	 */
	public function test_status_transitions(): void {
		$due_future  = '2026-06-01 00:00:00';
		$due_soon    = '2026-01-25 00:00:00';
		$due_today   = '2026-01-15 00:00:00';
		$due_past    = '2026-01-01 00:00:00';
		$now         = strtotime( '2026-01-15 12:00:00' );

		$this->assertSame( Deadline_Status::PENDING, Deadline_Status::resolve( $due_future, false, false, $now ) );
		$this->assertSame( Deadline_Status::UPCOMING, Deadline_Status::resolve( $due_soon, false, false, $now ) );
		$this->assertSame( Deadline_Status::DUE_TODAY, Deadline_Status::resolve( $due_today, false, false, $now ) );
		$this->assertSame( Deadline_Status::OVERDUE, Deadline_Status::resolve( $due_past, false, false, $now ) );
		$this->assertSame( Deadline_Status::COMPLETED, Deadline_Status::resolve( $due_past, true, false, $now ) );
		$this->assertSame( Deadline_Status::CANCELLED, Deadline_Status::resolve( $due_future, false, true, $now ) );
	}

	/**
	 * Overdue deadlines are bucketed correctly.
	 */
	public function test_overdue_detection(): void {
		$resolver = new Case_Timeline_Resolver();
		$now      = strtotime( '2026-02-01 00:00:00' );

		$deadlines = array(
			new Case_Deadline(
				'ANSWER_DUE',
				'Answer Due',
				'2026-01-20 00:00:00',
				Case_Catalog::EVENT_SERVICE_COMPLETED,
				'2026-01-01 00:00:00',
				'calendar',
				Deadline_Status::PENDING,
				false,
				Deadline_Catalog::ACTION_FILE_ANSWER
			),
			new Case_Deadline(
				'DISCOVERY_DEADLINE',
				'Discovery Deadline',
				'2026-03-01 00:00:00',
				Case_Catalog::EVENT_ANSWER_RECEIVED,
				'2026-01-01 00:00:00',
				'calendar',
				Deadline_Status::PENDING,
				false,
				Deadline_Catalog::ACTION_SUBMIT_FINANCIAL_DISCLOSURE
			),
		);

		$timeline = $resolver->resolve( $deadlines, $now );

		$this->assertCount( 1, $timeline->overdue_deadlines() );
		$this->assertSame( 'ANSWER_DUE', $timeline->overdue_deadlines()[0]->deadline_key() );
		$this->assertSame( Deadline_Status::OVERDUE, $timeline->overdue_deadlines()[0]->status() );
		$this->assertCount( 1, $timeline->upcoming_deadlines() );
	}

	/**
	 * Next actions are derived from overdue then upcoming deadlines.
	 */
	public function test_next_actions_calculated(): void {
		$timeline = $this->timeline();
		$state    = $this->case_service()->create_case( Vocabulary::WF_CONTESTED_DIVORCE );

		$now = strtotime( '2026-02-20 00:00:00' );

		$timeline->generate( $state, $now );
		$timeline->handle_event(
			$state,
			Case_Catalog::EVENT_SERVICE_COMPLETED,
			'2026-01-01 00:00:00',
			$now
		);
		$timeline->handle_event(
			$state,
			Case_Catalog::EVENT_ANSWER_RECEIVED,
			'2026-01-05 00:00:00',
			$now
		);

		$resolved = $timeline->build_timeline( $state, $now );
		$actions  = $resolved->next_actions();

		$this->assertNotEmpty( $actions );
		$this->assertContains( Deadline_Catalog::ACTION_FILE_ANSWER, $actions );
		$this->assertContains( Deadline_Catalog::ACTION_SUBMIT_FINANCIAL_DISCLOSURE, $actions );
		$this->assertSame( Deadline_Catalog::ACTION_FILE_ANSWER, $actions[0] );
	}

	/**
	 * Due date computation handles calendar and court days.
	 */
	public function test_due_date_computation(): void {
		$generator = new Deadline_Generator();

		$this->assertSame(
			'2026-01-21 00:00:00',
			$generator->compute_due_date( self::ANCHOR, 20, 'after', 'calendar' )
		);

		$this->assertSame(
			'2025-12-12 00:00:00',
			$generator->compute_due_date( self::ANCHOR, 20, 'before', 'calendar' )
		);
	}

	/**
	 * Resolved timeline exposes the documented shape.
	 */
	public function test_resolved_timeline_shape(): void {
		$timeline = $this->timeline();
		$state    = $this->case_service()->create_case( Vocabulary::WF_CHILD_SUPPORT );
		$resolved = $timeline->build_timeline( $state, strtotime( self::ANCHOR ) )->to_array();

		foreach ( array( 'upcoming_deadlines', 'overdue_deadlines', 'completed_deadlines', 'next_actions' ) as $key ) {
			$this->assertArrayHasKey( $key, $resolved );
		}
	}

	/**
	 * Node next-action map returns expected labels.
	 */
	public function test_node_next_action_map(): void {
		$this->assertSame(
			Deadline_Catalog::ACTION_SERVE_DOCUMENTS,
			Deadline_Catalog::next_action_for_node(
				Vocabulary::WF_CONTESTED_DIVORCE,
				Vocabulary::NODE_1001_DIVORCE_FILED
			)
		);

		$this->assertSame(
			Deadline_Catalog::ACTION_FILE_ANSWER,
			Deadline_Catalog::next_action_for_node(
				Vocabulary::WF_CONTESTED_DIVORCE,
				Vocabulary::NODE_1002_SERVICE_COMPLETE
			)
		);
	}
}
