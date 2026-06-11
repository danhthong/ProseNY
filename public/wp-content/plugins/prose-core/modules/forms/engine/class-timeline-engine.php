<?php
/**
 * Timeline Engine — read case deadlines for display.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Forms\Engine;

use ProSe\Core\Forms\Database\Repositories\Case_Deadline_Repository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Timeline_Engine
 */
final class Timeline_Engine {

	/**
	 * Case deadline repository.
	 *
	 * @var Case_Deadline_Repository
	 */
	private Case_Deadline_Repository $deadlines;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->deadlines = new Case_Deadline_Repository();
	}

	/**
	 * Build timeline for a case.
	 *
	 * @param int  $case_id   Case ID.
	 * @param bool $open_only Open deadlines only.
	 * @return array<int, array<string, mixed>>
	 */
	public function timeline_for_case( int $case_id, bool $open_only = true ): array {
		$rows   = $this->deadlines->list_for_case( $case_id, $open_only );
		$now    = time();
		$result = array();

		foreach ( $rows as $row ) {
			$item = $this->deadlines->to_array( $row );
			$due  = strtotime( (string) $row->due_date );

			if ( ! $item['completed'] && false !== $due && $due < $now ) {
				$item['status'] = 'overdue';
			} elseif ( ! $item['completed'] ) {
				$item['status'] = 'due';
			}

			$result[] = $item;
		}

		return $result;
	}

	/**
	 * Upcoming deadlines within N days.
	 *
	 * @param int $case_id Case ID.
	 * @param int $days    Days ahead.
	 * @return array<int, array<string, mixed>>
	 */
	public function upcoming( int $case_id, int $days = 30 ): array {
		$all     = $this->timeline_for_case( $case_id, true );
		$cutoff  = strtotime( '+' . $days . ' days' );
		$result  = array();

		foreach ( $all as $item ) {
			$due = strtotime( (string) $item['due_date'] );

			if ( false !== $due && $due <= $cutoff ) {
				$result[] = $item;
			}
		}

		return $result;
	}

	/**
	 * Mark a case deadline complete.
	 *
	 * @param int $case_deadline_id Case deadline ID.
	 * @return bool
	 */
	public function complete( int $case_deadline_id ): bool {
		return $this->deadlines->mark_complete( $case_deadline_id );
	}
}
