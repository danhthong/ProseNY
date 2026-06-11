<?php
/**
 * Generated document DTO — an assembled (and optionally generated) form.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Forms\Documents;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Generated_Document
 *
 * Represents a single court form assembled from case data: the resolved
 * field set, the lifecycle status, validation outcome, optional generation
 * audit trail, and the source linkage (case + package + form). The DTO is
 * renderer-agnostic — a Document_Provider_Interface implementation consumes
 * it to produce a concrete artifact (PDF, DOCX, XML, ...).
 */
final class Generated_Document {

	/**
	 * Form code (e.g. UD-1).
	 *
	 * @var string
	 */
	private string $form_code;

	/**
	 * Form post ID (0 when unknown / DB-free).
	 *
	 * @var int
	 */
	private int $form_id;

	/**
	 * Human label.
	 *
	 * @var string
	 */
	private string $title;

	/**
	 * Source package key.
	 *
	 * @var string
	 */
	private string $package_key;

	/**
	 * Requirement (required|optional).
	 *
	 * @var string
	 */
	private string $requirement;

	/**
	 * Resolved fields keyed by canonical key.
	 *
	 * @var array<string, Generated_Field>
	 */
	private array $fields;

	/**
	 * Lifecycle status.
	 *
	 * @var string
	 */
	private string $status;

	/**
	 * Validation result.
	 *
	 * @var Document_Validation_Result|null
	 */
	private ?Document_Validation_Result $validation;

	/**
	 * Audit trail (set on generation).
	 *
	 * @var Document_Audit_Trail|null
	 */
	private ?Document_Audit_Trail $audit;

	/**
	 * Constructor.
	 *
	 * @param string                          $form_code   Form code.
	 * @param string                          $package_key Source package key.
	 * @param array<string, Generated_Field>  $fields      Resolved fields.
	 * @param string                          $requirement Requirement.
	 * @param string                          $status      Status.
	 * @param int                             $form_id     Form post ID.
	 * @param string                          $title       Human label.
	 */
	public function __construct(
		string $form_code,
		string $package_key = '',
		array $fields = array(),
		string $requirement = 'required',
		string $status = Document_Status::NOT_STARTED,
		int $form_id = 0,
		string $title = ''
	) {
		$this->form_code   = $form_code;
		$this->package_key = $package_key;
		$this->fields      = $fields;
		$this->requirement = '' !== $requirement ? $requirement : 'required';
		$this->status      = Document_Status::is_valid( $status ) ? $status : Document_Status::NOT_STARTED;
		$this->form_id     = $form_id;
		$this->title       = '' !== $title ? $title : $form_code;
		$this->validation  = null;
		$this->audit       = null;
	}

	/**
	 * @return string
	 */
	public function form_code(): string {
		return $this->form_code;
	}

	/**
	 * @return int
	 */
	public function form_id(): int {
		return $this->form_id;
	}

	/**
	 * @return string
	 */
	public function title(): string {
		return $this->title;
	}

	/**
	 * @return string
	 */
	public function package_key(): string {
		return $this->package_key;
	}

	/**
	 * @return string
	 */
	public function requirement(): string {
		return $this->requirement;
	}

	/**
	 * Whether the form is required.
	 *
	 * @return bool
	 */
	public function is_required(): bool {
		return 'required' === $this->requirement;
	}

	/**
	 * Resolved fields keyed by key.
	 *
	 * @return array<string, Generated_Field>
	 */
	public function fields(): array {
		return $this->fields;
	}

	/**
	 * A single field by key.
	 *
	 * @param string $key Field key.
	 * @return Generated_Field|null
	 */
	public function field( string $key ): ?Generated_Field {
		return $this->fields[ $key ] ?? null;
	}

	/**
	 * Visible fields (conditional fields whose condition holds, plus all
	 * non-conditional fields).
	 *
	 * @return array<string, Generated_Field>
	 */
	public function visible_fields(): array {
		$visible = array();

		foreach ( $this->fields as $key => $field ) {
			if ( $field->is_visible() ) {
				$visible[ $key ] = $field;
			}
		}

		return $visible;
	}

	/**
	 * Hidden fields (conditional fields whose condition is false).
	 *
	 * @return array<string, Generated_Field>
	 */
	public function hidden_fields(): array {
		$hidden = array();

		foreach ( $this->fields as $key => $field ) {
			if ( ! $field->is_visible() ) {
				$hidden[ $key ] = $field;
			}
		}

		return $hidden;
	}

	/**
	 * Map of key => value for resolved fields.
	 *
	 * @return array<string, mixed>
	 */
	public function values(): array {
		$values = array();

		foreach ( $this->fields as $key => $field ) {
			if ( $field->is_resolved() ) {
				$values[ $key ] = $field->value();
			}
		}

		return $values;
	}

	/**
	 * @return string
	 */
	public function status(): string {
		return $this->status;
	}

	/**
	 * Transition the document status (forward-only, validated).
	 *
	 * @param string $status Target status.
	 * @return bool Whether the transition was applied.
	 */
	public function set_status( string $status ): bool {
		if ( ! Document_Status::is_valid( $status ) || ! Document_Status::can_transition( $this->status, $status ) ) {
			return false;
		}

		$this->status = $status;

		return true;
	}

	/**
	 * @return Document_Validation_Result|null
	 */
	public function validation(): ?Document_Validation_Result {
		return $this->validation;
	}

	/**
	 * Attach a validation result.
	 *
	 * @param Document_Validation_Result $validation Validation result.
	 * @return void
	 */
	public function set_validation( Document_Validation_Result $validation ): void {
		$this->validation = $validation;
	}

	/**
	 * Whether the document is valid (no validation errors).
	 *
	 * @return bool
	 */
	public function is_valid(): bool {
		return null !== $this->validation && $this->validation->is_valid();
	}

	/**
	 * @return Document_Audit_Trail|null
	 */
	public function audit(): ?Document_Audit_Trail {
		return $this->audit;
	}

	/**
	 * Attach the generation audit trail.
	 *
	 * @param Document_Audit_Trail $audit Audit trail.
	 * @return void
	 */
	public function set_audit( Document_Audit_Trail $audit ): void {
		$this->audit = $audit;
	}

	/**
	 * Whether the document has been generated.
	 *
	 * @return bool
	 */
	public function is_generated(): bool {
		return in_array(
			$this->status,
			array( Document_Status::GENERATED, Document_Status::SIGNED, Document_Status::FILED ),
			true
		);
	}

	/**
	 * Whether the document is ready to generate.
	 *
	 * @return bool
	 */
	public function is_ready(): bool {
		return Document_Status::READY === $this->status;
	}

	/**
	 * Serialize to array.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		$fields = array();

		foreach ( $this->fields as $key => $field ) {
			$fields[ $key ] = $field->to_array();
		}

		return array(
			'form_code'   => $this->form_code,
			'form_id'     => $this->form_id,
			'title'       => $this->title,
			'package_key' => $this->package_key,
			'requirement' => $this->requirement,
			'status'      => $this->status,
			'fields'      => $fields,
			'values'      => $this->values(),
			'validation'  => null === $this->validation ? null : $this->validation->to_array(),
			'audit'       => null === $this->audit ? null : $this->audit->to_array(),
		);
	}
}
