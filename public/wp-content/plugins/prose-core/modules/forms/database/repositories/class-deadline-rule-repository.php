<?php
/**
 * Deadline rules repository (templates).
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Forms\Database\Repositories;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Deadline_Rule_Repository
 */
final class Deadline_Rule_Repository extends Abstract_Repository {

	/**
	 * {@inheritDoc}
	 */
	protected function table_suffix(): string {
		return 'prose_deadline_rules';
	}

	/**
	 * Upsert deadline rule by deadline_key.
	 *
	 * @param array<string, mixed> $data Rule row.
	 * @return int Deadline rule ID.
	 */
	public function upsert( array $data ): int {
		global $wpdb;

		$key = sanitize_text_field( (string) ( $data['deadline_key'] ?? '' ) );

		if ( '' === $key ) {
			return 0;
		}

		$existing = $this->get_by_key( $key );
		$now      = $this->now();

		$row = array(
			'deadline_key'   => $key,
			'workflow_key'   => sanitize_text_field( (string) ( $data['workflow_key'] ?? '' ) ),
			'node_id'        => ! empty( $data['node_id'] ) ? (int) $data['node_id'] : null,
			'trigger_event'  => sanitize_text_field( (string) ( $data['trigger_event'] ?? '' ) ),
			'offset_days'    => isset( $data['offset_days'] ) ? (int) $data['offset_days'] : 0,
			'day_type'       => sanitize_text_field( (string) ( $data['day_type'] ?? 'calendar' ) ),
			'direction'      => sanitize_text_field( (string) ( $data['direction'] ?? 'after' ) ),
			'deadline_kind'  => sanitize_text_field( (string) ( $data['deadline_kind'] ?? 'hard' ) ),
			'applies_scope'  => sanitize_text_field( (string) ( $data['applies_scope'] ?? 'node' ) ),
			'applies_ref'    => sanitize_text_field( (string) ( $data['applies_ref'] ?? '' ) ),
			'county'         => sanitize_text_field( (string) ( $data['county'] ?? '' ) ),
			'statute_ref'    => sanitize_text_field( (string) ( $data['statute_ref'] ?? '' ) ),
			'label'          => sanitize_text_field( (string) ( $data['label'] ?? $key ) ),
			'description'    => sanitize_textarea_field( (string) ( $data['description'] ?? '' ) ),
			'priority'       => isset( $data['priority'] ) ? (int) $data['priority'] : 0,
			'status'         => sanitize_text_field( (string) ( $data['status'] ?? 'active' ) ),
			'updated_at'     => $now,
		);

		if ( $existing ) {
			$wpdb->update(
				$this->table(),
				$row,
				array( 'deadline_id' => (int) $existing->deadline_id )
			);

			return (int) $existing->deadline_id;
		}

		$row['created_at'] = $now;
		$wpdb->insert( $this->table(), $row );

		return (int) $wpdb->insert_id;
	}

	/**
	 * Get rule by key.
	 *
	 * @param string $deadline_key Deadline key.
	 * @return object|null
	 */
	public function get_by_key( string $deadline_key ): ?object {
		return $this->get_row_by( 'deadline_key', sanitize_text_field( $deadline_key ) );
	}

	/**
	 * Rules matching a trigger event.
	 *
	 * @param string $trigger_event Trigger event.
	 * @param string $workflow_key  Optional workflow filter.
	 * @return object[]
	 */
	public function get_by_trigger( string $trigger_event, string $workflow_key = '' ): array {
		if ( '' !== $workflow_key ) {
			return $this->get_all(
				'trigger_event = %s AND workflow_key = %s AND status = %s ORDER BY priority DESC',
				sanitize_text_field( $trigger_event ),
				sanitize_text_field( $workflow_key ),
				'active'
			);
		}

		return $this->get_all(
			'trigger_event = %s AND status = %s ORDER BY priority DESC',
			sanitize_text_field( $trigger_event ),
			'active'
		);
	}

	/**
	 * Rules for a workflow node.
	 *
	 * @param int $node_id Node ID.
	 * @return object[]
	 */
	public function get_by_node( int $node_id ): array {
		return $this->get_all(
			'node_id = %d AND status = %s ORDER BY priority DESC',
			$node_id,
			'active'
		);
	}

	/**
	 * Rule as array.
	 *
	 * @param object $row DB row.
	 * @return array<string, mixed>
	 */
	public function to_array( object $row ): array {
		return array(
			'deadline_id'    => (int) $row->deadline_id,
			'deadline_key'   => (string) $row->deadline_key,
			'workflow_key'   => (string) $row->workflow_key,
			'node_id'        => $row->node_id ? (int) $row->node_id : null,
			'trigger_event'  => (string) $row->trigger_event,
			'offset_days'    => (int) $row->offset_days,
			'day_type'       => (string) $row->day_type,
			'direction'      => (string) $row->direction,
			'deadline_kind'  => (string) $row->deadline_kind,
			'label'          => (string) $row->label,
			'description'    => (string) $row->description,
		);
	}
}
