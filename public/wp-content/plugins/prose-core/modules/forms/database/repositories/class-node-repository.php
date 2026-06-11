<?php
/**
 * Workflow node repository.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Forms\Database\Repositories;

use ProSe\Core\Forms\Database\Import\Import_Run_Context;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Node_Repository
 */
final class Node_Repository extends Abstract_Repository {

	/**
	 * {@inheritDoc}
	 */
	protected function primary_key_column(): string {
		return 'node_id';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function table_suffix(): string {
		return 'prose_workflow_nodes';
	}

	/**
	 * Upsert node by node_key.
	 *
	 * @param array<string, mixed> $data Node row.
	 * @return int Node ID.
	 */
	public function upsert( array $data ): int {
		global $wpdb;

		$key = sanitize_text_field( (string) ( $data['node_key'] ?? '' ) );

		if ( '' === $key ) {
			return 0;
		}

		$existing = $this->get_by_key( $key );
		$now      = $this->now();

		$row = array(
			'node_key'            => $key,
			'workflow_key'        => sanitize_text_field( (string) ( $data['workflow_key'] ?? '' ) ),
			'stage'               => sanitize_text_field( (string) ( $data['stage'] ?? '' ) ),
			'court_routing'       => sanitize_text_field( (string) ( $data['court_routing'] ?? '' ) ),
			'node_type'           => sanitize_text_field( (string) ( $data['node_type'] ?? '' ) ),
			'label'               => sanitize_text_field( (string) ( $data['label'] ?? $key ) ),
			'primary_package_id'  => ! empty( $data['primary_package_id'] ) ? (int) $data['primary_package_id'] : null,
			'responsible_party'   => sanitize_text_field( (string) ( $data['responsible_party'] ?? '' ) ),
			'instructions'        => sanitize_textarea_field( (string) ( $data['instructions'] ?? '' ) ),
			'trigger_events'      => $this->encode_json( $data['trigger_events'] ?? array() ),
			'completion_events'   => $this->encode_json( $data['completion_events'] ?? array() ),
			'sequence'            => isset( $data['sequence'] ) ? (int) $data['sequence'] : 0,
			'is_entry'            => isset( $data['is_entry'] ) ? (int) (bool) $data['is_entry'] : 0,
			'is_terminal'         => isset( $data['is_terminal'] ) ? (int) (bool) $data['is_terminal'] : 0,
			'status'              => sanitize_text_field( (string) ( $data['status'] ?? 'active' ) ),
			'updated_at'          => $now,
		);

		if ( $existing ) {
			$wpdb->update(
				$this->table(),
				$row,
				array( 'node_id' => (int) $existing->node_id )
			);

			return (int) $existing->node_id;
		}

		$row['created_at'] = $now;
		$wpdb->insert( $this->table(), $row );

		return (int) $wpdb->insert_id;
	}

	/**
	 * Get node by key.
	 *
	 * @param string $node_key Node key.
	 * @return object|null
	 */
	public function get_by_key( string $node_key ): ?object {
		return $this->get_row_by( 'node_key', sanitize_text_field( $node_key ) );
	}

	/**
	 * Get node ID by key.
	 *
	 * @param string $node_key Node key.
	 * @return int
	 */
	public function get_id_by_key( string $node_key ): int {
		$row = $this->get_by_key( $node_key );

		return $row ? (int) $row->node_id : 0;
	}

	/**
	 * List nodes for a workflow.
	 *
	 * @param string $workflow_key Workflow key.
	 * @return object[]
	 */
	public function list_by_workflow( string $workflow_key ): array {
		return $this->get_all( 'workflow_key = %s AND status = %s ORDER BY sequence ASC', sanitize_text_field( $workflow_key ), 'active' );
	}

	/**
	 * Idempotent upsert with import run tracking.
	 *
	 * @param array<string, mixed> $data    Node row.
	 * @param Import_Run_Context   $context Import context.
	 * @return array{action: string, id: int}
	 */
	public function upsert_with_context( array $data, Import_Run_Context $context ): array {
		$key = sanitize_text_field( (string) ( $data['node_key'] ?? '' ) );

		if ( '' === $key ) {
			return array( 'action' => 'skipped', 'id' => 0 );
		}

		$hash_fields = array(
			'node_key'          => $key,
			'workflow_key'      => (string) ( $data['workflow_key'] ?? '' ),
			'stage'             => (string) ( $data['stage'] ?? '' ),
			'court_routing'     => (string) ( $data['court_routing'] ?? '' ),
			'node_type'         => (string) ( $data['node_type'] ?? '' ),
			'label'             => (string) ( $data['label'] ?? '' ),
			'trigger_events'    => $data['trigger_events'] ?? array(),
			'completion_events' => $data['completion_events'] ?? array(),
			'sequence'          => (int) ( $data['sequence'] ?? 0 ),
			'is_entry'          => (bool) ( $data['is_entry'] ?? false ),
			'is_terminal'       => (bool) ( $data['is_terminal'] ?? false ),
		);
		$hash     = Import_Run_Context::content_hash( $hash_fields );
		$existing = $this->get_by_key( $key );
		$action   = $context->resolve_action( 'nodes', $key, $hash, $existing );

		if ( 'unchanged' === $action ) {
			$context->record( 'nodes', $key, $action, (array) $existing, (array) $existing, $hash );
			return array( 'action' => $action, 'id' => (int) $existing->node_id );
		}

		$before = $existing ? (array) $existing : array();
		$id     = $this->upsert( $data );
		$after  = $this->get_by_id( $id );

		$context->record( 'nodes', $key, $action, $before, $after ? (array) $after : array(), $hash );

		return array( 'action' => $action, 'id' => $id );
	}

	/**
	 * Archive nodes not in import scope.
	 *
	 * @param string[]           $active_keys Active node keys.
	 * @param Import_Run_Context $context     Import context.
	 * @return int
	 */
	public function archive_missing( array $active_keys, Import_Run_Context $context ): int {
		global $wpdb;

		$scope = array_flip( $active_keys );
		$count = 0;

		foreach ( $this->get_all( "status = %s", 'active' ) as $row ) {
			$key = (string) $row->node_key;
			if ( isset( $scope[ $key ] ) ) {
				continue;
			}

			$before = (array) $row;
			$wpdb->update(
				$this->table(),
				array(
					'status'     => 'archived',
					'updated_at' => $this->now(),
				),
				array( 'node_id' => (int) $row->node_id )
			);
			$after = (array) $this->get_by_id( (int) $row->node_id );
			$context->record( 'nodes', $key, 'archive', $before, $after, Import_Run_Context::content_hash( $after ) );
			++$count;
		}

		return $count;
	}

	/**
	 * Node as array for API.
	 *
	 * @param object $row DB row.
	 * @return array<string, mixed>
	 */
	public function to_array( object $row ): array {
		return array(
			'node_id'             => (int) $row->node_id,
			'node_key'            => (string) $row->node_key,
			'workflow_key'        => (string) $row->workflow_key,
			'stage'               => (string) $row->stage,
			'court_routing'       => (string) $row->court_routing,
			'node_type'           => (string) $row->node_type,
			'label'               => (string) $row->label,
			'primary_package_id'  => $row->primary_package_id ? (int) $row->primary_package_id : null,
			'responsible_party'   => (string) $row->responsible_party,
			'instructions'        => (string) $row->instructions,
			'trigger_events'      => $this->decode_json( $row->trigger_events ?? '' ),
			'completion_events'   => $this->decode_json( $row->completion_events ?? '' ),
			'sequence'            => (int) $row->sequence,
			'is_entry'            => (bool) $row->is_entry,
			'is_terminal'         => (bool) $row->is_terminal,
			'status'              => (string) $row->status,
		);
	}
}
