<?php
/**
 * Document status enum and transition rules.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Forms\Documents;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Document_Status
 *
 * Deterministic, DB-free status vocabulary for a generated court document
 * and the legal forward-only transitions between those statuses. The
 * statuses mirror the lifecycle of a single document: from an empty form
 * (NOT_STARTED) through assembly (IN_PROGRESS / READY), generation, and
 * the downstream filing milestones (SIGNED, FILED) plus the REJECTED
 * terminal state.
 */
final class Document_Status {

	public const NOT_STARTED = 'NOT_STARTED';
	public const IN_PROGRESS = 'IN_PROGRESS';
	public const READY       = 'READY';
	public const GENERATED   = 'GENERATED';
	public const SIGNED      = 'SIGNED';
	public const FILED       = 'FILED';
	public const REJECTED    = 'REJECTED';

	/**
	 * All supported statuses.
	 *
	 * @return string[]
	 */
	public static function all(): array {
		return array(
			self::NOT_STARTED,
			self::IN_PROGRESS,
			self::READY,
			self::GENERATED,
			self::SIGNED,
			self::FILED,
			self::REJECTED,
		);
	}

	/**
	 * Whether a value is a recognized status.
	 *
	 * @param string $status Status value.
	 * @return bool
	 */
	public static function is_valid( string $status ): bool {
		return in_array( $status, self::all(), true );
	}

	/**
	 * Allowed forward transitions per status.
	 *
	 * @return array<string, string[]>
	 */
	public static function transitions(): array {
		return array(
			self::NOT_STARTED => array( self::IN_PROGRESS, self::READY ),
			self::IN_PROGRESS => array( self::READY, self::NOT_STARTED ),
			self::READY       => array( self::GENERATED, self::IN_PROGRESS ),
			self::GENERATED   => array( self::SIGNED, self::REJECTED, self::READY ),
			self::SIGNED      => array( self::FILED, self::REJECTED ),
			self::FILED       => array( self::REJECTED ),
			self::REJECTED    => array( self::IN_PROGRESS ),
		);
	}

	/**
	 * Whether a transition between two statuses is permitted.
	 *
	 * @param string $from Current status.
	 * @param string $to   Target status.
	 * @return bool
	 */
	public static function can_transition( string $from, string $to ): bool {
		if ( $from === $to ) {
			return true;
		}

		$map = self::transitions();

		return in_array( $to, $map[ $from ] ?? array(), true );
	}

	/**
	 * Resolve the assembly status from field resolution + validation.
	 *
	 * NOT_STARTED  — no required field resolved.
	 * IN_PROGRESS  — some, but not all, required fields resolved.
	 * READY        — every required (and conditionally-required) field
	 *                resolved and validation passes.
	 *
	 * @param int  $resolved_required Required fields resolved.
	 * @param int  $total_required    Total required fields.
	 * @param bool $is_valid          Whether validation passed.
	 * @return string
	 */
	public static function resolve_assembly_status( int $resolved_required, int $total_required, bool $is_valid ): string {
		if ( $total_required > 0 && $resolved_required >= $total_required && $is_valid ) {
			return self::READY;
		}

		if ( $resolved_required <= 0 ) {
			return self::NOT_STARTED;
		}

		return self::IN_PROGRESS;
	}
}
