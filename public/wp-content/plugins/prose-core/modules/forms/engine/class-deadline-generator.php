<?php
/**
 * Deadline Generator — create case deadlines from rules.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Forms\Engine;

use ProSe\Core\Forms\Database\Repositories\Case_Deadline_Repository;
use ProSe\Core\Forms\Database\Repositories\Deadline_Rule_Repository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Deadline_Generator
 */
final class Deadline_Generator {

	/**
	 * Rule repository.
	 *
	 * @var Deadline_Rule_Repository
	 */
	private Deadline_Rule_Repository $rules;

	/**
	 * Case deadline repository.
	 *
	 * @var Case_Deadline_Repository
	 */
	private Case_Deadline_Repository $case_deadlines;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->rules           = new Deadline_Rule_Repository();
		$this->case_deadlines  = new Case_Deadline_Repository();
	}

	/**
	 * Generate case deadlines when an event fires.
	 *
	 * @param int                  $case_id       Case ID.
	 * @param string               $source_event  Event key.
	 * @param string               $event_date    Anchor datetime (Y-m-d H:i:s).
	 * @param string               $workflow_key  Workflow key.
	 * @return int Number of deadlines created/updated.
	 */
	public function generate( int $case_id, string $source_event, string $event_date, string $workflow_key = '' ): int {
		$rule_rows = $this->rules->get_by_trigger( $source_event, $workflow_key );
		$count     = 0;

		foreach ( $rule_rows as $rule ) {
			$due = $this->compute_due_date(
				$event_date,
				(int) $rule->offset_days,
				(string) $rule->direction,
				(string) $rule->day_type
			);

			$id = $this->case_deadlines->upsert(
				array(
					'case_id'           => $case_id,
					'workflow_key'      => (string) $rule->workflow_key,
					'node_id'           => $rule->node_id ? (int) $rule->node_id : null,
					'deadline_rule_id'  => (int) $rule->deadline_id,
					'title'             => (string) $rule->label,
					'due_date'          => $due,
					'source_event'      => $source_event,
					'source_event_date' => $event_date,
					'day_type'          => (string) $rule->day_type,
					'status'            => 'pending',
				)
			);

			if ( $id > 0 ) {
				++$count;
			}
		}

		return $count;
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
	private function compute_due_date( string $anchor, int $offset, string $direction, string $day_type ): string {
		$timestamp = strtotime( $anchor );

		if ( false === $timestamp ) {
			$timestamp = time();
		}

		$modifier = ( 'before' === $direction ? '-' : '+' ) . $offset . ' days';

		// Court-day calculation can be extended with holiday calendar.
		if ( 'court' === $day_type ) {
			$modifier = ( 'before' === $direction ? '-' : '+' ) . $offset . ' weekdays';
		}

		$due = strtotime( $modifier, $timestamp );

		return gmdate( 'Y-m-d H:i:s', false !== $due ? $due : $timestamp );
	}
}
