<?php
/**
 * Document repository — persists generated-document status on the existing
 * wp_prose_case_forms table (no schema changes).
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Forms\Database\Repositories;

use ProSe\Core\Forms\Documents\Document_Status;
use ProSe\Core\Forms\Documents\Generated_Document;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Document_Repository
 *
 * Tracks the generation lifecycle status of each case form against the
 * existing wp_prose_case_forms table. The generated field payload and audit
 * trail are carried on the in-memory Generated_Document DTO (ready for the
 * future PDF renderer); this repository owns only the durable status and
 * form-linkage columns, so the schema is unchanged.
 */
final class Document_Repository extends Abstract_Repository {

	/**
	 * {@inheritDoc}
	 */
	protected function table_suffix(): string {
		return 'prose_case_forms';
	}

	/**
	 * Persist a generated document's status + linkage for a case.
	 *
	 * @param int                $case_id  Case ID.
	 * @param Generated_Document $document Generated document.
	 * @return int Row ID (0 on failure).
	 */
	public function save_document( int $case_id, Generated_Document $document ): int {
		return $this->save_status(
			$case_id,
			$document->package_key(),
			$document->form_code(),
			$document->status(),
			$document->form_id(),
			$document->requirement()
		);
	}

	/**
	 * Upsert the status row for a single case form.
	 *
	 * @param int    $case_id     Case ID.
	 * @param string $package_key Package key.
	 * @param string $form_code   Form code.
	 * @param string $status      Document status.
	 * @param int    $form_id     Form post ID (optional).
	 * @param string $requirement Requirement (required|optional).
	 * @return int Row ID (0 on failure).
	 */
	public function save_status(
		int $case_id,
		string $package_key,
		string $form_code,
		string $status,
		int $form_id = 0,
		string $requirement = 'required'
	): int {
		global $wpdb;

		$form_code = sanitize_text_field( $form_code );

		if ( $case_id <= 0 || '' === $form_code ) {
			return 0;
		}

		if ( ! Document_Status::is_valid( $status ) ) {
			$status = Document_Status::NOT_STARTED;
		}

		$now      = $this->now();
		$complete = $this->is_terminal_status( $status ) ? $now : null;

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$sql      = "SELECT id FROM {$this->table()} WHERE case_id = %d AND package_key = %s AND form_code = %s LIMIT 1";
		$existing = $wpdb->get_row( $wpdb->prepare( $sql, $case_id, $package_key, $form_code ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		$row = array(
			'case_id'      => $case_id,
			'package_key'  => sanitize_text_field( $package_key ),
			'form_code'    => $form_code,
			'form_id'      => $form_id > 0 ? $form_id : null,
			'requirement'  => sanitize_text_field( $requirement ),
			'status'       => $status,
			'completed_at' => $complete,
			'updated_at'   => $now,
		);

		if ( $existing ) {
			$wpdb->update( $this->table(), $row, array( 'id' => (int) $existing->id ) );

			return (int) $existing->id;
		}

		$row['created_at'] = $now;
		$wpdb->insert( $this->table(), $row );

		return (int) $wpdb->insert_id;
	}

	/**
	 * Persist all documents in a list for a case.
	 *
	 * @param int                  $case_id   Case ID.
	 * @param Generated_Document[] $documents Documents.
	 * @return int Number of rows written.
	 */
	public function save_documents( int $case_id, array $documents ): int {
		$count = 0;

		foreach ( $documents as $document ) {
			if ( $document instanceof Generated_Document && $this->save_document( $case_id, $document ) > 0 ) {
				++$count;
			}
		}

		return $count;
	}

	/**
	 * Status of a single case form.
	 *
	 * @param int    $case_id     Case ID.
	 * @param string $package_key Package key.
	 * @param string $form_code   Form code.
	 * @return string Status (Document_Status::NOT_STARTED when absent).
	 */
	public function get_status( int $case_id, string $package_key, string $form_code ): string {
		global $wpdb;

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$sql = "SELECT status FROM {$this->table()} WHERE case_id = %d AND package_key = %s AND form_code = %s LIMIT 1";
		$row = $wpdb->get_row( $wpdb->prepare( $sql, $case_id, sanitize_text_field( $package_key ), sanitize_text_field( $form_code ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		$status = $row ? (string) $row->status : Document_Status::NOT_STARTED;

		return Document_Status::is_valid( $status ) ? $status : Document_Status::NOT_STARTED;
	}

	/**
	 * Transition the status of a single case form (forward-only).
	 *
	 * @param int    $case_id     Case ID.
	 * @param string $package_key Package key.
	 * @param string $form_code   Form code.
	 * @param string $status      Target status.
	 * @return bool
	 */
	public function transition_status( int $case_id, string $package_key, string $form_code, string $status ): bool {
		$current = $this->get_status( $case_id, $package_key, $form_code );

		if ( ! Document_Status::can_transition( $current, $status ) ) {
			return false;
		}

		return $this->save_status( $case_id, $package_key, $form_code, $status ) > 0;
	}

	/**
	 * Tracked form rows for a case.
	 *
	 * @param int $case_id Case ID.
	 * @return object[]
	 */
	public function list_for_case( int $case_id ): array {
		return $this->get_all( 'case_id = %d ORDER BY id ASC', $case_id );
	}

	/**
	 * Tracked form rows for a case package.
	 *
	 * @param int    $case_id     Case ID.
	 * @param string $package_key Package key.
	 * @return object[]
	 */
	public function list_for_package( int $case_id, string $package_key ): array {
		return $this->get_all(
			'case_id = %d AND package_key = %s ORDER BY id ASC',
			$case_id,
			sanitize_text_field( $package_key )
		);
	}

	/**
	 * Whether a status is a terminal / completed milestone.
	 *
	 * @param string $status Status.
	 * @return bool
	 */
	private function is_terminal_status( string $status ): bool {
		return in_array(
			$status,
			array( Document_Status::GENERATED, Document_Status::SIGNED, Document_Status::FILED ),
			true
		);
	}
}
