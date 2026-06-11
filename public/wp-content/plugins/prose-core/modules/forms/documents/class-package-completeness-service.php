<?php
/**
 * Package completeness service.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Forms\Documents;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Package_Completeness_Service
 *
 * Computes how complete a package is from its assembled required-form
 * documents: a field-weighted completion percentage, the field keys still
 * missing, the required forms not yet ready, and whether the whole package
 * is ready to generate.
 */
final class Package_Completeness_Service {

	/**
	 * Compute the completeness snapshot for a package.
	 *
	 * @param string                            $package_key    Package key.
	 * @param string[]                          $required_forms Required form codes.
	 * @param array<string, Generated_Document> $documents      Assembled documents keyed by code.
	 * @return Package_Completeness
	 */
	public function compute( string $package_key, array $required_forms, array $documents ): Package_Completeness {
		$total_slots    = 0;
		$resolved_slots = 0;
		$missing_fields = array();
		$missing_forms  = array();

		foreach ( $required_forms as $form_code ) {
			$document = $documents[ $form_code ] ?? null;

			if ( null === $document ) {
				$missing_forms[] = $form_code;

				foreach ( Field_Catalog::required_fields( $form_code ) as $key ) {
					++$total_slots;
					$missing_fields[] = $key;
				}

				continue;
			}

			foreach ( $document->fields() as $field ) {
				// OPTIONAL, COURT_ASSIGNED and SYSTEM_GENERATED fields never
				// count toward completeness. CONDITIONAL fields count only when
				// their condition holds (reflected by the required flag).
				if ( Field_Catalog::is_excluded_class( $field->field_class() ) ) {
					continue;
				}

				if ( ! $field->is_required() ) {
					continue;
				}

				++$total_slots;

				if ( $field->is_resolved() ) {
					++$resolved_slots;
				} else {
					$missing_fields[] = $field->key();
				}
			}

			if ( ! $this->is_form_complete( $document ) ) {
				$missing_forms[] = $form_code;

				$validation = $document->validation();

				if ( null !== $validation ) {
					$missing_fields = array_merge( $missing_fields, $validation->missing_fields() );
				}
			}
		}

		$percentage = $total_slots > 0
			? (int) round( ( $resolved_slots / $total_slots ) * 100 )
			: 100;

		$ready = empty( $missing_forms );

		return new Package_Completeness(
			$package_key,
			$percentage,
			$missing_fields,
			$missing_forms,
			$ready
		);
	}

	/**
	 * Whether a required form is complete (ready, valid, or generated).
	 *
	 * @param Generated_Document $document Document.
	 * @return bool
	 */
	private function is_form_complete( Generated_Document $document ): bool {
		return $document->is_ready() || $document->is_generated() || $document->is_valid();
	}
}
