<?php
/**
 * Case timeline resolver — buckets deadlines and derives next actions.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Forms\Engine;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Case_Timeline_Resolver
 *
 * Pure resolver that groups Case_Deadline DTOs into timeline buckets.
 */
final class Case_Timeline_Resolver {

	/**
	 * Resolve deadlines into a Case_Timeline.
	 *
	 * @param Case_Deadline[] $deadlines Deadlines.
	 * @param int             $now       Current Unix timestamp.
	 * @return Case_Timeline
	 */
	public function resolve( array $deadlines, int $now = 0 ): Case_Timeline {
		$now = $now > 0 ? $now : time();

		$upcoming  = array();
		$overdue   = array();
		$completed = array();

		foreach ( $deadlines as $deadline ) {
			if ( ! $deadline instanceof Case_Deadline ) {
				continue;
			}

			$status = Deadline_Status::resolve(
				$deadline->due_date(),
				$deadline->completed(),
				$deadline->cancelled(),
				$now
			);

			$resolved = $deadline->with_status( $status );

			switch ( $status ) {
				case Deadline_Status::COMPLETED:
				case Deadline_Status::CANCELLED:
					$completed[] = $resolved;
					break;

				case Deadline_Status::OVERDUE:
					$overdue[] = $resolved;
					break;

				default:
					$upcoming[] = $resolved;
					break;
			}
		}

		usort(
			$upcoming,
			static fn( Case_Deadline $a, Case_Deadline $b ): int => strcmp( $a->due_date(), $b->due_date() )
		);

		usort(
			$overdue,
			static fn( Case_Deadline $a, Case_Deadline $b ): int => strcmp( $a->due_date(), $b->due_date() )
		);

		usort(
			$completed,
			static fn( Case_Deadline $a, Case_Deadline $b ): int => strcmp( $a->due_date(), $b->due_date() )
		);

		return new Case_Timeline(
			$upcoming,
			$overdue,
			$completed,
			$this->derive_next_actions( $overdue, $upcoming )
		);
	}

	/**
	 * Derive de-duplicated next actions (overdue first).
	 *
	 * @param Case_Deadline[] $overdue  Overdue deadlines.
	 * @param Case_Deadline[] $upcoming Upcoming deadlines.
	 * @return string[]
	 */
	private function derive_next_actions( array $overdue, array $upcoming ): array {
		$actions = array();

		foreach ( array_merge( $overdue, $upcoming ) as $deadline ) {
			$action = $deadline->next_action();

			if ( '' === $action || in_array( $action, $actions, true ) ) {
				continue;
			}

			$actions[] = $action;
		}

		return $actions;
	}
}
