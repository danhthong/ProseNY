<?php
/**
 * Case deadlines repository (instances).
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Forms\Database\Repositories;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Case_Deadline_Repository
 */
final class Case_Deadline_Repository extends Abstract_Repository {

	/**
	 * {@inheritDoc}
	 */
	protected function table_suffix(): string {
		return 'prose_case_deadlines';
	}

	/**
	 * Create or update a case deadline (idempotent on case + rule + event).
	 *
	 * @param array<string, mixed> $data Case deadline row.
	 * @return int Case deadline ID.
	 */
	public function upsert( array $data ): int {
		global $wpdb;

		$case_id          = (int) ( $data['case_id'] ?? 0 );
		$deadline_rule_id = (int) ( $data['deadline_rule_id'] ?? 0 );
		$source_event     = sanitize_text_field( (string) ( $data['source_event'] ?? '' ) );

		if ( $case_id <= 0 || $deadline_rule_id <= 0 ) {
			return 0;
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$existing = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT case_deadline_id FROM {$this->table()} WHERE case_id = %d AND deadline_rule_id = %d AND source_event = %s LIMIT 1",
				$case_id,
				$deadline_rule_id,
				$source_event
			)
		);

		$now = $this->now();

		$row = array(
			'case_id'           => $case_id,
			'workflow_key'      => sanitize_text_field( (string) ( $data['workflow_key'] ?? '' ) ),
			'node_id'           => ! empty( $data['node_id'] ) ? (int) $data['node_id'] : null,
			'deadline_rule_id'  => $deadline_rule_id,
			'title'             => sanitize_text_field( (string) ( $data['title'] ?? '' ) ),
			'due_date'          => sanitize_text_field( (string) ( $data['due_date'] ?? $now ) ),
			'completed'         => isset( $data['completed'] ) ? (int) (bool) $data['completed'] : 0,
			'completed_at'      => ! empty( $data['completed_at'] ) ? sanitize_text_field( (string) $data['completed_at'] ) : null,
			'source_event'      => $source_event,
			'source_event_date' => ! empty( $data['source_event_date'] ) ? sanitize_text_field( (string) $data['source_event_date'] ) : null,
			'day_type'          => sanitize_text_field( (string) ( $data['day_type'] ?? 'calendar' ) ),
			'status'            => sanitize_text_field( (string) ( $data['status'] ?? 'pending' ) ),
			'county'            => sanitize_text_field( (string) ( $data['county'] ?? '' ) ),
			'updated_at'        => $now,
		);

		if ( $existing ) {
			$wpdb->update(
				$this->table(),
				$row,
				array( 'case_deadline_id' => (int) $existing->case_deadline_id )
			);

			return (int) $existing->case_deadline_id;
		}

		$row['created_at'] = $now;
		$wpdb->insert( $this->table(), $row );

		return (int) $wpdb->insert_id;
	}

	/**
	 * List deadlines for a case ordered by due date.
	 *
	 * @param int  $case_id   Case ID.
	 * @param bool $open_only Only incomplete deadlines.
	 * @return object[]
	 */
	public function list_for_case( int $case_id, bool $open_only = false ): array {
		if ( $open_only ) {
			return $this->get_all(
				'case_id = %d AND completed = 0 ORDER BY due_date ASC',
				$case_id
			);
		}

		return $this->get_all( 'case_id = %d ORDER BY due_date ASC', $case_id );
	}

	/**
	 * Mark deadline complete.
	 *
	 * @param int $case_deadline_id Case deadline ID.
	 * @return bool
	 */
	public function mark_complete( int $case_deadline_id ): bool {
		global $wpdb;

		$updated = $wpdb->update(
			$this->table(),
			array(
				'completed'    => 1,
				'completed_at' => $this->now(),
				'status'       => 'completed',
				'updated_at'   => $this->now(),
			),
			array( 'case_deadline_id' => $case_deadline_id ),
			array( '%d', '%s', '%s', '%s' ),
			array( '%d' )
		);

		return false !== $updated;
	}

	/**
	 * Row as array.
	 *
	 * @param object $row DB row.
	 * @return array<string, mixed>
	 */
	public function to_array( object $row ): array {
		return array(
			'case_deadline_id'  => (int) $row->case_deadline_id,
			'case_id'           => (int) $row->case_id,
			'workflow_key'      => (string) $row->workflow_key,
			'node_id'           => $row->node_id ? (int) $row->node_id : null,
			'deadline_rule_id'  => (int) $row->deadline_rule_id,
			'title'             => (string) $row->title,
			'due_date'          => (string) $row->due_date,
			'completed'         => (bool) $row->completed,
			'completed_at'      => $row->completed_at ? (string) $row->completed_at : null,
			'source_event'      => (string) $row->source_event,
			'source_event_date' => $row->source_event_date ? (string) $row->source_event_date : null,
			'status'            => (string) $row->status,
		);
	}
}
