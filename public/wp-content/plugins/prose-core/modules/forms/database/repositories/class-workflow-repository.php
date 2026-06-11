<?php
/**
 * Workflow catalog repository.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Forms\Database\Repositories;

use ProSe\Core\Forms\Database\Import\Import_Run_Context;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Workflow_Repository
 */
final class Workflow_Repository extends Abstract_Repository {

	/**
	 * {@inheritDoc}
	 */
	protected function primary_key_column(): string {
		return 'workflow_id';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function table_suffix(): string {
		return 'prose_workflows';
	}

	/**
	 * Upsert workflow by workflow_key.
	 *
	 * @param array<string, mixed> $data Workflow row.
	 * @return int Workflow surrogate ID.
	 */
	public function upsert( array $data ): int {
		global $wpdb;

		$key = sanitize_text_field( (string) ( $data['workflow_key'] ?? '' ) );

		if ( '' === $key ) {
			return 0;
		}

		$existing = $this->get_by_key( $key );
		$now      = $this->now();

		$row = array(
			'workflow_key'  => $key,
			'workflow_name' => sanitize_text_field( (string) ( $data['workflow_name'] ?? $key ) ),
			'court_routing' => sanitize_text_field( (string) ( $data['court_routing'] ?? '' ) ),
			'description'   => sanitize_textarea_field( (string) ( $data['description'] ?? '' ) ),
			'is_active'     => isset( $data['is_active'] ) ? (int) (bool) $data['is_active'] : 1,
			'sort_order'    => isset( $data['sort_order'] ) ? (int) $data['sort_order'] : 0,
			'updated_at'    => $now,
		);

		if ( $existing ) {
			$wpdb->update(
				$this->table(),
				$row,
				array( 'workflow_id' => (int) $existing->workflow_id )
			);

			return (int) $existing->workflow_id;
		}

		$row['created_at'] = $now;

		$wpdb->insert( $this->table(), $row );

		return (int) $wpdb->insert_id;
	}

	/**
	 * Get workflow by logical key.
	 *
	 * @param string $workflow_key Workflow key.
	 * @return object|null
	 */
	public function get_by_key( string $workflow_key ): ?object {
		return $this->get_row_by( 'workflow_key', sanitize_text_field( $workflow_key ) );
	}

	/**
	 * List active workflows ordered by sort_order.
	 *
	 * @return object[]
	 */
	public function list_active(): array {
		return $this->get_all( 'is_active = %d ORDER BY sort_order ASC', 1 );
	}

	/**
	 * Check whether a workflow_key exists.
	 *
	 * @param string $workflow_key Workflow key.
	 * @return bool
	 */
	public function key_exists( string $workflow_key ): bool {
		return null !== $this->get_by_key( $workflow_key );
	}

	/**
	 * All workflow keys in catalog.
	 *
	 * @return string[]
	 */
	public function all_keys(): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$keys = $wpdb->get_col( "SELECT workflow_key FROM {$this->table()} ORDER BY sort_order ASC" );

		return is_array( $keys ) ? array_map( 'strval', $keys ) : array();
	}

	/**
	 * Idempotent upsert with import run tracking.
	 *
	 * @param array<string, mixed> $data        Workflow row.
	 * @param Import_Run_Context   $context     Import context.
	 * @return array{action: string, id: int}
	 */
	public function upsert_with_context( array $data, Import_Run_Context $context ): array {
		$key = sanitize_text_field( (string) ( $data['workflow_key'] ?? '' ) );

		if ( '' === $key ) {
			return array( 'action' => 'skipped', 'id' => 0 );
		}

		$hash_fields = array(
			'workflow_key'  => $key,
			'workflow_name' => (string) ( $data['workflow_name'] ?? '' ),
			'court_routing' => (string) ( $data['court_routing'] ?? '' ),
			'description'   => (string) ( $data['description'] ?? '' ),
			'is_active'     => (bool) ( $data['is_active'] ?? true ),
			'sort_order'    => (int) ( $data['sort_order'] ?? 0 ),
		);
		$hash     = Import_Run_Context::content_hash( $hash_fields );
		$existing = $this->get_by_key( $key );
		$action   = $context->resolve_action( 'workflows', $key, $hash, $existing );

		if ( 'unchanged' === $action ) {
			$context->record( 'workflows', $key, $action, (array) $existing, (array) $existing, $hash );
			return array( 'action' => $action, 'id' => (int) $existing->workflow_id );
		}

		$before = $existing ? (array) $existing : array();
		$id     = $this->upsert( $data );
		$after  = $this->get_by_id( $id );

		$context->record( 'workflows', $key, $action, $before, $after ? (array) $after : array(), $hash );

		return array( 'action' => $action, 'id' => $id );
	}

	/**
	 * Archive workflows not present in the import scope.
	 *
	 * @param string[]           $active_keys Keys from artifact.
	 * @param Import_Run_Context $context     Import context.
	 * @return int Archived count.
	 */
	public function archive_missing( array $active_keys, Import_Run_Context $context ): int {
		global $wpdb;

		$scope = array_flip( $active_keys );
		$count = 0;

		foreach ( $this->get_all( 'is_active = %d', 1 ) as $row ) {
			$key = (string) $row->workflow_key;
			if ( isset( $scope[ $key ] ) ) {
				continue;
			}

			$before = (array) $row;
			$wpdb->update(
				$this->table(),
				array(
					'is_active'  => 0,
					'updated_at' => $this->now(),
				),
				array( 'workflow_id' => (int) $row->workflow_id )
			);
			$after = (array) $this->get_by_id( (int) $row->workflow_id );
			$context->record( 'workflows', $key, 'archive', $before, $after, Import_Run_Context::content_hash( $after ) );
			++$count;
		}

		return $count;
	}
}
