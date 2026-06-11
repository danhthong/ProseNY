<?php
/**
 * Document generation service — public entry point for the Document
 * Generation Engine.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Forms\Documents;

use ProSe\Core\Forms\Database\Repositories\Case_Repository;
use ProSe\Core\Forms\Database\Repositories\Document_Repository;
use ProSe\Core\Forms\Engine\Case_State;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Document_Generation_Service
 *
 * Orchestrates generation of completed court documents from case data:
 * assembles forms and packages, marks ready documents GENERATED, attaches
 * the audit trail, and persists status when a repository is available.
 *
 * Repository-optional: with a Document_Repository / Case_Repository injected
 * every result is persisted; without them the engine runs purely in memory
 * (used by unit tests), mirroring the Case and Timeline engines.
 */
final class Document_Generation_Service {

	/**
	 * Optional document status persistence.
	 *
	 * @var Document_Repository|null
	 */
	private ?Document_Repository $documents;

	/**
	 * Optional case persistence (for loading by ID).
	 *
	 * @var Case_Repository|null
	 */
	private ?Case_Repository $cases;

	/**
	 * Form assembly service.
	 *
	 * @var Form_Assembly_Service
	 */
	private Form_Assembly_Service $form_assembly;

	/**
	 * Package assembly service.
	 *
	 * @var Package_Assembly_Service
	 */
	private Package_Assembly_Service $package_assembly;

	/**
	 * Constructor.
	 *
	 * @param Document_Repository|null      $documents        Document repo.
	 * @param Case_Repository|null          $cases            Case repo.
	 * @param Form_Assembly_Service|null    $form_assembly    Form assembly.
	 * @param Package_Assembly_Service|null $package_assembly Package assembly.
	 */
	public function __construct(
		?Document_Repository $documents = null,
		?Case_Repository $cases = null,
		?Form_Assembly_Service $form_assembly = null,
		?Package_Assembly_Service $package_assembly = null
	) {
		$this->documents        = $documents;
		$this->cases            = $cases;
		$this->form_assembly    = $form_assembly ?? new Form_Assembly_Service();
		$this->package_assembly = $package_assembly ?? new Package_Assembly_Service();
	}

	/**
	 * Assemble a single form without generating it.
	 *
	 * @param Case_State|int       $case        Case state or case ID.
	 * @param string               $form_code   Form code (form_id).
	 * @param string               $package_key Source package key.
	 * @param array<string, mixed> $context     Resolver context.
	 * @return Generated_Document|null
	 */
	public function assemble_form( $case, string $form_code, string $package_key = '', array $context = array() ): ?Generated_Document {
		$state = $this->state_for( $case );

		if ( null === $state ) {
			return null;
		}

		return $this->form_assembly->assemble( $state, $form_code, $package_key, $context );
	}

	/**
	 * Generate a single completed form from case data.
	 *
	 * Input:  case_id (or state) + form_id (form code).
	 * Output: a generated Generated_Document (GENERATED when ready).
	 *
	 * @param Case_State|int       $case         Case state or case ID.
	 * @param string               $form_code    Form code (form_id).
	 * @param string               $package_key  Source package key.
	 * @param array<string, mixed> $context      Resolver context.
	 * @param int                  $generated_by User ID (0 = current/system).
	 * @param int                  $version      Generation version.
	 * @return Generated_Document|null
	 */
	public function generate_form(
		$case,
		string $form_code,
		string $package_key = '',
		array $context = array(),
		int $generated_by = 0,
		int $version = 1
	): ?Generated_Document {
		$state = $this->state_for( $case );

		if ( null === $state ) {
			return null;
		}

		$document = $this->form_assembly->assemble( $state, $form_code, $package_key, $context );

		$this->finalize_document( $document, $state, $package_key, $generated_by, $version );

		if ( null !== $this->documents && $state->case_id() > 0 ) {
			$this->documents->save_document( $state->case_id(), $document );
		}

		return $document;
	}

