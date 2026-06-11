<?php
/**
 * Deadline status constants and resolver.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Forms\Engine;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Deadline_Status
 *
 * Resolves a deadline's display status from its due date and completion state.
 */
final class Deadline_Status {

	public const PENDING    = 'PENDING';
	public const UPCOMING   = 'UPCOMING';
	public const DUE_TODAY  = 'DUE_TODAY';
	public const OVERDUE    = 'OVERDUE';
	public const COMPLETED  = 'COMPLETED';
	public const CANCELLED  = 'CANCELLED';

	/**
	 * Days ahead within which a deadline is considered upcoming.
	 */
	public const DEFAULT_UPCOMING_DAYS = 14;

	/**
	 * Resolve the status for a deadline.
	 *
	 * @param string $due_date       Due datetime (Y-m-d H:i:s).
	 * @param bool   $completed      Whether the deadline is completed.
	 * @param bool   $cancelled      Whether the deadline is cancelled.
	 * @param int    $now            Current Unix timestamp.
	 * @param int    $upcoming_days  Days ahead to treat as upcoming.
	 * @return string One of the status constants.
	 */
	public static function resolve(
		string $due_date,
		bool $completed,
		bool $cancelled,
		int $now,
		int $upcoming_days = self::DEFAULT_UPCOMING_DAYS
	): string {
		if ( $completed ) {
			return self::COMPLETED;
		}

		if ( $cancelled ) {
			return self::CANCELLED;
		}

		$due_ts = strtotime( $due_date );

		if ( false === $due_ts ) {
			return self::PENDING;
		}

		$now_date = gmdate( 'Y-m-d', $now );
		$due_day  = gmdate( 'Y-m-d', $due_ts );

		if ( $due_day === $now_date ) {
			return self::DUE_TODAY;
		}

		if ( $due_ts < $now ) {
			return self::OVERDUE;
		}

		$cutoff = strtotime( '+' . $upcoming_days . ' days', $now );

		if ( false !== $cutoff && $due_ts <= $cutoff ) {
			return self::UPCOMING;
		}

		return self::PENDING;
	}

	/**
	 * Whether a status is an open (actionable) status.
	 *
	 * @param string $status Status constant.
	 * @return bool
	 */
	public static function is_open( string $status ): bool {
		return in_array(
			$status,
			array( self::PENDING, self::UPCOMING, self::DUE_TODAY, self::OVERDUE ),
			true
		);
	}
}
