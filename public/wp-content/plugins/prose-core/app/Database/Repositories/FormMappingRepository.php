<?php
/**
 * Form field mappings repository.
 *
 * @package ProseCore
 */

namespace Prose\Core\Database\Repositories;

use Prose\Core\Support\Config;

final class FormMappingRepository {

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public function for_form( int $form_id ): array {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . Config::table( 'form_field_mappings' ) . ' WHERE form_id = %d',
				$form_id
			),
			ARRAY_A
		);

		foreach ( $rows as &$row ) {
			$row['transform'] = json_decode( $row['transform'] ?? 'null', true );
		}

		return $rows ?: array();
	}

	/**
	 * @param array<string, mixed> $data
	 */
	public function upsert( array $data ): int {
		global $wpdb;

		$existing = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT id FROM ' . Config::table( 'form_field_mappings' ) . ' WHERE form_id = %d AND field_name = %s',
				$data['form_id'],
				$data['field_name']
			)
		);

		$row = array(
			'form_id'      => $data['form_id'],
			'field_name'   => $data['field_name'],
			'source_path'  => $data['source_path'],
			'transform'    => isset( $data['transform'] ) ? wp_json_encode( $data['transform'] ) : null,
		);

		if ( $existing ) {
			$wpdb->update(
				Config::table( 'form_field_mappings' ),
				$row,
				array( 'id' => (int) $existing )
			);
			return (int) $existing;
		}

		$wpdb->insert( Config::table( 'form_field_mappings' ), $row );
		return (int) $wpdb->insert_id;
	}

	public function delete( int $id ): bool {
		global $wpdb;

		return (bool) $wpdb->delete(
			Config::table( 'form_field_mappings' ),
			array( 'id' => $id ),
			array( '%d' )
		);
	}

	public function delete_for_form( int $form_id ): int {
		global $wpdb;

		return (int) $wpdb->query(
			$wpdb->prepare(
				'DELETE FROM ' . Config::table( 'form_field_mappings' ) . ' WHERE form_id = %d',
				$form_id
			)
		);
	}
}
