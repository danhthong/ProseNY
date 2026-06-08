<?php
/**
 * Generate questionnaire keys from normalized PDF fields and case type.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Forms\Classification;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Questionnaire_Mapper
 */
final class Questionnaire_Mapper {

	/**
	 * Default keys by case type.
	 *
	 * @var array<string, string[]>
	 */
	private const CASE_TYPE_DEFAULTS = array(
		'Uncontested Divorce'       => array( 'plaintiff_name', 'defendant_name', 'county', 'grounds_for_divorce', 'marriage_date' ),
		'Contested Divorce'         => array( 'plaintiff_name', 'defendant_name', 'county', 'grounds_for_divorce', 'marriage_date' ),
		'Divorce With Children'     => array( 'plaintiff_name', 'defendant_name', 'county', 'child_name', 'child_birth_date' ),
		'Divorce Without Children'  => array( 'plaintiff_name', 'defendant_name', 'county', 'grounds_for_divorce' ),
		'Child Support'             => array( 'plaintiff_name', 'defendant_name', 'county', 'child_name' ),
		'Child Custody'             => array( 'plaintiff_name', 'defendant_name', 'county', 'child_name', 'child_birth_date' ),
		'Visitation'                => array( 'plaintiff_name', 'defendant_name', 'child_name' ),
		'Paternity'                 => array( 'plaintiff_name', 'defendant_name', 'child_name', 'child_birth_date' ),
		'Family Offense'            => array( 'plaintiff_name', 'defendant_name', 'county' ),
		'Orders of Protection'      => array( 'plaintiff_name', 'defendant_name', 'county' ),
	);

	/**
	 * Build questionnaire keys.
	 *
	 * @param array<int, array{pdf_field?: string, normalized_key?: string, type?: string}> $normalized_fields Normalized fields.
	 * @param string                                                                        $case_type         Detected case type.
	 * @return string[]
	 */
	public function map( array $normalized_fields, string $case_type ): array {
		$keys = array();

		foreach ( $normalized_fields as $field ) {
			$key = (string) ( $field['normalized_key'] ?? '' );

			if ( '' !== $key ) {
				$keys[] = $key;
			}
		}

		if ( isset( self::CASE_TYPE_DEFAULTS[ $case_type ] ) ) {
			$keys = array_merge( $keys, self::CASE_TYPE_DEFAULTS[ $case_type ] );
		}

		$keys = array_values( array_unique( $keys ) );

		/**
		 * Filter questionnaire keys for a form.
		 *
		 * @param string[] $keys      Questionnaire keys.
		 * @param string   $case_type Case type.
		 * @param array    $normalized_fields Normalized PDF fields.
		 */
		return apply_filters( 'prose_core_questionnaire_keys', $keys, $case_type, $normalized_fields );
	}
}
