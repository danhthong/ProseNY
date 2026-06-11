<?php
/**
 * Field catalog — deterministic, DB-free field definitions and per-form
 * field requirements for the Document Generation Engine.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Forms\Documents;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Field_Catalog
 *
 * Encodes the canonical fields a court form may contain, where each field's
 * value is sourced from, its catalog default, the alias map used to
 * normalize legacy / PDF field names, and the per-form-code requirement
 * sets (required, optional, conditional). Expressed as pure PHP so the
 * engine resolves and validates documents deterministically without a
 * database, exactly like Case_Catalog and Deadline_Catalog.
 */
final class Field_Catalog {

	// Resolution sources, in resolution priority order.
	public const SOURCE_GENERATED = 'generated_forms';
	public const SOURCE_PROFILE   = 'case_profile';
	public const SOURCE_ANSWERS   = 'intake_answers';
	public const SOURCE_WORKFLOW  = 'workflow_data';
	public const SOURCE_COURT     = 'court_metadata';
	public const SOURCE_COUNTY    = 'county_metadata';
	public const SOURCE_DEFAULT   = 'catalog_default';

	// Field classification. Drives validation and completeness:
	//   REQUIRED         — must be populated.
	//   OPTIONAL         — ignored for completeness.
	//   CONDITIONAL      — required only when its condition evaluates true.
	//   COURT_ASSIGNED   — populated by the court (e.g. index number); excluded
	//                      from completeness.
	//   SYSTEM_GENERATED — produced by the system / downstream renderer;
	//                      excluded from completeness.
	public const CLASS_REQUIRED         = 'REQUIRED';
	public const CLASS_OPTIONAL         = 'OPTIONAL';
	public const CLASS_CONDITIONAL      = 'CONDITIONAL';
	public const CLASS_COURT_ASSIGNED   = 'COURT_ASSIGNED';
	public const CLASS_SYSTEM_GENERATED = 'SYSTEM_GENERATED';

	/**
	 * Field classes that are excluded from completeness scoring.
	 *
	 * @return string[]
	 */
	public static function excluded_classes(): array {
		return array(
			self::CLASS_OPTIONAL,
			self::CLASS_COURT_ASSIGNED,
			self::CLASS_SYSTEM_GENERATED,
		);
	}

	/**
	 * Whether a field class is excluded from completeness scoring.
	 *
	 * @param string $field_class Field class.
	 * @return bool
	 */
	public static function is_excluded_class( string $field_class ): bool {
		return in_array( $field_class, self::excluded_classes(), true );
	}

	/**
	 * Canonical field definitions.
	 *
	 * Each entry: key => array{ label: string, source: string, default: mixed }.
	 * `source` is the primary/native source; the resolver still walks the
	 * full source chain so a field can be satisfied from any input.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function fields(): array {
		return array(
			'petitioner_name'      => array(
				'label'   => 'Petitioner Name',
				'source'  => self::SOURCE_PROFILE,
				'default' => null,
			),
			'respondent_name'      => array(
				'label'   => 'Respondent Name',
				'source'  => self::SOURCE_PROFILE,
				'default' => null,
			),
			'petitioner_address'   => array(
				'label'   => 'Petitioner Address',
				'source'  => self::SOURCE_PROFILE,
				'default' => null,
			),
			'respondent_address'   => array(
				'label'   => 'Respondent Address',
				'source'  => self::SOURCE_PROFILE,
				'default' => null,
			),
			'marriage_date'        => array(
				'label'   => 'Date of Marriage',
				'source'  => self::SOURCE_ANSWERS,
				'default' => null,
			),
			'marriage_place'       => array(
				'label'   => 'Place of Marriage',
				'source'  => self::SOURCE_ANSWERS,
				'default' => null,
			),
			'separation_date'      => array(
				'label'   => 'Date of Separation',
				'source'  => self::SOURCE_ANSWERS,
				'default' => null,
			),
			'grounds'              => array(
				'label'   => 'Grounds for Divorce',
				'source'  => self::SOURCE_ANSWERS,
				'default' => 'DRL 170(7)',
			),
			'children_count'       => array(
				'label'   => 'Number of Children',
				'source'  => self::SOURCE_ANSWERS,
				'default' => null,
			),
			'date_of_birth'        => array(
				'label'   => 'Date of Birth',
				'source'  => self::SOURCE_ANSWERS,
				'default' => null,
			),
			'support_amount'       => array(
				'label'   => 'Support Amount',
				'source'  => self::SOURCE_ANSWERS,
				'default' => null,
			),
			'incident_date'        => array(
				'label'   => 'Incident Date',
				'source'  => self::SOURCE_ANSWERS,
				'default' => null,
			),
			'relief_requested'     => array(
				'label'   => 'Relief Requested',
				'source'  => self::SOURCE_ANSWERS,
				'default' => null,
			),
			'child_support_fields' => array(
				'label'   => 'Child Support Details',
				'source'  => self::SOURCE_ANSWERS,
				'default' => null,
			),
			'fault_allegations'    => array(
				'label'   => 'Fault Allegations',
				'source'  => self::SOURCE_ANSWERS,
				'default' => null,
			),
			'protection_fields'    => array(
				'label'   => 'Protection / Safety Details',
				'source'  => self::SOURCE_ANSWERS,
				'default' => null,
			),
			'service_date'         => array(
				'label'   => 'Date of Service',
				'source'  => self::SOURCE_WORKFLOW,
				'default' => null,
			),
			'answer_date'          => array(
				'label'   => 'Date Answer Filed',
				'source'  => self::SOURCE_WORKFLOW,
				'default' => null,
			),
			'hearing_date'         => array(
				'label'   => 'Hearing Date',
				'source'  => self::SOURCE_WORKFLOW,
				'default' => null,
			),
			'index_number'         => array(
				'label'   => 'Index Number',
				'source'  => self::SOURCE_COURT,
				'default' => null,
			),
			'court'                => array(
				'label'   => 'Court',
				'source'  => self::SOURCE_COURT,
				'default' => null,
			),
			'county'               => array(
				'label'   => 'County',
				'source'  => self::SOURCE_COUNTY,
				'default' => null,
			),
		);
	}

	/**
	 * Whether a canonical field key exists.
	 *
	 * @param string $key Field key.
	 * @return bool
	 */
	public static function has_field( string $key ): bool {
		return array_key_exists( $key, self::fields() );
	}

