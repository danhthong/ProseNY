<?php
/**
 * Optional DB persistence for CourtFlow workspace sessions.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Intake\Rest;

use ProSe\Core\Forms\Database\Repositories\Case_Repository;
use ProSe\Core\Forms\Engine\Case_Event;
use ProSe\Core\Forms\Engine\Case_State;
use ProSe\Core\Routing\Workflow_Catalog;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Courtflow_Case_Persistence
 */
final class Courtflow_Case_Persistence {

	/**
	 * Case repository.
	 *
	 * @var Case_Repository
	 */
	private Case_Repository $repository;

	/**
	 * Workflow catalog.
	 *
	 * @var Workflow_Catalog
	 */
	private Workflow_Catalog $catalog;

	/**
	 * Constructor.
	 *
	 * @param Case_Repository|null  $repository Case repository.
	 * @param Workflow_Catalog|null $catalog    Workflow catalog.
	 */
	public function __construct( ?Case_Repository $repository = null, ?Workflow_Catalog $catalog = null ) {
		$this->repository = $repository ?? new Case_Repository();
		$this->catalog    = $catalog ?? new Workflow_Catalog();
	}

	/**
	 * Whether case persistence is enabled.
	 *
	 * @return bool
	 */
	public function is_enabled(): bool {
		/**
		 * Filter whether CourtFlow sessions are persisted to prose_cases.
		 *
		 * @param bool $enabled Default true when tables exist.
		 */
		return (bool) apply_filters( 'prose_courtflow_persist_cases', true );
	}

	/**
	 * Persist a completed intake session to prose_cases.
	 *
	 * @param array<string, mixed> $session Stored session payload.
	 * @return int Case ID (0 when skipped).
	 */
	public function persist_intake_complete( array $session ): int {
		if ( ! $this->is_enabled() ) {
			return 0;
		}

		$existing = (int) ( $session['case_id'] ?? 0 );

		if ( $existing > 0 ) {
			return $existing;
		}

		$state = $this->session_to_case_state( $session );

		if ( '' === $state->workflow_key() ) {
			return 0;
		}

		$case_id = $this->repository->save_state( $state );

		$this->repository->insert_event(
			$case_id,
			new Case_Event(
				'intake_complete',
				'',
				'commencement',
				array(
					'session_id' => (string) ( $session['session_id'] ?? '' ),
					'completion' => (int) ( $session['intake_state']['completion'] ?? 0 ),
				)
			)
		);

		return $case_id;
	}

	/**
	 * Build a Case_State aggregate from a CourtFlow session.
	 *
	 * @param array<string, mixed> $session Session payload.
	 * @return Case_State
	 */
	public function session_to_case_state( array $session ): Case_State {
		$profile  = is_array( $session['case_profile'] ?? null ) ? $session['case_profile'] : array();
		$facts    = is_array( $profile['facts'] ?? null ) ? $profile['facts'] : array();
		$workflow = sanitize_key( (string) ( $profile['workflow'] ?? '' ) );
		$def      = $workflow ? ( $this->catalog->by_key( $workflow ) ?? array() ) : array();

		$state = Case_State::from_array(
			array(
				'case_id'      => (int) ( $session['case_id'] ?? 0 ),
				'workflow_key' => $workflow,
				'court_routing'=> sanitize_key( (string) ( $def['court'] ?? '' ) ),
				'county'       => sanitize_text_field( (string) ( $facts['county'] ?? '' ) ),
				'current_node' => 'commencement',
				'status'       => 'active',
				'answers'      => $profile,
			)
		);

		return $state;
	}
}
