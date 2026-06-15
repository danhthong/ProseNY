<?php
/**
 * OpenAI usage logger — records every API call with token usage.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Ai_Intake;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Usage_Logger
 *
 * Stores a ring buffer of OpenAI API calls (one entry per request) plus
 * cumulative token totals, for display on the wp-admin Tools page.
 */
final class Usage_Logger {

	/**
	 * Option key for the per-request log ring buffer.
	 */
	public const OPTION_KEY = 'prose_ai_usage_log';

	/**
	 * Option key for cumulative lifetime totals.
	 */
	public const TOTALS_KEY = 'prose_ai_usage_totals';

	/**
	 * Maximum log entries retained.
	 */
	private const MAX_ENTRIES = 200;

	/**
	 * Record a single API call.
	 *
	 * @param array<string, mixed> $entry {
	 *     Call metadata.
	 *
	 *     @type string $type              Request type/mode (extract, question, ...).
	 *     @type string $provider          Provider name.
	 *     @type string $model             Model id.
	 *     @type int    $prompt_tokens     Prompt tokens.
	 *     @type int    $completion_tokens Completion tokens.
	 *     @type int    $total_tokens      Total tokens.
	 *     @type int    $latency_ms        Latency in milliseconds.
	 *     @type string $status            "ok" or "error".
	 *     @type string $error             Error message when status is "error".
	 * }
	 * @return void
	 */
	public function record( array $entry ): void {
		$prompt_tokens     = (int) ( $entry['prompt_tokens'] ?? 0 );
		$completion_tokens = (int) ( $entry['completion_tokens'] ?? 0 );
		$total_tokens      = (int) ( $entry['total_tokens'] ?? ( $prompt_tokens + $completion_tokens ) );

		$normalized = array(
			'timestamp'         => time(),
			'type'              => (string) ( $entry['type'] ?? 'request' ),
			'provider'          => (string) ( $entry['provider'] ?? 'openai' ),
			'model'             => (string) ( $entry['model'] ?? '' ),
			'prompt_tokens'     => $prompt_tokens,
			'completion_tokens' => $completion_tokens,
			'total_tokens'      => $total_tokens,
			'latency_ms'        => (int) ( $entry['latency_ms'] ?? 0 ),
			'status'            => (string) ( $entry['status'] ?? 'ok' ),
			'error'             => (string) ( $entry['error'] ?? '' ),
		);

		$logs   = $this->all();
		$logs[] = $normalized;

		if ( count( $logs ) > self::MAX_ENTRIES ) {
			$logs = array_slice( $logs, -self::MAX_ENTRIES );
		}

		update_option( self::OPTION_KEY, $logs, false );

		$this->bump_totals( $normalized );
	}

	/**
	 * All log entries (oldest first).
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function all(): array {
		$logs = get_option( self::OPTION_KEY, array() );

		return is_array( $logs ) ? $logs : array();
	}

	/**
	 * Cumulative lifetime totals (survives log trimming/clearing of entries).
	 *
	 * @return array{requests: int, prompt_tokens: int, completion_tokens: int, total_tokens: int, errors: int}
	 */
	public function totals(): array {
		$totals = get_option( self::TOTALS_KEY, array() );
		$totals = is_array( $totals ) ? $totals : array();

		return array(
			'requests'          => (int) ( $totals['requests'] ?? 0 ),
			'prompt_tokens'     => (int) ( $totals['prompt_tokens'] ?? 0 ),
			'completion_tokens' => (int) ( $totals['completion_tokens'] ?? 0 ),
			'total_tokens'      => (int) ( $totals['total_tokens'] ?? 0 ),
			'errors'            => (int) ( $totals['errors'] ?? 0 ),
		);
	}

	/**
	 * Clear the per-request log entries (keeps lifetime totals).
	 *
	 * @return void
	 */
	public function clear(): void {
		delete_option( self::OPTION_KEY );
	}

	/**
	 * Reset lifetime totals back to zero.
	 *
	 * @return void
	 */
	public function reset_totals(): void {
		delete_option( self::TOTALS_KEY );
	}

	/**
	 * Increment cumulative totals.
	 *
	 * @param array<string, mixed> $entry Normalized entry.
	 * @return void
	 */
	private function bump_totals( array $entry ): void {
		$totals = $this->totals();

		$totals['requests']          += 1;
		$totals['prompt_tokens']     += (int) $entry['prompt_tokens'];
		$totals['completion_tokens'] += (int) $entry['completion_tokens'];
		$totals['total_tokens']      += (int) $entry['total_tokens'];

		if ( 'ok' !== ( $entry['status'] ?? 'ok' ) ) {
			$totals['errors'] += 1;
		}

		update_option( self::TOTALS_KEY, $totals, false );
	}
}