	/**
	 * Definition for a single field.
	 *
	 * @param string $key Field key.
	 * @return array<string, mixed>
	 */
	public static function field( string $key ): array {
		$fields = self::fields();

		return $fields[ $key ] ?? array(
			'label'   => $key,
			'source'  => self::SOURCE_ANSWERS,
			'default' => null,
		);
	}

	/**
	 * Human label for a field key.
	 *
	 * @param string $key Field key.
	 * @return string
	 */
	public static function label( string $key ): string {
		return (string) ( self::field( $key )['label'] ?? $key );
	}

	/**
	 * Native source for a field key.
	 *
	 * @param string $key Field key.
	 * @return string
	 */
	public static function source( string $key ): string {
		return (string) ( self::field( $key )['source'] ?? self::SOURCE_ANSWERS );
	}

	/**
	 * Catalog default for a field key.
	 *
	 * @param string $key Field key.
	 * @return mixed
	 */
	public static function default_for( string $key ) {
		return self::field( $key )['default'] ?? null;
	}

	/**
	 * Alias map: legacy / PDF field name (lower-case) => canonical key.
	 *
	 * @return array<string, string>
	 */
	public static function aliases(): array {
		return array(
			'plaintiff'          => 'petitioner_name',
			'plaintiff_name'     => 'petitioner_name',
			'petitioner'         => 'petitioner_name',
			'defendant'          => 'respondent_name',
			'defendant_name'     => 'respondent_name',
			'respondent'         => 'respondent_name',
			'plaintiff_address'  => 'petitioner_address',
			'defendant_address'  => 'respondent_address',
			'date_of_marriage'   => 'marriage_date',
			'marriage'           => 'marriage_date',
			'place_of_marriage'  => 'marriage_place',
			'date_of_separation' => 'separation_date',
			'num_children'       => 'children_count',
			'number_of_children' => 'children_count',
			'children'           => 'children_count',
			'ground'             => 'grounds',
			'court_county'       => 'county',
			'filing_county'      => 'county',
			'court_routing'      => 'court',
			'court_type'         => 'court',
			'date_of_service'    => 'service_date',
			'served_on'          => 'service_date',
			'dob'                => 'date_of_birth',
			'index_no'           => 'index_number',
			'docket_number'      => 'index_number',
		);
	}

	/**
	 * Resolve a (possibly aliased) field name to its canonical key.
	 *
	 * @param string $name Raw field name.
	 * @return string Canonical key (or the normalized name when no alias).
	 */
	public static function canonical( string $name ): string {
		$norm = strtolower( trim( $name ) );

		if ( self::has_field( $norm ) ) {
			return $norm;
		}

		$aliases = self::aliases();

		return $aliases[ $norm ] ?? $norm;
	}

