<?php
/**
 * User document repository — wp_prose_documents.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Users\Database\Repositories;

use ProSe\Core\Forms\Database\Repositories\Abstract_Repository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class User_Document_Repository
 */
final class User_Document_Repository extends Abstract_Repository {

	/**
	 * {@inheritDoc}
	 */
	protected function primary_key_column(): string {
		return 'document_id';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function table_suffix(): string {
		return 'prose_documents';
	}

	/**
	 * Create a document record.
	 *
	 * @param array<string, mixed> $data Document fields.
	 * @return int Document ID.
	 */
	public function create( array $data ): int {
		global $wpdb;

		$now = $this->now();

		$row = array(
			'user_id'          => max( 0, (int) ( $data['user_id'] ?? 0 ) ),
			'case_id'          => max( 0, (int) ( $data['case_id'] ?? 0 ) ),
			'conversation_id'  => ! empty( $data['conversation_id'] ) ? (int) $data['conversation_id'] : null,
			'document_type'    => sanitize_key( (string) ( $data['document_type'] ?? 'generated_pdf' ) ),
			'form_code'        => sanitize_text_field( (string) ( $data['form_code'] ?? '' ) ),
			'title'            => sanitize_text_field( (string) ( $data['title'] ?? '' ) ),
			'file_path'        => sanitize_text_field( (string) ( $data['file_path'] ?? '' ) ),
			'download_token'   => sanitize_text_field( (string) ( $data['download_token'] ?? '' ) ),
			'status'           => sanitize_key( (string) ( $data['status'] ?? 'ready' ) ),
			'created_at'       => $now,
			'updated_at'       => $now,
		);

		$wpdb->insert( $this->table(), $row );

		return (int) $wpdb->insert_id;
	}

	/**
	 * Recent documents for a user.
	 *
	 * @param int $user_id User ID.
	 * @param int $limit   Max rows.
	 * @return object[]
	 */
	public function recent_for_user( int $user_id, int $limit = 10 ): array {
		global $wpdb;

		if ( $user_id <= 0 ) {
			return array();
		}

		$limit = max( 1, min( 50, $limit ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$sql  = "SELECT * FROM {$this->table()} WHERE user_id = %d ORDER BY created_at DESC LIMIT %d";
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $user_id, $limit ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Documents linked to a conversation or its case.
	 *
	 * @param int $user_id         User ID.
	 * @param int $conversation_id Conversation ID.
	 * @param int $case_id         Optional case ID.
	 * @param int $limit           Max rows.
	 * @return object[]
	 */
	public function for_conversation( int $user_id, int $conversation_id, int $case_id = 0, int $limit = 10 ): array {
		global $wpdb;

		if ( $user_id <= 0 || $conversation_id <= 0 ) {
			return array();
		}

		$limit = max( 1, min( 50, $limit ) );

		if ( $case_id > 0 ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$sql = "SELECT * FROM {$this->table()} WHERE user_id = %d AND (conversation_id = %d OR case_id = %d OR conversation_id IS NULL) ORDER BY created_at DESC LIMIT %d";
			$rows = $wpdb->get_results( $wpdb->prepare( $sql, $user_id, $conversation_id, $case_id, $limit ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		} else {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$sql  = "SELECT * FROM {$this->table()} WHERE user_id = %d AND (conversation_id = %d OR conversation_id IS NULL) ORDER BY created_at DESC LIMIT %d";
			$rows = $wpdb->get_results( $wpdb->prepare( $sql, $user_id, $conversation_id, $limit ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Build a download URL for a document row.
	 *
	 * @param object $row Document row.
	 * @return string
	 */
	public function download_url_for_row( object $row ): string {
		$file_path = (string) ( $row->file_path ?? '' );

		if ( '' !== $file_path ) {
			$upload_dir = wp_upload_dir();

			if ( ! empty( $upload_dir['baseurl'] ) ) {
				return trailingslashit( $upload_dir['baseurl'] ) . ltrim( $file_path, '/' );
			}
		}

		$token = (string) ( $row->download_token ?? '' );

		if ( '' !== $token ) {
			if ( str_starts_with( $token, 'http://' ) || str_starts_with( $token, 'https://' ) ) {
				return $token;
			}

			return rest_url( 'prose/v1/documents/download/' . rawurlencode( $token ) );
		}

		return '';
	}
}
