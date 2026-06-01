<?php
/**
 * Session events repository (append-only).
 *
 * @package ProseCore
 */

namespace Prose\Core\Database\Repositories;

use Prose\Core\Support\Config;

final class EventRepository {

	/**
	 * @param array<string, mixed> $payload
	 */
	public function append( int $session_id, string $event_type, array $payload, string $actor = 'system' ): int {
		global $wpdb;

		$wpdb->insert(
			Config::table( 'session_events' ),
			array(
				'session_id' => $session_id,
				'event_type' => $event_type,
				'actor'      => $actor,
				'payload'    => wp_json_encode( $payload ),
			),
			array( '%d', '%s', '%s', '%s' )
		);

		return (int) $wpdb->insert_id;
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public function for_session( int $session_id, int $limit = 100 ): array {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . Config::table( 'session_events' ) . ' WHERE session_id = %d ORDER BY created_at ASC LIMIT %d',
				$session_id,
				$limit
			),
			ARRAY_A
		);

		foreach ( $rows as &$row ) {
			$row['payload'] = json_decode( $row['payload'] ?? '{}', true ) ?: array();
		}

		return $rows ?: array();
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public function messages( int $session_id, int $limit = 50 ): array {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM " . Config::table( 'session_events' ) . " WHERE session_id = %d AND event_type IN ('user_message','assistant_message') ORDER BY created_at ASC LIMIT %d",
				$session_id,
				$limit
			),
			ARRAY_A
		);

		foreach ( $rows as &$row ) {
			$row['payload'] = json_decode( $row['payload'] ?? '{}', true ) ?: array();
		}

		return $rows ?: array();
	}

	/**
	 * Path the assistant was asking about on the most recent turn (stored in
	 * assistant_message payload as `pending_path`).
	 */
	public function last_pending_path( int $session_id ): ?string {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT payload FROM " . Config::table( 'session_events' ) .
				" WHERE session_id = %d AND event_type = 'assistant_message' ORDER BY created_at DESC, id DESC LIMIT 1",
				$session_id
			),
			ARRAY_A
		);

		if ( ! $row ) {
			return null;
		}

		$payload = json_decode( $row['payload'] ?? '{}', true ) ?: array();
		$path    = (string) ( $payload['pending_path'] ?? '' );

		return '' !== $path ? $path : null;
	}
}