	/**
	 * Per-form-code field requirement definitions.
	 *
	 * Each entry: form_code => array{
	 *     title: string,
	 *     required: string[],
	 *     optional: string[],
	 *     conditional: array<int, array{field: string, condition: array<string, mixed>}>,
	 *     workflow_requires: string[],  // lifecycle events that must be recorded
	 * }
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function form_requirements(): array {
		return array(
			'UD-1' => self::form(
				'Summons With Notice',
				array( 'petitioner_name', 'respondent_name', 'county', 'court' )
			),
			'UD-2' => self::form(
				'Verified Complaint',
				array( 'petitioner_name', 'respondent_name', 'marriage_date', 'grounds' ),
				array( 'marriage_place', 'separation_date' ),
				array(
					// Fault grounds (DRL 170(1)) require fault allegations.
					array(
						'field'     => 'fault_allegations',
						'condition' => array(
							'all' => array(
								array(
									'type'  => 'answer',
									'key'   => 'grounds',
									'op'    => 'eq',
									'value' => 'DRL_170_1',
								),
							),
						),
					),
				)
			),
			'UD-3' => self::form(
				'Affidavit of Plaintiff',
				array( 'petitioner_name', 'respondent_name' ),
				array( 'marriage_date' )
			),
			'UD-4' => self::form(
				'Affidavit of Service',
				array( 'respondent_name', 'service_date' ),
				array(),
				array(),
				array( 'SERVICE_COMPLETED' )
			),
			'UD-5' => self::form(
				'Answer / Affidavit of Defendant',
				array( 'petitioner_name', 'respondent_name' )
			),
			'UD-6' => self::form(
				'Affidavit of Regularity',
				array( 'petitioner_name', 'respondent_name' ),
				array(),
				array(),
				array(),
				array( 'index_number' )
			),
			'UD-7' => self::form(
				'Findings of Fact / Judgment of Divorce',
				array( 'petitioner_name', 'respondent_name', 'marriage_date', 'county', 'court' )
			),
			'UD-8' => self::form(
				'Child Support Worksheet',
				array( 'petitioner_name', 'respondent_name' ),
				array( 'support_amount' ),
				array(
					array(
						'field'     => 'children_count',
						'condition' => array(
							'all' => array(
								array(
									'type'  => 'answer',
									'key'   => 'has_children',
									'op'    => 'eq',
									'value' => true,
								),
							),
						),
					),
					// More than zero children requires the child-support detail set.
					array(
						'field'     => 'child_support_fields',
						'condition' => array(
							'all' => array(
								array(
									'type'  => 'answer',
									'key'   => 'children_count',
									'op'    => 'gt',
									'value' => 0,
								),
							),
						),
					),
				)
			),
			'FC-1' => self::form(
				'General Petition',
				array( 'petitioner_name', 'respondent_name', 'county', 'court' )
			),
			'FC-2' => self::form(
				'Child Support Petition',
				array( 'petitioner_name', 'respondent_name', 'children_count', 'support_amount' )
			),
			'FC-3' => self::form(
				'Custody / Visitation Petition',
				array( 'petitioner_name', 'respondent_name', 'children_count', 'relief_requested' )
			),
			'FC-7' => self::form(
				'Family Offense Petition',
				array( 'petitioner_name', 'respondent_name', 'incident_date', 'relief_requested' ),
				array(),
				array(
					// A domestic-violence allegation requires protection details.
					array(
						'field'     => 'protection_fields',
						'condition' => array(
							'all' => array(
								array(
									'type'  => 'answer',
									'key'   => 'domestic_violence',
									'op'    => 'eq',
									'value' => 'YES',
								),
							),
						),
					),
				)
			),
		);
	}

	/**
	 * Requirement definition for a single form code.
	 *
	 * @param string $form_code Form code.
	 * @return array<string, mixed>
	 */
	public static function requirements_for( string $form_code ): array {
		$all = self::form_requirements();

		return $all[ $form_code ] ?? self::form( $form_code, array() );
	}

	/**
	 * Required field keys for a form code.
	 *
	 * @param string $form_code Form code.
	 * @return string[]
	 */
	public static function required_fields( string $form_code ): array {
		return (array) ( self::requirements_for( $form_code )['required'] ?? array() );
	}

	/**
	 * Optional field keys for a form code.
	 *
	 * @param string $form_code Form code.
	 * @return string[]
	 */
	public static function optional_fields( string $form_code ): array {
		return (array) ( self::requirements_for( $form_code )['optional'] ?? array() );
	}

	/**
	 * Conditional field definitions for a form code.
	 *
	 * @param string $form_code Form code.
	 * @return array<int, array{field: string, condition: array<string, mixed>}>
	 */
	public static function conditional_fields( string $form_code ): array {
		return (array) ( self::requirements_for( $form_code )['conditional'] ?? array() );
	}

