<?php
/**
 * Tests for the Case Engine: create, events, package completion, progress.
 *
 * Runs database-free against the Case_State aggregate, mirroring the
 * Routing Engine tests.
 *
 * @package ProSeCore
 */

use PHPUnit\Framework\TestCase;
use ProSe\Core\Forms\Classification\Vocabulary;
use ProSe\Core\Forms\Engine\Case_Catalog;
use ProSe\Core\Forms\Engine\Case_Service;
use ProSe\Core\Forms\Engine\Case_State;

/**
 * Class CaseEngineTest
 */
class CaseEngineTest extends TestCase {

	/**
	 * Build an in-memory case service (no persistence).
	 *
	 * @return Case_Service
	 */
	private function service(): Case_Service {
		return new Case_Service();
	}

	/**
	 * Resolve a case to its canonical state array.
	 *
	 * @param Case_State $state Case state.
	 * @return array<string, mixed>
	 */
	private function resolve( Case_State $state ): array {
		return $this->service()->resolve_state( $state );
	}

	/**
	 * Uncontested divorce: event-driven service step and package-driven judgment.
	 */
	public function test_uncontested_divorce_case(): void {
		$service = $this->service();
		$state   = $service->create_case( Vocabulary::WF_UNCONTESTED_DIVORCE, array( 'children' => false ) );

		$resolved = $this->resolve( $state );
		$this->assertSame( Vocabulary::WF_UNCONTESTED_DIVORCE, $resolved['workflow_key'] );
		$this->assertSame( Vocabulary::NODE_1001_DIVORCE_FILED, $resolved['current_node'] );
		$this->assertSame( array( Vocabulary::PKG_UNCONTESTED_NO_CHILDREN ), $resolved['available_packages'] );
		$this->assertSame( array(), $resolved['completed_packages'] );
		$this->assertSame( 0, $resolved['progress_percentage'] );
		$this->assertFalse( $resolved['is_complete'] );

		$service->record_event( $state, Case_Catalog::EVENT_SERVICE_COMPLETED );
		$this->assertSame( Vocabulary::NODE_1002_SERVICE_COMPLETE, $state->current_node() );
		$this->assertSame( 50, $this->resolve( $state )['progress_percentage'] );

		$service->complete_package( $state, Vocabulary::PKG_UNCONTESTED_NO_CHILDREN );
		$resolved = $this->resolve( $state );
		$this->assertContains( Vocabulary::PKG_UNCONTESTED_NO_CHILDREN, $resolved['completed_packages'] );
		$this->assertSame( array( Vocabulary::PKG_JUDGMENT ), $resolved['available_packages'] );
		$this->assertSame( Vocabulary::NODE_1002_SERVICE_COMPLETE, $resolved['current_node'] );

		$service->complete_package( $state, Vocabulary::PKG_JUDGMENT );
		$resolved = $this->resolve( $state );
		$this->assertSame( Vocabulary::NODE_1010_JUDGMENT, $resolved['current_node'] );
		$this->assertSame( 100, $resolved['progress_percentage'] );
		$this->assertTrue( $resolved['is_complete'] );
	}

	/**
	 * Uncontested divorce with children selects the with-children package.
	 */
	public function test_uncontested_divorce_with_children_initial_package(): void {
		$state = $this->service()->create_case( Vocabulary::WF_UNCONTESTED_DIVORCE, array( 'children' => true ) );

		$this->assertSame(
			array( Vocabulary::PKG_UNCONTESTED_WITH_CHILDREN ),
			$state->available_packages()
		);
	}

