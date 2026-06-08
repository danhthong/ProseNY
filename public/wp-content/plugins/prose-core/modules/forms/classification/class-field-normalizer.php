<?php
/**
 * Normalize AcroForm field names to ProSe questionnaire keys.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Forms\Classification;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Field_Normalizer
 */
final class Field_Normalizer {

	/**
	 * Synonym map: lowercase PDF name => normalized key.
	 *
	 * @var array<string, string>
	 */
	private const SYNONYMS = array(
		'plaintiffname'       => 'plaintiff_name',
		'plaintiff_name'      => 'plaintiff_name',
		'defendantname'       => 'defendant_name',
		'defendant_name'      => 'defendant_name',
		'marriagedate'        => 'marriage_date',
		'marriage_date'       => 'marriage_date',
		'county'              => 'county',
		'groundsfordivorce'   => 'grounds_for_divorce',
		'grounds_for_divorce' => 'grounds_for_divorce',
		'childname'           => 'child_name',
		'child_name'          => 'child_name',
		'childbirthdate'      => 'child_birth_date',
		'child_birth_date'    => 'child_birth_date',
		'petitionername'      => 'plaintiff_name',
		'respondentname'      => 'defendant_name',
	);

	/**
	 * Normalize raw PDF fields.
	 *
	 * @param array<int, array<string, mixed>> $fields Raw fields.
	 * @return array<int, array{pdf_field: string, normalized_key: string, type: string}>
	 */
	public function normalize( array $fields ): array {
		$normalized = array();

		foreach ( $fields as $field ) {
			$name = (string) ( $field['name'] ?? '' );

			if ( '' === $name ) {
				continue;
			}

			$key = $this->normalize_key( $name );

			$normalized[] = array(
				'pdf_field'      => $name,
				'normalized_key' => $key,
				'type'           => (string) ( $field['type'] ?? 'text' ),
			);
		}

		return $normalized;
	}

	/**
	 * Convert a PDF field name to snake_case key.
	 *
	 * @param string $name PDF field name.
	 * @return string
	 */
	public function normalize_key( string $name ): string {
		$compact = strtolower( preg_replace( '/[^a-zA-Z0-9_]/', '', $name ) ?? '' );

		if ( isset( self::SYNONYMS[ $compact ] ) ) {
			return self::SYNONYMS[ $compact ];
		}

		// CamelCase to snake_case.
		$snake = strtolower( preg_replace( '/([a-z])([A-Z])/', '$1_$2', $name ) ?? $name );
		$snake = strtolower( preg_replace( '/[^a-z0-9_]+/', '_', $snake ) ?? $snake );
		$snake = trim( $snake, '_' );

		return $snake;
	}
}
