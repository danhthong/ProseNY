<?php
/**
 * Intake session repository.
 *
 * @package ProseCore
 */

namespace Prose\Core\Database\Repositories;

use Prose\Core\Support\Config;

final class SessionRepository {

	public function create( int $case_id, int $user_id, ?int $workflow_id = null ): int {
		global $wpdb;

		$data    = array(
			'case_id'      => $case_id,
			'user_id'      => $user_id,
			'status'       => 'active',
			'rule_version' => 1,
		);
		$formats = array( '%d', '%d', '%s', '%d' );

		if ( null !== $workflow_id ) {
			$data['workflow_id'] = $workflow_id;
			$formats[]           = '%d';
		}

		$inserted = $wpdb->insert( Config::table( 'intake_sessions' ), $data, $formats );

		if ( false === $inserted ) {
			throw new \RuntimeException( 'Failed to create intake session: ' . (string) $wpdb->last_error );
		}

		$session_id = (int) $wpdb->insert_id;

		$this->init_facts( $session_id );

		return $session_id;
	}

	private function init_facts( int $session_id ): void {
		global $wpdb;

		$inserted = $wpdb->insert(
			Config::table( 'session_facts' ),
			array(
				'session_id'    => $session_id,
				'facts'         => wp_json_encode( array( 'case' => array(), 'user' => array() ) ),
				'facts_version' => 1,
			),
			array( '%d', '%s', '%d' )
		);

		if ( false === $inserted ) {
			throw new \RuntimeException( 'Failed to init session facts: ' . (string) $wpdb->last_error );
		}
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public function find( int $id ): ?array {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM ' . Config::table( 'intake_sessions' ) . ' WHERE id = %d', $id ),
			ARRAY_A
		);

		return $row ?: null;
	}

	public function update( int $id, array $data ): bool {
		global $wpdb;

		$allowed = array( 'status', 'workflow_id', 'current_node_id', 'rule_version', 'advance_key' );
		$update  = array_intersect_key( $data, array_flip( $allowed ) );

		if ( empty( $update ) ) {
			return false;
		}

		return false !== $wpdb->update(
			Config::table( 'intake_sessions' ),
			$update,
			array( 'id' => $id )
		);
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public function list_all( int $limit = 50, int $offset = 0 ): array {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . Config::table( 'intake_sessions' ) . ' ORDER BY created_at DESC LIMIT %d OFFSET %d',
				$limit,
				$offset
			),
			ARRAY_A
		);

		return $rows ?: array();
	}

	public function count(): int {
		global $wpdb;
		return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Config::table( 'intake_sessions' ) );
	}
}