	/**
	 * Contested divorce: full JSON-driven progression through settlement to judgment.
	 */
	public function test_contested_divorce_case(): void {
		$service = $this->service();
		$state   = $service->create_case( Vocabulary::WF_CONTESTED_DIVORCE );

		$resolved = $this->resolve( $state );
		$this->assertSame( Vocabulary::NODE_1001_DIVORCE_FILED, $resolved['current_node'] );
		$this->assertSame( array( Vocabulary::PKG_CONTESTED_COMMENCEMENT ), $resolved['available_packages'] );
		$this->assertSame( 0, $resolved['progress_percentage'] );

		$service->complete_package( $state, Vocabulary::PKG_CONTESTED_COMMENCEMENT );
		$this->assertSame( array( Vocabulary::PKG_DISCOVERY ), $state->available_packages() );

		$service->record_event( $state, Case_Catalog::EVENT_SERVICE_COMPLETED );
		$this->assertSame( Vocabulary::NODE_1002_SERVICE_COMPLETE, $state->current_node() );

		$service->record_event( $state, Case_Catalog::EVENT_ANSWER_RECEIVED );
		$this->assertSame( Vocabulary::NODE_1003_ANSWER_FILED, $state->current_node() );

		$service->record_event( $state, Case_Catalog::EVENT_PRELIMINARY_CONFERENCE_HELD );
		$this->assertSame( Vocabulary::NODE_1005_PRELIMINARY_CONFERENCE, $state->current_node() );

		$service->record_event( $state, Case_Catalog::EVENT_DISCOVERY_COMPLETE );
		$this->assertSame( Vocabulary::NODE_1006_DISCOVERY, $state->current_node() );

		$service->record_event( $state, Case_Catalog::EVENT_COMPLIANCE_CONFERENCE_HELD );
		$this->assertSame( Vocabulary::NODE_1007_COMPLIANCE_CONFERENCE, $state->current_node() );

		$service->record_event( $state, Case_Catalog::EVENT_SETTLEMENT_REACHED );
		$this->assertSame( Vocabulary::NODE_1008_SETTLEMENT, $state->current_node() );
		$this->assertSame( 75, $this->resolve( $state )['progress_percentage'] );

		$service->record_event( $state, Case_Catalog::EVENT_JUDGMENT_ENTERED );
		$resolved = $this->resolve( $state );
		$this->assertSame( Vocabulary::NODE_1010_JUDGMENT, $resolved['current_node'] );
		$this->assertSame( 100, $resolved['progress_percentage'] );
		$this->assertTrue( $resolved['is_complete'] );
	}

	/**
	 * Custody: hearing then order via events.
	 */
	public function test_custody_case(): void {
		$service = $this->service();
		$state   = $service->create_case( Vocabulary::WF_CUSTODY );

		$resolved = $this->resolve( $state );
		$this->assertSame( Vocabulary::NODE_2001_CUSTODY_PETITION, $resolved['current_node'] );
		$this->assertSame( array( Vocabulary::PKG_CUSTODY_PETITION ), $resolved['available_packages'] );
		$this->assertSame( 0, $resolved['progress_percentage'] );

		$service->record_event( $state, Case_Catalog::EVENT_HEARING_SCHEDULED );
		$this->assertSame( Vocabulary::NODE_2002_CUSTODY_HEARING, $state->current_node() );
		$this->assertSame( 50, $this->resolve( $state )['progress_percentage'] );

		$service->record_event( $state, Case_Catalog::EVENT_JUDGMENT_ENTERED );
		$resolved = $this->resolve( $state );
		$this->assertSame( Vocabulary::NODE_2003_CUSTODY_ORDER, $resolved['current_node'] );
		$this->assertSame( 100, $resolved['progress_percentage'] );
		$this->assertTrue( $resolved['is_complete'] );
	}

	/**
	 * Child support: petition then order.
	 */
	public function test_child_support_case(): void {
		$service = $this->service();
		$state   = $service->create_case( Vocabulary::WF_CHILD_SUPPORT );

		$resolved = $this->resolve( $state );
		$this->assertSame( Vocabulary::NODE_3001_SUPPORT_PETITION, $resolved['current_node'] );
		$this->assertSame( array( Vocabulary::PKG_CHILD_SUPPORT_PETITION ), $resolved['available_packages'] );
		$this->assertSame( 0, $resolved['progress_percentage'] );

		$service->record_event( $state, Case_Catalog::EVENT_JUDGMENT_ENTERED );
		$resolved = $this->resolve( $state );
		$this->assertSame( Vocabulary::NODE_3002_SUPPORT_ORDER, $resolved['current_node'] );
		$this->assertSame( 100, $resolved['progress_percentage'] );
		$this->assertTrue( $resolved['is_complete'] );
	}

