<?php
/**
 * AI request logger — admin-only ring buffer.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Ai_Intake;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class AI_Logger
 */
final class AI_Logger {

	/**
	 * Option key.
	 */
	public const OPTION_KEY = 'prose_ai_logs';

	/**
	 * Maximum log entries.
	 */
	private const MAX_ENTRIES = 100;

	/**
	 * Append a log entry.
	 *
	 * @param array<string, mixed> $entry Log entry.
	 * @return void
	 */
	public function log( array $entry ): void {
		$logs   = $this->all();
		$logs[] = array_merge(
			array( 'timestamp' => time() ),
			$entry
		);

		if ( count( $logs ) > self::MAX_ENTRIES ) {
			$logs = array_slice( $logs, -self::MAX_ENTRIES );
		}

		update_option( self::OPTION_KEY, $logs, false );
	}

	/**
	 * Get all log entries (newest last).
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function all(): array {
		$logs = get_option( self::OPTION_KEY, array() );

		return is_array( $logs ) ? $logs : array();
	}

	/**
	 * Clear all logs.
	 *
	 * @return void
	 */
	public function clear(): void {
		delete_option( self::OPTION_KEY );
	}
}