	/**
	 * Assemble a package bundle without generating it.
	 *
	 * @param Case_State|int       $case        Case state or case ID.
	 * @param string               $package_key Package key.
	 * @param array<string, mixed> $context     Resolver context.
	 * @return Package_Document_Bundle|null
	 */
	public function assemble_package( $case, string $package_key, array $context = array() ): ?Package_Document_Bundle {
		$state = $this->state_for( $case );

		if ( null === $state ) {
			return null;
		}

		return $this->package_assembly->assemble( $state, $package_key, $context );
	}

	/**
	 * Generate every ready document in a package.
	 *
	 * Input:  case_id (or state) + package_key.
	 * Output: a Package_Document_Bundle with generated forms, missing forms,
	 *         and completion status.
	 *
	 * @param Case_State|int       $case         Case state or case ID.
	 * @param string               $package_key  Package key.
	 * @param array<string, mixed> $context      Resolver context.
	 * @param int                  $generated_by User ID (0 = current/system).
	 * @param int                  $version      Generation version.
	 * @return Package_Document_Bundle|null
	 */
	public function generate_package(
		$case,
		string $package_key,
		array $context = array(),
		int $generated_by = 0,
		int $version = 1
	): ?Package_Document_Bundle {
		$state = $this->state_for( $case );

		if ( null === $state ) {
			return null;
		}

		$bundle = $this->package_assembly->assemble( $state, $package_key, $context );

		foreach ( $bundle->documents() as $document ) {
			$this->finalize_document( $document, $state, $package_key, $generated_by, $version );
		}

		$bundle->set_audit(
			$this->build_audit( $state, $package_key, $generated_by, $version )
		);

		if ( null !== $this->documents && $state->case_id() > 0 ) {
			$this->documents->save_documents( $state->case_id(), array_values( $bundle->documents() ) );
		}

		return $bundle;
	}

	/**
	 * Completeness snapshot for a package.
	 *
	 * @param Case_State|int       $case        Case state or case ID.
	 * @param string               $package_key Package key.
	 * @param array<string, mixed> $context     Resolver context.
	 * @return Package_Completeness|null
	 */
	public function completeness( $case, string $package_key, array $context = array() ): ?Package_Completeness {
		$bundle = $this->assemble_package( $case, $package_key, $context );

		return null === $bundle ? null : $bundle->completeness();
	}

	/**
	 * Mark a ready document GENERATED and attach its audit trail.
	 *
	 * @param Generated_Document $document     Document.
	 * @param Case_State         $state        Case state.
	 * @param string             $package_key  Package key.
	 * @param int                $generated_by User ID.
	 * @param int                $version      Version.
	 * @return void
	 */
	private function finalize_document(
		Generated_Document $document,
		Case_State $state,
		string $package_key,
		int $generated_by,
		int $version
	): void {
		if ( ! $document->is_ready() ) {
			return;
		}

		$document->set_status( Document_Status::GENERATED );
		$document->set_audit(
			$this->build_audit( $state, '' !== $package_key ? $package_key : $document->package_key(), $generated_by, $version )
		);
	}

	/**
	 * Build an audit trail for a generation.
	 *
	 * @param Case_State $state        Case state.
	 * @param string     $package_key  Package key.
	 * @param int        $generated_by User ID.
	 * @param int        $version      Version.
	 * @return Document_Audit_Trail
	 */
	private function build_audit( Case_State $state, string $package_key, int $generated_by, int $version ): Document_Audit_Trail {
		if ( $generated_by <= 0 && function_exists( 'get_current_user_id' ) ) {
			$generated_by = (int) get_current_user_id();
		}

		return new Document_Audit_Trail(
			gmdate( 'Y-m-d H:i:s' ),
			$generated_by,
			$version,
			$state->case_id(),
			$package_key
		);
	}

	/**
	 * Resolve a Case_State from a state instance or a persisted case ID.
	 *
	 * @param Case_State|int $case Case state or case ID.
	 * @return Case_State|null
	 */
	private function state_for( $case ): ?Case_State {
		if ( $case instanceof Case_State ) {
			return $case;
		}

		$case_id = (int) $case;

		if ( null === $this->cases || $case_id <= 0 ) {
			return null;
		}

		return $this->cases->load_state( $case_id );
	}
}
