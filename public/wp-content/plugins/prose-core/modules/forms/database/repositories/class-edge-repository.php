<?php
/**
 * Workflow edge repository.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Forms\Database\Repositories;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Edge_Repository
 */
final class Edge_Repository extends Abstract_Repository {

	/**
	 * {@inheritDoc}
	 */
	protected function table_suffix(): string {
		return 'prose_workflow_edges';
	}

	/**
	 * Upsert edge.
	 *
	 * @param array<string, mixed> $data Edge row.
	 * @return int Edge ID.
	 */
	public function upsert( array $data ): int {
		global $wpdb;

		$from = (int) ( $data['from_node_id'] ?? 0 );
		$to   = (int) ( $data['to_node_id'] ?? 0 );

		if ( $from <= 0 || $to <= 0 ) {
			return 0;
		}

		$edge_type     = sanitize_text_field( (string) ( $data['edge_type'] ?? 'next' ) );
		$condition_key = sanitize_text_field( (string) ( $data['condition_key'] ?? '' ) );
		$now           = $this->now();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$existing = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT edge_id FROM {$this->table()} WHERE from_node_id = %d AND to_node_id = %d AND edge_type = %s AND condition_key = %s LIMIT 1",
				$from,
				$to,
				$edge_type,
				$condition_key
			)
		);

		$row = array(
			'from_node_id'   => $from,
			'to_node_id'     => $to,
			'workflow_key'   => sanitize_text_field( (string) ( $data['workflow_key'] ?? '' ) ),
			'edge_type'      => $edge_type,
			'condition_key'  => $condition_key,
			'condition_data' => $this->encode_json( $data['condition_data'] ?? array() ),
			'label'          => sanitize_text_field( (string) ( $data['label'] ?? '' ) ),
			'sequence'       => isset( $data['sequence'] ) ? (int) $data['sequence'] : 0,
			'weight'         => isset( $data['weight'] ) ? (int) $data['weight'] : 0,
			'status'         => sanitize_text_field( (string) ( $data['status'] ?? 'active' ) ),
			'updated_at'     => $now,
		);

		if ( $existing ) {
			$wpdb->update(
				$this->table(),
				$row,
				array( 'edge_id' => (int) $existing->edge_id )
			);

			return (int) $existing->edge_id;
		}

		$row['created_at'] = $now;
		$wpdb->insert( $this->table(), $row );

		return (int) $wpdb->insert_id;
	}

	/**
	 * Outgoing edges from a node.
	 *
	 * @param int $from_node_id From node ID.
	 * @return object[]
	 */
	public function get_outgoing( int $from_node_id ): array {
		return $this->get_all( 'from_node_id = %d AND status = %s ORDER BY sequence ASC', $from_node_id, 'active' );
	}

	/**
	 * All edges for a workflow.
	 *
	 * @param string $workflow_key Workflow key.
	 * @return object[]
	 */
	public function list_by_workflow( string $workflow_key ): array {
		return $this->get_all( 'workflow_key = %s AND status = %s', sanitize_text_field( $workflow_key ), 'active' );
	}

	/**
	 * Recursive path from a node (MySQL 8+ / MariaDB 10.2+).
	 *
	 * @param int $from_node_id Start node ID.
	 * @param int $max_depth    Max depth.
	 * @return object[]
	 */
	public function traverse_from( int $from_node_id, int $max_depth = 20 ): array {
		global $wpdb;

		$table = $this->table();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$sql = "
			WITH RECURSIVE path AS (
				SELECT e.*, 1 AS depth
				FROM {$table} e
				WHERE e.from_node_id = %d AND e.status = 'active'
				UNION ALL
				SELECT e.*, p.depth + 1
				FROM {$table} e
				INNER JOIN path p ON e.from_node_id = p.to_node_id
				WHERE p.depth < %d AND e.status = 'active'
			)
			SELECT * FROM path ORDER BY depth, sequence
		";

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $from_node_id, $max_depth ) );

		return is_array( $rows ) ? $rows : array();
	}
}
