<?php
/**
 * Assembly manifest builder.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Assembly;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Manifest_Builder
 */
final class Manifest_Builder {

	/**
	 * Build an assembly manifest for a package.
	 *
	 * @param string               $package_id   Package enum.
	 * @param array<int, array<string, mixed>> $package_forms Package form rows {form_id, required}.
	 * @param array<string, array<string, mixed>|null> $loaded_forms Loaded form definitions keyed by form code.
	 * @return array<string, mixed>
	 */
	public function build( string $package_id, array $package_forms, array $loaded_forms ): array {
		$entries = array();

		foreach ( $package_forms as $row ) {
			$form_id  = (string) ( $row['form_id'] ?? '' );
			$required = (bool) ( $row['required'] ?? true );

			if ( '' === $form_id ) {
				continue;
			}

			$loaded = $loaded_forms[ $form_id ] ?? null;

			$entries[] = array(
				'form_id'  => $form_id,
				'required' => $required,
				'status'   => is_array( $loaded ) ? 'ready' : 'not_found',
			);
		}

		return array(
			'package_id' => $package_id,
			'form_count'   => count( $entries ),
			'forms'        => $entries,
		);
	}
}