	/**
	 * Order of protection: family offense -> temp order -> final order.
	 */
	public function test_order_of_protection_case(): void {
		$service = $this->service();
		$state   = $service->create_case( Vocabulary::WF_ORDER_OF_PROTECTION );

		$resolved = $this->resolve( $state );
		$this->assertSame( Vocabulary::NODE_4001_FAMILY_OFFENSE, $resolved['current_node'] );
		$this->assertSame( array( Vocabulary::PKG_ORDER_OF_PROTECTION ), $resolved['available_packages'] );
		$this->assertSame( 0, $resolved['progress_percentage'] );

		$service->record_event( $state, Case_Catalog::EVENT_HEARING_SCHEDULED );
		$this->assertSame( Vocabulary::NODE_4002_TEMP_OP, $state->current_node() );
		$this->assertSame( 50, $this->resolve( $state )['progress_percentage'] );

		$service->record_event( $state, Case_Catalog::EVENT_JUDGMENT_ENTERED );
		$resolved = $this->resolve( $state );
		$this->assertSame( Vocabulary::NODE_4003_FINAL_OP, $resolved['current_node'] );
		$this->assertSame( 100, $resolved['progress_percentage'] );
		$this->assertTrue( $resolved['is_complete'] );
	}

	/**
	 * Out-of-order events do not skip workflow steps.
	 */
	public function test_out_of_order_event_does_not_advance(): void {
		$service = $this->service();
		$state   = $service->create_case( Vocabulary::WF_CONTESTED_DIVORCE );

		// Judgment cannot be entered before service is complete.
		$service->record_event( $state, Case_Catalog::EVENT_JUDGMENT_ENTERED );

		$this->assertSame( Vocabulary::NODE_1001_DIVORCE_FILED, $state->current_node() );
		$this->assertSame( 0, $this->resolve( $state )['progress_percentage'] );
	}

	/**
	 * Every applied event is recorded on the case.
	 */
	public function test_events_are_recorded(): void {
		$service = $this->service();
		$state   = $service->create_case( Vocabulary::WF_CUSTODY );

		$service->record_event( $state, Case_Catalog::EVENT_HEARING_SCHEDULED, array( 'date' => '2026-07-01' ) );
		$service->record_event( $state, Case_Catalog::EVENT_JUDGMENT_ENTERED );

		$events = $state->events();
		$this->assertCount( 2, $events );
		$this->assertSame( Case_Catalog::EVENT_HEARING_SCHEDULED, $events[0]->event_type() );
		$this->assertTrue( $events[0]->advanced() );
		$this->assertSame( array( 'date' => '2026-07-01' ), $events[0]->payload() );
		$this->assertSame( Vocabulary::NODE_2002_CUSTODY_HEARING, $events[0]->to_node() );
	}

	/**
	 * The resolved state exposes the documented shape.
	 */
	public function test_resolved_state_shape(): void {
		$state    = $this->service()->create_case( Vocabulary::WF_CHILD_SUPPORT );
		$resolved = $this->resolve( $state );

		foreach ( array( 'case_id', 'workflow_key', 'current_node', 'available_packages', 'completed_packages', 'progress_percentage' ) as $key ) {
			$this->assertArrayHasKey( $key, $resolved );
		}
	}

	/**
	 * Case state round-trips through array serialization deterministically.
	 */
	public function test_state_serialization_round_trip(): void {
		$service = $this->service();
		$state   = $service->create_case( Vocabulary::WF_CONTESTED_DIVORCE );
		$service->record_event( $state, Case_Catalog::EVENT_SERVICE_COMPLETED );
		$service->complete_package( $state, Vocabulary::PKG_CONTESTED_COMMENCEMENT );

		$rebuilt = Case_State::from_array( $state->to_array() );

		$this->assertSame( $state->workflow_key(), $rebuilt->workflow_key() );
		$this->assertSame( $state->current_node(), $rebuilt->current_node() );
		$this->assertSame( $state->completed_packages(), $rebuilt->completed_packages() );
		$this->assertSame( $state->available_packages(), $rebuilt->available_packages() );
		$this->assertCount( count( $state->events() ), $rebuilt->events() );
	}
}
