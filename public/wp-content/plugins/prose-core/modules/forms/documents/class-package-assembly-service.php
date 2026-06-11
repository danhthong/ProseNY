<?php
/**
 * Package assembly service — assemble every form in a package.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Forms\Documents;

use ProSe\Core\Forms\Classification\Vocabulary;
use ProSe\Core\Forms\Engine\Case_State;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Package_Assembly_Service
 *
 * Assembles all forms in a package into a Package_Document_Bundle. It reads
 * the package's required and optional form set from the package catalog,
 * assembles each form (threading already-resolved values forward so shared
 * fields such as petitioner_name are reused), and computes the package
 * completion snapshot.
 */
final class Package_Assembly_Service {

	/**
	 * Form assembly service.
	 *
	 * @var Form_Assembly_Service
	 */
	private Form_Assembly_Service $forms;

	/**
	 * Completeness service.
	 *
	 * @var Package_Completeness_Service
	 */
	private Package_Completeness_Service $completeness;

	/**
	 * Constructor.
	 *
	 * @param Form_Assembly_Service|null        $forms        Form assembly service.
	 * @param Package_Completeness_Service|null $completeness Completeness service.
	 */
	public function __construct(
		?Form_Assembly_Service $forms = null,
		?Package_Completeness_Service $completeness = null
	) {
		$this->forms        = $forms ?? new Form_Assembly_Service();
		$this->completeness = $completeness ?? new Package_Completeness_Service();
	}

	/**
	 * Assemble a package into a Package_Document_Bundle.
	 *
	 * @param Case_State           $state       Case state.
	 * @param string               $package_key Package key.
	 * @param array<string, mixed> $context     Resolver context (court, county, generated).
	 * @return Package_Document_Bundle
	 */
	public function assemble( Case_State $state, string $package_key, array $context = array() ): Package_Document_Bundle {
		$definition = $this->package_definition( $package_key );

		$required_forms = array_values( array_unique( array_map( 'strval', (array) ( $definition['required_forms'] ?? array() ) ) ) );
		$optional_forms = array_values( array_unique( array_map( 'strval', (array) ( $definition['optional_forms'] ?? array() ) ) ) );

		// Shared values resolved by earlier forms thread forward to later ones.
		$generated = (array) ( $context['generated'] ?? array() );

		$documents = array();

		foreach ( array_merge( $required_forms, $optional_forms ) as $form_code ) {
			$requirement               = in_array( $form_code, $required_forms, true ) ? 'required' : 'optional';
			$form_context              = $context;
			$form_context['generated'] = $generated;

			$document = $this->forms->assemble( $state, $form_code, $package_key, $form_context );
			$document = $this->with_requirement( $document, $requirement );

			$documents[ $form_code ] = $document;
			$generated               = array_merge( $generated, $document->values() );
		}

		$completeness = $this->completeness->compute( $package_key, $required_forms, $documents );

		return new Package_Document_Bundle(
			$package_key,
			$required_forms,
			$optional_forms,
			$documents,
			$completeness
		);
	}

	/**
	 * Resolve the package catalog definition for a key.
	 *
	 * @param string $package_key Package key.
	 * @return array<string, mixed>
	 */
	private function package_definition( string $package_key ): array {
		$catalog = Vocabulary::package_catalog();

		return $catalog[ $package_key ] ?? array(
			'required_forms' => array(),
			'optional_forms' => array(),
		);
	}

	/**
	 * Rebuild a document with the correct requirement flag.
	 *
	 * @param Generated_Document $document    Document.
	 * @param string             $requirement Requirement.
	 * @return Generated_Document
	 */
	private function with_requirement( Generated_Document $document, string $requirement ): Generated_Document {
		if ( $document->requirement() === $requirement ) {
			return $document;
		}

		$rebuilt = new Generated_Document(
			$document->form_code(),
			$document->package_key(),
			$document->fields(),
			$requirement,
			$document->status(),
			$document->form_id(),
			$document->title()
		);

		$validation = $document->validation();

		if ( null !== $validation ) {
			$rebuilt->set_validation( $validation );
		}

		return $rebuilt;
	}
}
