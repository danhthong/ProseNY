<?php
/**
 * Deadline Generator — create case deadlines from catalog rules.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Forms\Engine;

use ProSe\Core\Forms\Database\Repositories\Case_Deadline_Repository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Deadline_Generator
 *
 * Generates Case_Deadline DTOs from Deadline_Catalog rules. Optionally
 * persists through Case_Deadline_Repository.
 */
final class Deadline_Generator {

	/**
	 * Optional persistence layer.
	 *
	 * @var Case_Deadline_Repository|null
	 */
	private ?Case_Deadline_Repository $repository;

	/**
	 * Constructor.
	 *
	 * @param Case_Deadline_Repository|null $repository Persistence (null = in-memory).
	 */
	public function __construct( ?Case_Deadline_Repository $repository = null ) {
		$this->repository = $repository;
	}

	/**
	 * Generate deadlines for all known anchors on a case state.
	 *
	 * @param Case_State $state Case state.
	 * @param int        $now   Current Unix timestamp for status resolution.
	 * @return Case_Deadline[]
	 */
	public function generate_for_case( Case_State $state, int $now = 0 ): array {
		$now        = $now > 0 ? $now : time();
		$deadlines  = array();
		$anchors    = $this->anchors_for_state( $state, $now );

		foreach ( $anchors as $trigger_event => $anchor_date ) {
			$generated = $this->generate_for_trigger(
				$state,
				$trigger_event,
				$anchor_date,
				$now
			);

			foreach ( $generated as $deadline ) {
				$deadlines[ $this->deadline_index_key( $deadline ) ] = $deadline;
			}
		}

		return array_values( $deadlines );
	}

	/**
	 * Generate deadlines when a trigger event fires.
	 *
	 * @param Case_State $state         Case state.
	 * @param string     $trigger_event   Trigger event.
	 * @param string     $anchor_date     Anchor datetime (Y-m-d H:i:s).
	 * @param int        $now             Current Unix timestamp.
	 * @return Case_Deadline[]
	 */
	public function generate_for_trigger(
		Case_State $state,
		string $trigger_event,
		string $anchor_date,
		int $now = 0
	): array {
		$now   = $now > 0 ? $now : time();
		$rules = Deadline_Catalog::for_trigger( $state->workflow_key(), $trigger_event );

		if ( empty( $rules ) ) {
			return array();
		}

		$deadlines = array();

		foreach ( $rules as $rule ) {
			$due = $this->compute_due_date(
				$anchor_date,
				(int) ( $rule['offset_days'] ?? 0 ),
				(string) ( $rule['direction'] ?? 'after' ),
				(string) ( $rule['day_type'] ?? 'calendar' )
			);

			$status = Deadline_Status::resolve( $due, false, false, $now );

			$deadline = new Case_Deadline(
				(string) ( $rule['deadline_key'] ?? '' ),
				(string) ( $rule['label'] ?? '' ),
				$due,
				$trigger_event,
				$anchor_date,
				(string) ( $rule['day_type'] ?? 'calendar' ),
				$status,
				false,
				(string) ( $rule['next_action'] ?? '' ),
				0,
				$state->case_id(),
				$state->workflow_key()
			);

			if ( null !== $this->repository && $state->case_id() > 0 ) {
				$id = $this->repository->upsert_from_dto( $deadline );
				$deadline = Case_Deadline::from_array(
					array_merge(
						$deadline->to_array(),
						array( 'case_deadline_id' => $id )
					)
				);
			}

			$deadlines[] = $deadline;
		}

		return $deadlines;
	}

	/**
	 * Compute due date from anchor.
	 *
	 * @param string $anchor    Anchor datetime.
	 * @param int    $offset    Offset days.
	 * @param string $direction after|before.
	 * @param string $day_type  calendar|court.
	 * @return string Datetime string.
	 */
	public function compute_due_date( string $anchor, int $offset, string $direction, string $day_type ): string {
		$timestamp = strtotime( $anchor );

		if ( false === $timestamp ) {
			$timestamp = time();
		}

		if ( 0 === $offset ) {
			return gmdate( 'Y-m-d H:i:s', $timestamp );
		}

		if ( 'court' === $day_type ) {
			return $this->add_court_days( $timestamp, $offset, $direction );
		}

		$modifier = ( 'before' === $direction ? '-' : '+' ) . $offset . ' days';
		$due      = strtotime( $modifier, $timestamp );

		return gmdate( 'Y-m-d H:i:s', false !== $due ? $due : $timestamp );
	}

	/**
	 * Add court (weekday) days to a timestamp.
	 *
	 * @param int    $timestamp Anchor timestamp.
	 * @param int    $offset    Number of weekdays.
	 * @param string $direction after|before.
	 * @return string Datetime string.
	 */
	private function add_court_days( int $timestamp, int $offset, string $direction ): string {
		$remaining = abs( $offset );
		$current   = $timestamp;
		$step      = 'before' === $direction ? -86400 : 86400;

		while ( $remaining > 0 ) {
			$current += $step;
			$weekday  = (int) gmdate( 'N', $current );

			if ( $weekday < 6 ) {
				--$remaining;
			}
		}

		return gmdate( 'Y-m-d H:i:s', $current );
	}

	/**
	 * Build trigger-event anchors from case state.
	 *
	 * @param Case_State $state Case state.
	 * @return array<string, string>
	 */
	private function anchors_for_state( Case_State $state, int $now ): array {
		$anchors = array();

		$anchors[ Deadline_Catalog::EVENT_CASE_FILED ] = $this->case_filed_anchor( $state, $now );

		foreach ( $state->events() as $event ) {
			$anchors[ $event->event_type() ] = $event->occurred_at();
		}

		return $anchors;
	}

	/**
	 * Resolve the CASE_FILED anchor datetime.
	 *
	 * @param Case_State $state Case state.
	 * @return string
	 */
	private function case_filed_anchor( Case_State $state, int $now ): string {
		foreach ( $state->events() as $event ) {
			if ( Deadline_Catalog::EVENT_CASE_FILED === $event->event_type() ) {
				return $event->occurred_at();
			}
		}

		return gmdate( 'Y-m-d H:i:s', $now );
	}

	/**
	 * Unique index key for deduplicating deadlines.
	 *
	 * @param Case_Deadline $deadline Deadline.
	 * @return string
	 */
	private function deadline_index_key( Case_Deadline $deadline ): string {
		return $deadline->deadline_key() . '|' . $deadline->trigger_event();
	}
}
