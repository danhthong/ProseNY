<?php
/**
 * Form assembly service — assemble a single completed form from case data.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Forms\Documents;

use ProSe\Core\Forms\Engine\Case_State;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Form_Assembly_Service
 *
 * Assembles one court form into a Generated_Document: it resolves every
 * field the form references (applying aliases, defaults and conditional
 * logic), flags the required fields, validates the result, and computes
 * the assembly status (NOT_STARTED / IN_PROGRESS / READY).
 *
 * Assembly never marks a document GENERATED — that transition belongs to
 * the Document_Generation_Service, which attaches the audit trail.
 */
final class Form_Assembly_Service {

	/**
	 * Field resolver.
	 *
	 * @var Field_Resolver
	 */
	private Field_Resolver $resolver;

	/**
	 * Validation service.
	 *
	 * @var Document_Validation_Service
	 */
	private Document_Validation_Service $validation;

	/**
	 * Constructor.
	 *
	 * @param Field_Resolver|null              $resolver   Field resolver.
	 * @param Document_Validation_Service|null $validation Validation service.
	 */
	public function __construct(
		?Field_Resolver $resolver = null,
		?Document_Validation_Service $validation = null
	) {
		$this->resolver   = $resolver ?? new Field_Resolver();
		$this->validation = $validation ?? new Document_Validation_Service();
	}

	/**
	 * Assemble a form into a Generated_Document.
	 *
	 * @param Case_State           $state       Case state.
	 * @param string               $form_code   Form code (a.k.a. form_id).
	 * @param string               $package_key Source package key (optional).
	 * @param array<string, mixed> $context     Resolver context (generated, court, county).
	 * @param int                  $form_id     Form post ID (optional).
	 * @return Generated_Document
	 */
	public function assemble(
		Case_State $state,
		string $form_code,
		string $package_key = '',
		array $context = array(),
		int $form_id = 0
	): Generated_Document {
		$keys       = Field_Catalog::all_fields_for( $form_code );
		$classes    = Field_Catalog::classes_for( $form_code );
		$resolution = $this->resolver->resolve( $keys, $state, $context, $classes );

		$required_keys    = Field_Catalog::required_fields( $form_code );
		$active_condition = $this->validation->active_conditional_fields( $form_code, $state );
		$required_lookup  = array_fill_keys( array_merge( $required_keys, $active_condition ), true );
		$active_lookup    = array_fill_keys( $active_condition, true );

		$fields            = array();
		$resolved_required = 0;

		foreach ( $resolution->fields() as $key => $field ) {
			$is_required  = isset( $required_lookup[ $key ] );
			$base_class   = (string) ( $classes[ $key ] ?? Field_Catalog::CLASS_OPTIONAL );
			$is_condition = Field_Catalog::CLASS_CONDITIONAL === $base_class;
			$is_active    = isset( $active_lookup[ $key ] );

			// A CONDITIONAL field becomes REQUIRED when its condition holds;
			// otherwise it stays CONDITIONAL and is hidden.
			$field_class = $is_condition && $is_active ? Field_Catalog::CLASS_REQUIRED : $base_class;
			$is_visible  = $is_condition ? $is_active : true;

			$fields[ $key ] = new Generated_Field(
				$field->key(),
				$field->label(),
				$field->value(),
				$field->source(),
				$is_required,
				$field->is_resolved(),
				$field->is_default(),
				$field_class,
				$is_visible
			);

			if ( $is_required && $field->is_resolved() ) {
				++$resolved_required;
			}
		}

		$validation_result = $this->validation->validate( $form_code, $resolution, $state, $package_key );
		$total_required    = count( $required_lookup );

		$status = Document_Status::resolve_assembly_status(
			$resolved_required,
			$total_required,
			$validation_result->is_valid()
		);

		$document = new Generated_Document(
			$form_code,
			$package_key,
			$fields,
			'required',
			$status,
			$form_id,
			Field_Catalog::form_title( $form_code )
		);

		$document->set_validation( $validation_result );

		return $document;
	}
}