	/**
	 * Court-assigned field keys for a form code (excluded from completeness).
	 *
	 * @param string $form_code Form code.
	 * @return string[]
	 */
	public static function court_assigned_fields( string $form_code ): array {
		return (array) ( self::requirements_for( $form_code )['court_assigned'] ?? array() );
	}

	/**
	 * System-generated field keys for a form code (excluded from completeness).
	 *
	 * @param string $form_code Form code.
	 * @return string[]
	 */
	public static function system_generated_fields( string $form_code ): array {
		return (array) ( self::requirements_for( $form_code )['system_generated'] ?? array() );
	}

	/**
	 * Classification map: field key => field class for every field a form
	 * references.
	 *
	 * @param string $form_code Form code.
	 * @return array<string, string>
	 */
	public static function classes_for( string $form_code ): array {
		$classes = array();

		foreach ( self::optional_fields( $form_code ) as $key ) {
			$classes[ $key ] = self::CLASS_OPTIONAL;
		}

		foreach ( self::court_assigned_fields( $form_code ) as $key ) {
			$classes[ $key ] = self::CLASS_COURT_ASSIGNED;
		}

		foreach ( self::system_generated_fields( $form_code ) as $key ) {
			$classes[ $key ] = self::CLASS_SYSTEM_GENERATED;
		}

		foreach ( self::conditional_fields( $form_code ) as $conditional ) {
			$key = (string) ( $conditional['field'] ?? '' );

			if ( '' !== $key ) {
				$classes[ $key ] = self::CLASS_CONDITIONAL;
			}
		}

		// REQUIRED takes precedence over any other classification.
		foreach ( self::required_fields( $form_code ) as $key ) {
			$classes[ $key ] = self::CLASS_REQUIRED;
		}

		return $classes;
	}

	/**
	 * Classification of a single field on a form.
	 *
	 * @param string $form_code Form code.
	 * @param string $key       Field key.
	 * @return string
	 */
	public static function field_class( string $form_code, string $key ): string {
		$classes = self::classes_for( $form_code );

		return $classes[ $key ] ?? self::CLASS_OPTIONAL;
	}

	/**
	 * Lifecycle events a form requires before it can be completed.
	 *
	 * @param string $form_code Form code.
	 * @return string[]
	 */
	public static function workflow_requirements( string $form_code ): array {
		return (array) ( self::requirements_for( $form_code )['workflow_requires'] ?? array() );
	}

	/**
	 * Human title for a form code.
	 *
	 * @param string $form_code Form code.
	 * @return string
	 */
	public static function form_title( string $form_code ): string {
		return (string) ( self::requirements_for( $form_code )['title'] ?? $form_code );
	}

	/**
	 * The complete, distinct set of field keys a form references.
	 *
	 * @param string $form_code Form code.
	 * @return string[]
	 */
	public static function all_fields_for( string $form_code ): array {
		$keys = array_merge(
			self::required_fields( $form_code ),
			self::optional_fields( $form_code ),
			self::court_assigned_fields( $form_code ),
			self::system_generated_fields( $form_code )
		);

		foreach ( self::conditional_fields( $form_code ) as $conditional ) {
			$keys[] = (string) ( $conditional['field'] ?? '' );
		}

		return array_values( array_unique( array_filter( $keys ) ) );
	}

	/**
	 * Build a form requirement definition.
	 *
	 * @param string                                                        $title             Form title.
	 * @param string[]                                                      $required          Required field keys.
	 * @param string[]                                                      $optional          Optional field keys.
	 * @param array<int, array{field: string, condition: array<string,mixed>}> $conditional   Conditional fields.
	 * @param string[]                                                      $workflow_requires Required lifecycle events.
	 * @param string[]                                                      $court_assigned    Court-assigned field keys.
	 * @param string[]                                                      $system_generated  System-generated field keys.
	 * @return array<string, mixed>
	 */
	private static function form(
		string $title,
		array $required,
		array $optional = array(),
		array $conditional = array(),
		array $workflow_requires = array(),
		array $court_assigned = array(),
		array $system_generated = array()
	): array {
		return array(
			'title'             => $title,
			'required'          => array_values( array_map( 'strval', $required ) ),
			'optional'          => array_values( array_map( 'strval', $optional ) ),
			'conditional'       => $conditional,
			'workflow_requires' => array_values( array_map( 'strval', $workflow_requires ) ),
			'court_assigned'    => array_values( array_map( 'strval', $court_assigned ) ),
			'system_generated'  => array_values( array_map( 'strval', $system_generated ) ),
		);
	}
}
