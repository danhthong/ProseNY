<?php
/**
 * Case deadlines repository (instances).
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Forms\Database\Repositories;

use ProSe\Core\Forms\Engine\Case_Deadline;
use ProSe\Core\Forms\Engine\Deadline_Catalog;
use ProSe\Core\Forms\Engine\Deadline_Status;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Case_Deadline_Repository
 *
 * Persistence layer for wp_prose_case_deadlines. Also serves as the
 * DeadlineRepository deliverable for the Timeline Engine.
 */
final class Case_Deadline_Repository extends Abstract_Repository {

	/**
	 * {@inheritDoc}
	 */
	protected function primary_key_column(): string {
		return 'case_deadline_id';
	}

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
	 * Persist a Case_Deadline DTO.
	 *
	 * @param Case_Deadline $deadline Deadline DTO.
	 * @return int Case deadline ID.
	 */
	public function upsert_from_dto( Case_Deadline $deadline ): int {
		$rule_id = Deadline_Catalog::synthetic_id_for_key( $deadline->deadline_key() );

		return $this->upsert(
			array(
				'case_id'           => $deadline->case_id(),
				'workflow_key'      => $deadline->workflow_key(),
				'deadline_rule_id'  => $rule_id,
				'title'             => $deadline->label(),
				'due_date'          => $deadline->due_date(),
				'completed'         => $deadline->completed(),
				'completed_at'      => $deadline->completed_at(),
				'source_event'      => $deadline->trigger_event(),
				'source_event_date' => $deadline->anchor_date(),
				'day_type'          => $deadline->day_type(),
				'status'            => strtolower( $deadline->status() ),
			)
		);
	}

	/**
	 * Load deadlines for a case as DTOs.
	 *
	 * @param int $case_id Case ID.
	 * @param int $now     Current Unix timestamp for status resolution.
	 * @return Case_Deadline[]
	 */
	public function find_for_case( int $case_id, int $now = 0 ): array {
		$now       = $now > 0 ? $now : time();
		$deadlines = array();

		foreach ( $this->list_for_case( $case_id ) as $row ) {
			$deadlines[] = $this->row_to_dto( $row, $now );
		}

		return $deadlines;
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
				'case_id = %d AND completed = 0 AND status != %s ORDER BY due_date ASC',
				$case_id,
				'cancelled'
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
				'status'       => strtolower( Deadline_Status::COMPLETED ),
				'updated_at'   => $this->now(),
			),
			array( 'case_deadline_id' => $case_deadline_id ),
			array( '%d', '%s', '%s', '%s' ),
			array( '%d' )
		);

		return false !== $updated;
	}

	/**
	 * Cancel a case deadline.
	 *
	 * @param int $case_deadline_id Case deadline ID.
	 * @return bool
	 */
	public function cancel( int $case_deadline_id ): bool {
		global $wpdb;

		$updated = $wpdb->update(
			$this->table(),
			array(
				'status'     => strtolower( Deadline_Status::CANCELLED ),
				'updated_at' => $this->now(),
			),
			array( 'case_deadline_id' => $case_deadline_id ),
			array( '%s', '%s' ),
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

	/**
	 * Map a DB row to a Case_Deadline DTO.
	 *
	 * @param object $row DB row.
	 * @param int    $now Current Unix timestamp.
	 * @return Case_Deadline
	 */
	private function row_to_dto( object $row, int $now ): Case_Deadline {
		$workflow_key = (string) $row->workflow_key;
		$deadline_key = Deadline_Catalog::key_for_synthetic_id( (int) $row->deadline_rule_id );
		$rule         = '' !== $deadline_key
			? Deadline_Catalog::rule_by_key( $workflow_key, $deadline_key )
			: null;

		$completed = (bool) $row->completed;
		$cancelled = strtolower( (string) $row->status ) === strtolower( Deadline_Status::CANCELLED );
		$status    = Deadline_Status::resolve(
			(string) $row->due_date,
			$completed,
			$cancelled,
			$now
		);

		return new Case_Deadline(
			$deadline_key,
			(string) $row->title,
			(string) $row->due_date,
			(string) $row->source_event,
			$row->source_event_date ? (string) $row->source_event_date : '',
			(string) $row->day_type,
			$status,
			$completed,
			(string) ( $rule['next_action'] ?? '' ),
			(int) $row->case_deadline_id,
			(int) $row->case_id,
			$workflow_key,
			$row->completed_at ? (string) $row->completed_at : null,
			$cancelled
		);
	}
}
