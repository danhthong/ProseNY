<?php
/**
 * User case repository.
 *
 * @package ProseCore
 */

namespace Prose\Core\Database\Repositories;

use Prose\Core\Support\Config;

final class CaseRepository {

	public function create( int $user_id, string $case_type = 'divorce', ?int $county_id = null ): int {
		global $wpdb;

		$data    = array(
			'user_id'   => $user_id,
			'case_type' => $case_type,
			'status'    => 'active',
		);
		$formats = array( '%d', '%s', '%s' );

		if ( null !== $county_id ) {
			$data['county_id'] = $county_id;
			$formats[]         = '%d';
		}

		$inserted = $wpdb->insert( Config::table( 'user_cases' ), $data, $formats );

		if ( false === $inserted ) {
			throw new \RuntimeException( 'Failed to create case: ' . (string) $wpdb->last_error );
		}

		return (int) $wpdb->insert_id;
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public function find( int $id ): ?array {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM ' . Config::table( 'user_cases' ) . ' WHERE id = %d', $id ),
			ARRAY_A
		);

		return $row ?: null;
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public function for_user( int $user_id ): array {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . Config::table( 'user_cases' ) . ' WHERE user_id = %d ORDER BY created_at DESC',
				$user_id
			),
			ARRAY_A
		);

		return $rows ?: array();
	}
}
