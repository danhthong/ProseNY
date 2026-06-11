<?php
/**
 * Package document bundle DTO.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Forms\Documents;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Package_Document_Bundle
 *
 * The output of assembling a full package: the required and optional form
 * codes, the assembled/generated Generated_Document objects, the required
 * forms still missing (not yet ready), the overall completion status, and
 * the package-level audit trail.
 */
final class Package_Document_Bundle {

	/**
	 * Package key.
	 *
	 * @var string
	 */
	private string $package_key;

	/**
	 * Required form codes.
	 *
	 * @var string[]
	 */
	private array $required_forms;

	/**
	 * Optional form codes.
	 *
	 * @var string[]
	 */
	private array $optional_forms;

	/**
	 * Assembled documents keyed by form code.
	 *
	 * @var array<string, Generated_Document>
	 */
	private array $documents;

	/**
	 * Completeness snapshot.
	 *
	 * @var Package_Completeness
	 */
	private Package_Completeness $completeness;

	/**
	 * Package-level audit trail (set on generation).
	 *
	 * @var Document_Audit_Trail|null
	 */
	private ?Document_Audit_Trail $audit;

	/**
	 * Constructor.
	 *
	 * @param string                            $package_key    Package key.
	 * @param string[]                          $required_forms Required form codes.
	 * @param string[]                          $optional_forms Optional form codes.
	 * @param array<string, Generated_Document> $documents      Assembled documents.
	 * @param Package_Completeness              $completeness   Completeness.
	 */
	public function __construct(
		string $package_key,
		array $required_forms,
		array $optional_forms,
		array $documents,
		Package_Completeness $completeness
	) {
		$this->package_key    = $package_key;
		$this->required_forms = array_values( array_map( 'strval', $required_forms ) );
		$this->optional_forms = array_values( array_map( 'strval', $optional_forms ) );
		$this->documents      = $documents;
		$this->completeness   = $completeness;
		$this->audit          = null;
	}

	/**
	 * @return string
	 */
	public function package_key(): string {
		return $this->package_key;
	}

	/**
	 * @return string[]
	 */
	public function required_forms(): array {
		return $this->required_forms;
	}

	/**
	 * @return string[]
	 */
	public function optional_forms(): array {
		return $this->optional_forms;
	}

	/**
	 * All assembled documents keyed by form code.
	 *
	 * @return array<string, Generated_Document>
	 */
	public function documents(): array {
		return $this->documents;
	}

	/**
	 * A single document by form code.
	 *
	 * @param string $form_code Form code.
	 * @return Generated_Document|null
	 */
	public function document( string $form_code ): ?Generated_Document {
		return $this->documents[ $form_code ] ?? null;
	}

	/**
	 * Documents that have been generated.
	 *
	 * @return array<string, Generated_Document>
	 */
	public function generated_forms(): array {
		return array_filter(
			$this->documents,
			static fn( Generated_Document $doc ): bool => $doc->is_generated()
		);
	}

	/**
	 * Required form codes that are not yet ready or generated.
	 *
	 * @return string[]
	 */
	public function missing_forms(): array {
		return $this->completeness->missing_forms();
	}

	/**
	 * @return Package_Completeness
	 */
	public function completeness(): Package_Completeness {
		return $this->completeness;
	}

	/**
	 * @return Document_Audit_Trail|null
	 */
	public function audit(): ?Document_Audit_Trail {
		return $this->audit;
	}

	/**
	 * Attach the package-level audit trail.
	 *
	 * @param Document_Audit_Trail $audit Audit trail.
	 * @return void
	 */
	public function set_audit( Document_Audit_Trail $audit ): void {
		$this->audit = $audit;
	}

	/**
	 * Serialize to array.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		$documents = array();

		foreach ( $this->documents as $form_code => $document ) {
			$documents[ $form_code ] = $document->to_array();
		}

		return array(
			'package_key'     => $this->package_key,
			'required_forms'  => $this->required_forms,
			'optional_forms'  => $this->optional_forms,
			'documents'       => $documents,
			'generated_forms' => array_keys( $this->generated_forms() ),
			'missing_forms'   => $this->missing_forms(),
			'completeness'    => $this->completeness->to_array(),
			'audit'           => null === $this->audit ? null : $this->audit->to_array(),
		);
	}
}
