<?php
/**
 * Node-package junction repository.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Forms\Database\Repositories;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Node_Package_Repository
 */
final class Node_Package_Repository extends Abstract_Repository {

	/**
	 * {@inheritDoc}
	 */
	protected function table_suffix(): string {
		return 'prose_node_packages';
	}

	/**
	 * Upsert node-package link.
	 *
	 * @param array<string, mixed> $data Link row.
	 * @return int Link ID.
	 */
	public function upsert( array $data ): int {
		global $wpdb;

		$node_id     = (int) ( $data['node_id'] ?? 0 );
		$package_key = sanitize_text_field( (string) ( $data['package_key'] ?? '' ) );
		$role        = sanitize_text_field( (string) ( $data['role'] ?? 'satisfies' ) );

		if ( $node_id <= 0 || '' === $package_key ) {
			return 0;
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$existing = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id FROM {$this->table()} WHERE node_id = %d AND package_key = %s AND role = %s LIMIT 1",
				$node_id,
				$package_key,
				$role
			)
		);

		$row = array(
			'node_id'     => $node_id,
			'package_key' => $package_key,
			'package_id'  => ! empty( $data['package_id'] ) ? (int) $data['package_id'] : null,
			'role'        => $role,
			'sequence'    => isset( $data['sequence'] ) ? (int) $data['sequence'] : 0,
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
	 * Packages for a node.
	 *
	 * @param int $node_id Node ID.
	 * @return object[]
	 */
	public function get_by_node( int $node_id ): array {
		return $this->get_all( 'node_id = %d ORDER BY sequence ASC', $node_id );
	}

	/**
	 * Nodes for a package key.
	 *
	 * @param string $package_key Package key.
	 * @return object[]
	 */
	public function get_by_package_key( string $package_key ): array {
		return $this->get_all( 'package_key = %s ORDER BY sequence ASC', sanitize_text_field( $package_key ) );
	}
}
