<?php
/**
 * Workflow catalog repository.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Forms\Database\Repositories;

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
}
