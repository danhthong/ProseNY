<?php
/**
 * Routing rules repository.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Forms\Database\Repositories;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Routing_Repository
 */
final class Routing_Repository extends Abstract_Repository {

	/**
	 * {@inheritDoc}
	 */
	protected function table_suffix(): string {
		return 'prose_routing_rules';
	}

	/**
	 * Upsert routing rule.
	 *
	 * @param array<string, mixed> $data Rule row.
	 * @return int Rule ID.
	 */
	public function upsert( array $data ): int {
		global $wpdb;

		$rule_key = sanitize_text_field( (string) ( $data['rule_key'] ?? '' ) );
		$scope    = sanitize_text_field( (string) ( $data['scope'] ?? '' ) );
		$scope_ref = sanitize_text_field( (string) ( $data['scope_ref'] ?? '' ) );
		$now      = $this->now();

		$existing = null;

		if ( '' !== $rule_key ) {
			$existing = $this->get_row_by( 'rule_key', $rule_key );
		}

		$row = array(
			'rule_key'          => $rule_key,
			'scope'             => $scope,
			'scope_ref'         => $scope_ref,
			'county'            => sanitize_text_field( (string) ( $data['county'] ?? '' ) ),
			'court_routing'     => sanitize_text_field( (string) ( $data['court_routing'] ?? '' ) ),
			'rule_type'         => sanitize_text_field( (string) ( $data['rule_type'] ?? '' ) ),
			'match_conditions'  => $this->encode_json( $data['match_conditions'] ?? array() ),
			'rule_data'         => $this->encode_json( $data['rule_data'] ?? array() ),
			'priority'          => isset( $data['priority'] ) ? (int) $data['priority'] : 0,
			'effective_from'    => ! empty( $data['effective_from'] ) ? sanitize_text_field( (string) $data['effective_from'] ) : null,
			'effective_to'      => ! empty( $data['effective_to'] ) ? sanitize_text_field( (string) $data['effective_to'] ) : null,
			'status'            => sanitize_text_field( (string) ( $data['status'] ?? 'active' ) ),
			'description'       => sanitize_textarea_field( (string) ( $data['description'] ?? '' ) ),
			'updated_at'        => $now,
		);

		if ( $existing ) {
			$wpdb->update(
				$this->table(),
				$row,
				array( 'rule_id' => (int) $existing->rule_id )
			);

			return (int) $existing->rule_id;
		}

		$row['created_at'] = $now;
		$wpdb->insert( $this->table(), $row );

		return (int) $wpdb->insert_id;
	}

	/**
	 * Resolve routing rules for scope.
	 *
	 * @param string $scope     Scope.
	 * @param string $scope_ref Scope reference.
	 * @param string $county    Optional county filter.
	 * @return object[]
	 */
	public function resolve( string $scope, string $scope_ref, string $county = '' ): array {
		global $wpdb;

		$table = $this->table();

		if ( '' !== $county ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$sql = $wpdb->prepare(
				"SELECT * FROM {$table} WHERE status = 'active' AND scope = %s AND scope_ref = %s AND (county = '' OR county = %s) ORDER BY priority DESC",
				$scope,
				$scope_ref,
				$county
			);
		} else {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$sql = $wpdb->prepare(
				"SELECT * FROM {$table} WHERE status = 'active' AND scope = %s AND scope_ref = %s ORDER BY priority DESC",
				$scope,
				$scope_ref
			);
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results( $sql );

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Rule as array.
	 *
	 * @param object $row DB row.
	 * @return array<string, mixed>
	 */
	public function to_array( object $row ): array {
		return array(
			'rule_id'          => (int) $row->rule_id,
			'rule_key'         => (string) $row->rule_key,
			'scope'            => (string) $row->scope,
			'scope_ref'        => (string) $row->scope_ref,
			'county'           => (string) $row->county,
			'court_routing'    => (string) $row->court_routing,
			'rule_type'        => (string) $row->rule_type,
			'match_conditions' => $this->decode_json( $row->match_conditions ?? '' ),
			'rule_data'        => $this->decode_json( $row->rule_data ?? '' ),
			'priority'         => (int) $row->priority,
			'description'      => (string) $row->description,
		);
	}
}
