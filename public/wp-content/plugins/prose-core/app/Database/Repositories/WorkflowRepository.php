<?php
/**
 * Workflow nodes and transitions repository.
 *
 * @package ProseCore
 */

namespace Prose\Core\Database\Repositories;

use Prose\Core\Support\Config;

final class WorkflowRepository {

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public function nodes( int $workflow_id ): array {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . Config::table( 'workflow_nodes' ) . ' WHERE workflow_id = %d ORDER BY sort_order ASC',
				$workflow_id
			),
			ARRAY_A
		);

		foreach ( $rows as &$row ) {
			$row['config'] = json_decode( $row['config'] ?? '{}', true ) ?: array();
		}

		return $rows ?: array();
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public function node_by_slug( int $workflow_id, string $slug ): ?array {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . Config::table( 'workflow_nodes' ) . ' WHERE workflow_id = %d AND slug = %s',
				$workflow_id,
				$slug
			),
			ARRAY_A
		);

		if ( $row ) {
			$row['config'] = json_decode( $row['config'] ?? '{}', true ) ?: array();
		}

		return $row ?: null;
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public function transitions( int $workflow_id ): array {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . Config::table( 'workflow_transitions' ) . ' WHERE workflow_id = %d ORDER BY priority DESC',
				$workflow_id
			),
			ARRAY_A
		);

		foreach ( $rows as &$row ) {
			$row['condition'] = json_decode( $row['condition_json'] ?? '{}', true ) ?: array();
		}

		return $rows ?: array();
	}

	/**
	 * @param array<string, mixed> $data
	 */
	public function create_node( array $data ): int {
		global $wpdb;

		$wpdb->insert(
			Config::table( 'workflow_nodes' ),
			array(
				'workflow_id' => $data['workflow_id'],
				'slug'        => $data['slug'],
				'node_type'   => $data['node_type'] ?? 'intake_question',
				'title'       => $data['title'] ?? '',
				'config'      => wp_json_encode( $data['config'] ?? array() ),
				'sort_order'  => $data['sort_order'] ?? 0,
			),
			array( '%d', '%s', '%s', '%s', '%s', '%d' )
		);

		return (int) $wpdb->insert_id;
	}

	/**
	 * @param array<string, mixed> $data
	 */
	public function create_transition( array $data ): int {
		global $wpdb;

		$wpdb->insert(
			Config::table( 'workflow_transitions' ),
			array(
				'workflow_id'    => $data['workflow_id'],
				'from_node_id'   => $data['from_node_id'],
				'to_node_id'     => $data['to_node_id'],
				'condition_json' => wp_json_encode( $data['condition'] ?? array( 'always' => true ) ),
				'priority'       => $data['priority'] ?? 0,
			),
			array( '%d', '%d', '%d', '%s', '%d' )
		);

		return (int) $wpdb->insert_id;
	}
}
