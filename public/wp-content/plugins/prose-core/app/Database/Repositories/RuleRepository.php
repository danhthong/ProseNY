<?php
/**
 * Procedural rules repository.
 *
 * @package ProseCore
 */

namespace Prose\Core\Database\Repositories;

use Prose\Core\Support\Config;

class RuleRepository {

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public function enabled( ?int $workflow_id = null, ?int $version = null ): array {
		global $wpdb;

		$table = Config::table( 'rules' );
		$sql   = "SELECT * FROM {$table} WHERE enabled = 1";
		$args  = array();

		if ( null !== $workflow_id ) {
			$sql   .= ' AND (workflow_id = %d OR workflow_id IS NULL)';
			$args[] = $workflow_id;
		}

		if ( null !== $version ) {
			$sql   .= ' AND version = %d';
			$args[] = $version;
		}

		$sql .= ' ORDER BY priority ASC, slug ASC';

		if ( ! empty( $args ) ) {
			$rows = $wpdb->get_results( $wpdb->prepare( $sql, ...$args ), ARRAY_A );
		} else {
			$rows = $wpdb->get_results( $sql, ARRAY_A );
		}

		return $rows ?: array();
	}

	/**
	 * @param array<string, mixed> $data
	 */
	public function create( array $data ): int {
		global $wpdb;

		$wpdb->insert(
			Config::table( 'rules' ),
			array(
				'workflow_id' => $data['workflow_id'] ?? null,
				'slug'        => $data['slug'],
				'priority'    => $data['priority'] ?? 100,
				'conditions'  => wp_json_encode( $data['conditions'] ?? array() ),
				'actions'     => wp_json_encode( $data['actions'] ?? array() ),
				'version'     => $data['version'] ?? 1,
				'enabled'     => $data['enabled'] ?? 1,
			),
			array( '%d', '%s', '%d', '%s', '%s', '%d', '%d' )
		);

		return (int) $wpdb->insert_id;
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public function all( int $limit = 100 ): array {
		global $wpdb;

		return $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . Config::table( 'rules' ) . ' ORDER BY priority ASC LIMIT %d',
				$limit
			),
			ARRAY_A
		) ?: array();
	}
}
