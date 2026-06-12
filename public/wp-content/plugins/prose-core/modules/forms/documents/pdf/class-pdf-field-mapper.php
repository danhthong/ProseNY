<?php
/**
 * PDF field mapper — map document model fields to PDF field names and types.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Forms\Documents\Pdf;

use ProSe\Core\Forms\Documents\Generated_Document;
use ProSe\Core\Forms\Documents\Generated_Field;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Pdf_Field_Mapper
 *
 * Translates the Document Generation Engine's canonical field keys into the
 * PDF field names used on court templates (petitioner_name -> PETITIONER_NAME)
 * and classifies each field as text, checkbox, radio, date or multiline so the
 * renderer can format the value appropriately.
 */
final class Pdf_Field_Mapper {

	// Field input types.
	public const TYPE_TEXT      = 'text';
	public const TYPE_CHECKBOX  = 'checkbox';
	public const TYPE_RADIO     = 'radio';
	public const TYPE_DATE      = 'date';
	public const TYPE_MULTILINE = 'multiline';

	/**
	 * Explicit canonical key => PDF field name overrides.
	 *
	 * @return array<string, string>
	 */
	private function name_map(): array {
		return array(
			'petitioner_name'      => 'PETITIONER_NAME',
			'respondent_name'      => 'RESPONDENT_NAME',
			'petitioner_address'   => 'PETITIONER_ADDRESS',
			'respondent_address'   => 'RESPONDENT_ADDRESS',
			'county'               => 'COUNTY',
			'court'                => 'COURT',
			'marriage_date'        => 'MARRIAGE_DATE',
			'marriage_place'       => 'MARRIAGE_PLACE',
			'separation_date'      => 'SEPARATION_DATE',
			'grounds'              => 'GROUNDS',
			'children_count'       => 'CHILDREN_COUNT',
			'date_of_birth'        => 'DATE_OF_BIRTH',
			'support_amount'       => 'SUPPORT_AMOUNT',
			'incident_date'        => 'INCIDENT_DATE',
			'relief_requested'     => 'RELIEF_REQUESTED',
			'service_date'         => 'SERVICE_DATE',
			'answer_date'          => 'ANSWER_DATE',
			'hearing_date'         => 'HEARING_DATE',
			'index_number'         => 'INDEX_NUMBER',
			'fault_allegations'    => 'FAULT_ALLEGATIONS',
			'child_support_fields' => 'CHILD_SUPPORT_FIELDS',
			'protection_fields'    => 'PROTECTION_FIELDS',
		);
	}

	/**
	 * Canonical keys treated as date inputs.
	 *
	 * @return string[]
	 */
	private function date_keys(): array {
		return array( 'marriage_date', 'separation_date', 'date_of_birth', 'incident_date', 'service_date', 'answer_date', 'hearing_date' );
	}

	/**
	 * Canonical keys treated as checkbox inputs.
	 *
	 * @return string[]
	 */
	private function checkbox_keys(): array {
		return array( 'has_children', 'domestic_violence' );
	}

	/**
	 * Canonical keys treated as radio groups.
	 *
	 * @return string[]
	 */
	private function radio_keys(): array {
		return array( 'grounds', 'court' );
	}

	/**
	 * Canonical keys treated as multiline text.
	 *
	 * @return string[]
	 */
	private function multiline_keys(): array {
		return array( 'relief_requested', 'fault_allegations', 'protection_fields', 'child_support_fields', 'petitioner_address', 'respondent_address' );
	}

	/**
	 * PDF field name for a canonical key.
	 *
	 * @param string $key Canonical key.
	 * @return string
	 */
	public function pdf_field_name( string $key ): string {
		$map = $this->name_map();

		return $map[ $key ] ?? strtoupper( $key );
	}

	/**
	 * PDF input type for a canonical key.
	 *
	 * @param string $key Canonical key.
	 * @return string
	 */
	public function field_type( string $key ): string {
		if ( in_array( $key, $this->date_keys(), true ) ) {
			return self::TYPE_DATE;
		}

		if ( in_array( $key, $this->checkbox_keys(), true ) ) {
			return self::TYPE_CHECKBOX;
		}

		if ( in_array( $key, $this->radio_keys(), true ) ) {
			return self::TYPE_RADIO;
		}

		if ( in_array( $key, $this->multiline_keys(), true ) ) {
			return self::TYPE_MULTILINE;
		}

		return self::TYPE_TEXT;
	}

	/**
	 * Map a single generated field to its PDF representation.
	 *
	 * @param Generated_Field $field Field.
	 * @return array<string, mixed>
	 */
	public function map_field( Generated_Field $field ): array {
		$key  = $field->key();
		$type = $this->field_type( $key );

		return array(
			'key'         => $key,
			'pdf_field'   => $this->pdf_field_name( $key ),
			'label'       => $field->label(),
			'type'        => $type,
			'value'       => $field->value(),
			'display'     => $this->format_value( $field->value(), $type, $field->is_resolved() ),
			'resolved'    => $field->is_resolved(),
			'required'    => $field->is_required(),
			'visible'     => $field->is_visible(),
			'field_class' => $field->field_class(),
		);
	}

	/**
	 * Map every (visible) field of a document to PDF entries.
	 *
	 * @param Generated_Document $document     Document.
	 * @param bool               $visible_only Only include visible fields.
	 * @return array<int, array<string, mixed>>
	 */
	public function map_document( Generated_Document $document, bool $visible_only = true ): array {
		$entries = array();

		foreach ( $document->fields() as $field ) {
			if ( $visible_only && ! $field->is_visible() ) {
				continue;
			}

			$entries[] = $this->map_field( $field );
		}

		return $entries;
	}

	/**
	 * Format a value for display in the PDF according to its input type.
	 *
	 * @param mixed  $value    Raw value.
	 * @param string $type     Field type.
	 * @param bool   $resolved Whether the field resolved.
	 * @return string
	 */
	public function format_value( $value, string $type, bool $resolved = true ): string {
		if ( self::TYPE_CHECKBOX === $type ) {
			return $this->is_truthy( $value ) ? '[X]' : '[ ]';
		}

		if ( ! $resolved || null === $value || '' === $value ) {
			return '';
		}

		if ( self::TYPE_DATE === $type ) {
			return $this->format_date( $value );
		}

		if ( is_bool( $value ) ) {
			return $value ? 'Yes' : 'No';
		}

		if ( is_scalar( $value ) ) {
			return (string) $value;
		}

		return (string) wp_json_encode( $value );
	}

	/**
	 * Normalize a date value to Y-m-d (dropping any time component).
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	private function format_date( $value ): string {
		$value = (string) $value;
		$ts    = strtotime( $value );

		if ( false === $ts ) {
			return $value;
		}

		return gmdate( 'Y-m-d', $ts );
	}

	/**
	 * Whether a value is truthy for a checkbox.
	 *
	 * @param mixed $value Value.
	 * @return bool
	 */
	private function is_truthy( $value ): bool {
		if ( is_bool( $value ) ) {
			return $value;
		}

		if ( is_numeric( $value ) ) {
			return (float) $value > 0;
		}

		$normalized = strtolower( trim( (string) $value ) );

		return in_array( $normalized, array( 'yes', 'true', 'y', '1', 'on' ), true );
	}
}
