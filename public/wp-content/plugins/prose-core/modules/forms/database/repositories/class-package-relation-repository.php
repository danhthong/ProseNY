<?php
/**
 * Package relation repository (transitions and dependencies).
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Forms\Database\Repositories;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Package_Relation_Repository
 */
final class Package_Relation_Repository extends Abstract_Repository {

	/**
	 * {@inheritDoc}
	 */
	protected function table_suffix(): string {
		return 'prose_package_relations';
	}

	/**
	 * Upsert package relation.
	 *
	 * @param array<string, mixed> $data Relation row.
	 * @return int Relation ID.
	 */
	public function upsert( array $data ): int {
		global $wpdb;

		$from_key      = sanitize_text_field( (string) ( $data['from_package_key'] ?? '' ) );
		$to_key        = sanitize_text_field( (string) ( $data['to_package_key'] ?? '' ) );
		$relation_type = sanitize_text_field( (string) ( $data['relation_type'] ?? 'next' ) );
		$condition_key = sanitize_text_field( (string) ( $data['condition_key'] ?? '' ) );

		if ( '' === $from_key || '' === $to_key ) {
			return 0;
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$existing = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id FROM {$this->table()} WHERE from_package_key = %s AND to_package_key = %s AND relation_type = %s AND condition_key = %s LIMIT 1",
				$from_key,
				$to_key,
				$relation_type,
				$condition_key
			)
		);

		$row = array(
			'from_package_key' => $from_key,
			'to_package_key'   => $to_key,
			'from_package_id'  => ! empty( $data['from_package_id'] ) ? (int) $data['from_package_id'] : null,
			'to_package_id'    => ! empty( $data['to_package_id'] ) ? (int) $data['to_package_id'] : null,
			'relation_type'    => $relation_type,
			'condition_key'    => $condition_key,
			'condition_data'   => $this->encode_json( $data['condition_data'] ?? array() ),
			'sequence'         => isset( $data['sequence'] ) ? (int) $data['sequence'] : 0,
		);

		if ( $existing ) {
			$wpdb->update(
				$this->table(),
				$row,
				array( 'id' => (int) $existing->id )
			);

			return (int) $existing->id;
		}

		$wpdb->insert( $this->table(), $row );

		return (int) $wpdb->insert_id;
	}

	/**
	 * Outgoing relations from a package key.
	 *
	 * @param string $from_package_key From package key.
	 * @param string $relation_type    Optional relation type filter.
	 * @return object[]
	 */
	public function get_outgoing( string $from_package_key, string $relation_type = '' ): array {
		if ( '' !== $relation_type ) {
			return $this->get_all(
				'from_package_key = %s AND relation_type = %s ORDER BY sequence ASC',
				sanitize_text_field( $from_package_key ),
				sanitize_text_field( $relation_type )
			);
		}

		return $this->get_all(
			'from_package_key = %s ORDER BY sequence ASC',
			sanitize_text_field( $from_package_key )
		);
	}

	/**
	 * Incoming prerequisite relations.
	 *
	 * @param string $to_package_key To package key.
	 * @return object[]
	 */
	public function get_prerequisites( string $to_package_key ): array {
		return $this->get_outgoing_by_target( $to_package_key, 'prerequisite' );
	}

	/**
	 * Relations targeting a package.
	 *
	 * @param string $to_package_key To package key.
	 * @param string $relation_type  Relation type.
	 * @return object[]
	 */
	public function get_outgoing_by_target( string $to_package_key, string $relation_type ): array {
		return $this->get_all(
			'to_package_key = %s AND relation_type = %s ORDER BY sequence ASC',
			sanitize_text_field( $to_package_key ),
			sanitize_text_field( $relation_type )
		);
	}
}
