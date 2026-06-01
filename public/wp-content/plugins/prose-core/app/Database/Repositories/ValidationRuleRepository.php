<?php
/**
 * Validation rules repository.
 *
 * @package ProseCore
 */

namespace Prose\Core\Database\Repositories;

use Prose\Core\Support\Config;

final class ValidationRuleRepository {

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public function enabled( string $scope = 'global' ): array {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . Config::table( 'validation_rules' ) . ' WHERE enabled = 1 AND (scope = %s OR scope = %s)',
				$scope,
				'global'
			),
			ARRAY_A
		);

		foreach ( $rows as &$row ) {
			$row['expr'] = json_decode( $row['expr'] ?? '{}', true ) ?: array();
		}

		return $rows ?: array();
	}

	/**
	 * @param array<string, mixed> $data
	 */
	public function create( array $data ): int {
		global $wpdb;

		$wpdb->insert(
			Config::table( 'validation_rules' ),
			array(
				'scope'    => $data['scope'] ?? 'global',
				'slug'     => $data['slug'],
				'expr'     => wp_json_encode( $data['expr'] ?? array() ),
				'severity' => $data['severity'] ?? 'error',
				'message'  => $data['message'],
				'enabled'  => $data['enabled'] ?? 1,
			),
			array( '%s', '%s', '%s', '%s', '%s', '%d' )
		);

		return (int) $wpdb->insert_id;
	}
}
