<?php
/**
 * Validate CourtFlow schema integrity.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Forms\Database;

use ProSe\Core\Forms\Database\Repositories\Node_Repository;
use ProSe\Core\Forms\Database\Repositories\Workflow_Repository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Schema_Validator
 */
final class Schema_Validator {

	/**
	 * Run integrity checks.
	 *
	 * @return array<string, mixed>
	 */
	public function validate(): array {
		global $wpdb;

		$workflows  = new Workflow_Repository();
		$nodes      = new Node_Repository();
		$valid_keys = $workflows->all_keys();

		$orphan_nodes  = array();
		$orphan_edges  = array();
		$explain_notes = array();

		$node_table = Database_Installer::table( 'prose_workflow_nodes' );
		$edge_table = Database_Installer::table( 'prose_workflow_edges' );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$node_rows = $wpdb->get_results( "SELECT node_id, node_key, workflow_key FROM {$node_table}" );
		$node_map  = array();

		if ( is_array( $node_rows ) ) {
			foreach ( $node_rows as $row ) {
				$node_map[ (int) $row->node_id ] = (string) $row->node_key;

				if ( ! in_array( (string) $row->workflow_key, $valid_keys, true ) ) {
					$orphan_nodes[] = (string) $row->node_key;
				}
			}
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$edge_rows = $wpdb->get_results( "SELECT edge_id, from_node_id, to_node_id FROM {$edge_table} WHERE status = 'active'" );

		if ( is_array( $edge_rows ) ) {
			foreach ( $edge_rows as $edge ) {
				$from = (int) $edge->from_node_id;
				$to   = (int) $edge->to_node_id;

				if ( ! isset( $node_map[ $from ] ) || ! isset( $node_map[ $to ] ) ) {
					$orphan_edges[] = (int) $edge->edge_id;
				}
			}
		}

		if ( ! empty( $node_map ) ) {
			$sample_id = (int) array_key_first( $node_map );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$explain = $wpdb->get_results(
				$wpdb->prepare(
					"EXPLAIN SELECT * FROM {$edge_table} WHERE from_node_id = %d AND status = 'active' ORDER BY sequence ASC",
					$sample_id
				)
			);

			if ( is_array( $explain ) && ! empty( $explain[0]->key ) ) {
				$explain_notes[] = 'edge_outgoing_uses_index:' . (string) $explain[0]->key;
			}
		}

		$needs_review = array_values( array_unique( array_merge( $orphan_nodes, array_map( 'strval', $orphan_edges ) ) ) );

		return array(
			'valid'          => empty( $orphan_nodes ) && empty( $orphan_edges ),
			'workflow_count' => count( $valid_keys ),
			'node_count'     => count( is_array( $node_rows ) ? $node_rows : array() ),
			'edge_count'     => count( is_array( $edge_rows ) ? $edge_rows : array() ),
			'orphan_nodes'   => $orphan_nodes,
			'orphan_edges'   => $orphan_edges,
			'explain_notes'  => $explain_notes,
			'needs_review'   => $needs_review,
		);
	}
}
