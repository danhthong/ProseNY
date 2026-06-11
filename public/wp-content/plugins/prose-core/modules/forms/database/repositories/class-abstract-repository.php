<?php
/**
 * Base repository for CourtFlow custom tables.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Forms\Database\Repositories;

use ProSe\Core\Forms\Database\Database_Installer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Abstract_Repository
 */
abstract class Abstract_Repository {

	/**
	 * Table suffix without prefix.
	 *
	 * @return string
	 */
	abstract protected function table_suffix(): string;

	/**
	 * Fully qualified table name.
	 *
	 * @return string
	 */
	protected function table(): string {
		return Database_Installer::table( $this->table_suffix() );
	}

	/**
	 * Current MySQL timestamp.
	 *
	 * @return string
	 */
	protected function now(): string {
		return current_time( 'mysql' );
	}

	/**
	 * Encode value as JSON for storage.
	 *
	 * @param mixed $value Value.
	 * @return string|null
	 */
	protected function encode_json( $value ): ?string {
		if ( null === $value ) {
			return null;
		}

		if ( is_string( $value ) && '' === $value ) {
			return null;
		}

		return wp_json_encode( $value ) ?: null;
	}

	/**
	 * Decode JSON column.
	 *
	 * @param mixed $raw Raw DB value.
	 * @return array<int|string, mixed>
	 */
	protected function decode_json( $raw ): array {
		if ( is_array( $raw ) ) {
			return $raw;
		}

		if ( ! is_string( $raw ) || '' === $raw ) {
			return array();
		}

		$decoded = json_decode( $raw, true );

		return is_array( $decoded ) ? $decoded : array();
	}

	/**
	 * Fetch single row by primary ID column.
	 *
	 * @param int $id Row ID.
	 * @return object|null
	 */
	public function get_by_id( int $id ): ?object {
		global $wpdb;

		if ( $id <= 0 ) {
			return null;
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$this->table()} WHERE {$this->primary_key_column()} = %d LIMIT 1",
				$id
			)
		);

		return $row instanceof \stdClass ? $row : null;
	}

	/**
	 * Primary key column name for this table.
	 *
	 * @return string
	 */
	protected function primary_key_column(): string {
		return 'id';
	}

	/**
	 * Fetch single row by column.
	 *
	 * @param string $column Column name.
	 * @param mixed  $value Column value.
	 * @return object|null
	 */
	protected function get_row_by( string $column, $value ): ?object {
		global $wpdb;

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$this->table()} WHERE {$column} = %s LIMIT 1",
				$value
			)
		);

		return $row instanceof \stdClass ? $row : null;
	}

	/**
	 * Fetch all rows.
	 *
	 * @param string $where Optional WHERE clause with placeholders.
	 * @param mixed  ...$args Prepared statement args.
	 * @return object[]
	 */
	protected function get_all( string $where = '', ...$args ): array {
		global $wpdb;

		$sql = "SELECT * FROM {$this->table()}";

		if ( '' !== $where ) {
			$sql .= ' WHERE ' . $where;
		}

		if ( ! empty( $args ) ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$sql = $wpdb->prepare( $sql, ...$args );
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results( $sql );

		return is_array( $rows ) ? $rows : array();
	}
}
