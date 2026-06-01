<?php
/**
 * Generated documents repository.
 *
 * @package ProseCore
 */

namespace Prose\Core\Database\Repositories;

use Prose\Core\Support\Config;

final class DocumentRepository {

	/**
	 * @param array<string, mixed> $data
	 */
	public function create( array $data ): int {
		global $wpdb;

		$wpdb->insert(
			Config::table( 'generated_documents' ),
			array(
				'session_id'            => $data['session_id'],
				'form_slug'             => $data['form_slug'],
				'storage_path'          => $data['storage_path'],
				'file_hash'             => $data['file_hash'] ?? '',
				'signed_url_expires_at' => $data['signed_url_expires_at'] ?? null,
			),
			array( '%d', '%s', '%s', '%s', '%s' )
		);

		return (int) $wpdb->insert_id;
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public function find( int $id ): ?array {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM ' . Config::table( 'generated_documents' ) . ' WHERE id = %d', $id ),
			ARRAY_A
		);

		return $row ?: null;
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public function for_session( int $session_id ): array {
		global $wpdb;

		return $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . Config::table( 'generated_documents' ) . ' WHERE session_id = %d ORDER BY created_at DESC',
				$session_id
			),
			ARRAY_A
		) ?: array();
	}

	public function find_by_hash( string $hash ): ?array {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . Config::table( 'generated_documents' ) . ' WHERE file_hash = %s LIMIT 1',
				$hash
			),
			ARRAY_A
		);

		return $row ?: null;
	}
}
