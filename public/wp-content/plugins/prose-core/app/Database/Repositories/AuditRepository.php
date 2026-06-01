<?php
/**
 * AI audit log repository.
 *
 * @package ProseCore
 */

namespace Prose\Core\Database\Repositories;

use Prose\Core\Support\Config;

final class AuditRepository {

	/**
	 * @param array<string, mixed> $data
	 */
	public function log( array $data ): int {
		global $wpdb;

		$wpdb->insert(
			Config::table( 'ai_audit_log' ),
			array(
				'session_id'       => $data['session_id'] ?? null,
				'case_id'          => $data['case_id'] ?? null,
				'agent'            => $data['agent'],
				'provider'         => $data['provider'],
				'model'            => $data['model'],
				'prompt_hash'      => $data['prompt_hash'] ?? '',
				'redacted_input'   => wp_json_encode( $data['redacted_input'] ?? array() ),
				'redacted_output'  => wp_json_encode( $data['redacted_output'] ?? array() ),
				'tokens_in'        => $data['tokens_in'] ?? 0,
				'tokens_out'       => $data['tokens_out'] ?? 0,
				'cost_usd'         => $data['cost_usd'] ?? 0,
				'latency_ms'       => $data['latency_ms'] ?? 0,
			),
			array( '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%f', '%d' )
		);

		return (int) $wpdb->insert_id;
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public function list( int $limit = 50, int $offset = 0 ): array {
		global $wpdb;

		return $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . Config::table( 'ai_audit_log' ) . ' ORDER BY created_at DESC LIMIT %d OFFSET %d',
				$limit,
				$offset
			),
			ARRAY_A
		) ?: array();
	}

	public function total_cost_for_case( int $case_id ): float {
		global $wpdb;

		return (float) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COALESCE(SUM(cost_usd), 0) FROM ' . Config::table( 'ai_audit_log' ) . ' WHERE case_id = %d',
				$case_id
			)
		);
	}
}
