<?php
/**
 * Package-form junction repository.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Forms\Database\Repositories;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Package_Form_Repository
 */
final class Package_Form_Repository extends Abstract_Repository {

	/**
	 * {@inheritDoc}
	 */
	protected function table_suffix(): string {
		return 'prose_package_forms';
	}

	/**
	 * Upsert package-form link.
	 *
	 * @param array<string, mixed> $data Link row.
	 * @return int Link ID.
	 */
	public function upsert( array $data ): int {
		global $wpdb;

		$package_id  = (int) ( $data['package_id'] ?? 0 );
		$form_code   = sanitize_text_field( (string) ( $data['form_code'] ?? '' ) );
		$requirement = sanitize_text_field( (string) ( $data['requirement'] ?? 'required' ) );

		if ( $package_id <= 0 || '' === $form_code ) {
			return 0;
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$existing = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id FROM {$this->table()} WHERE package_id = %d AND form_code = %s AND requirement = %s LIMIT 1",
				$package_id,
				$form_code,
				$requirement
			)
		);

		$row = array(
			'package_id'    => $package_id,
			'form_id'       => ! empty( $data['form_id'] ) ? (int) $data['form_id'] : null,
			'form_code'     => $form_code,
			'requirement'   => $requirement,
			'condition_key' => sanitize_text_field( (string) ( $data['condition_key'] ?? '' ) ),
			'sequence'      => isset( $data['sequence'] ) ? (int) $data['sequence'] : 0,
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
	 * Forms for a package.
	 *
	 * @param int $package_id Package post ID.
	 * @return object[]
	 */
	public function get_by_package( int $package_id ): array {
		return $this->get_all( 'package_id = %d ORDER BY sequence ASC', $package_id );
	}

	/**
	 * Packages referencing a form code.
	 *
	 * @param string $form_code Form code.
	 * @return object[]
	 */
	public function get_by_form_code( string $form_code ): array {
		return $this->get_all( 'form_code = %s ORDER BY package_id ASC', sanitize_text_field( $form_code ) );
	}
}
