<?php
/**
 * Package Manifest — the source of truth for a package build.
 *
 * Holds identity (package_id), provenance (workflow + frozen workflow_snapshot),
 * the resolved form list, validation errors, and lifecycle/readiness statuses.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\PackageBuilder;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Package_Manifest
 */
final class Package_Manifest {

	/**
	 * Stable package identifier.
	 *
	 * @var string
	 */
	private string $package_id;

	/**
	 * Originating conversation identifier.
	 *
	 * @var string
	 */
	private string $conversation_id;

	/**
	 * Resolved workflow key.
	 *
	 * @var string
	 */
	private string $workflow;

	/**
	 * Package type (blank|filled).
	 *
	 * @var string
	 */
	private string $package_type;

	/**
	 * Frozen copy of the workflow definition at build time.
	 *
	 * @var array<string, mixed>
	 */
	private array $workflow_snapshot;

	/**
	 * Resolved form entries (deduped).
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $forms = array();

	/**
	 * Index of form code => position in $forms (for dedupe; required wins).
	 *
	 * @var array<string, int>
	 */
	private array $form_index = array();

	/**
	 * Validation errors.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $validation_errors = array();

	/**
	 * Manifest lifecycle status.
	 *
	 * @var string
	 */
	private string $manifest_status = Manifest_Status::DRAFT;

	/**
	 * Constructor.
	 *
	 * @param string               $package_id        Stable package id.
	 * @param string               $conversation_id   Conversation id.
	 * @param string               $workflow          Workflow key.
	 * @param string               $package_type      Package type.
	 * @param array<string, mixed> $workflow_snapshot Frozen workflow definition.
	 */
	public function __construct(
		string $package_id,
		string $conversation_id,
		string $workflow,
		string $package_type,
		array $workflow_snapshot = array()
	) {
		$this->package_id        = $package_id;
		$this->conversation_id   = $conversation_id;
		$this->workflow          = $workflow;
		$this->package_type      = $package_type;
		$this->workflow_snapshot = $workflow_snapshot;
	}

	/**
	 * Add a resolved form entry, deduping by code (required wins over optional).
	 *
	 * @param array<string, mixed> $entry Form entry.
	 * @return void
	 */
	public function add_form( array $entry ): void {
		$code = (string) ( $entry['code'] ?? '' );

		if ( '' === $code ) {
			return;
		}

		if ( isset( $this->form_index[ $code ] ) ) {
			$position  = $this->form_index[ $code ];
			$existing  = $this->forms[ $position ];
			$is_req    = 'required' === ( $entry['requirement'] ?? '' );
			$was_opt   = 'required' !== ( $existing['requirement'] ?? '' );

			// Upgrade an optional duplicate to required; otherwise keep first.
			if ( $is_req && $was_opt ) {
				$this->forms[ $position ] = $entry;
			}

			return;
		}

		$this->form_index[ $code ] = count( $this->forms );
		$this->forms[]             = $entry;
	}

	/**
	 * Record a validation error.
	 *
	 * @param string $code        Related form code (may be empty).
	 * @param string $requirement Requirement level (required|optional|'').
	 * @param string $message     Human-readable message.
	 * @return void
	 */
	public function add_error( string $code, string $requirement, string $message ): void {
		$this->validation_errors[] = array(
			'code'        => $code,
			'requirement' => $requirement,
			'message'     => $message,
		);
	}

	/**
	 * Whether any required form is missing or not generation ready.
	 *
	 * @return bool
	 */
	public function has_required_blockers(): bool {
		foreach ( $this->validation_errors as $error ) {
			if ( 'required' === ( $error['requirement'] ?? '' ) ) {
				return true;
			}
		}

		foreach ( $this->forms as $form ) {
			if ( 'required' === ( $form['requirement'] ?? '' ) && empty( $form['generation_ready'] ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Derived package readiness status.
	 *
	 * @return string
	 */
	public function package_status(): string {
		return $this->has_required_blockers() ? Package_Status::INCOMPLETE : Package_Status::READY;
	}

	/**
	 * Mark the manifest finalized.
	 *
	 * @param string $status One of Manifest_Status constants.
	 * @return void
	 */
	public function set_manifest_status( string $status ): void {
		if ( in_array( $status, Manifest_Status::all(), true ) ) {
			$this->manifest_status = $status;
		}
	}

	/**
	 * Package id accessor.
	 *
	 * @return string
	 */
	public function package_id(): string {
		return $this->package_id;
	}

	/**
	 * Workflow key accessor.
	 *
	 * @return string
	 */
	public function workflow(): string {
		return $this->workflow;
	}

	/**
	 * Form entries accessor.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function forms(): array {
		return $this->forms;
	}

	/**
	 * Workflow snapshot accessor.
	 *
	 * @return array<string, mixed>
	 */
	public function workflow_snapshot(): array {
		return $this->workflow_snapshot;
	}

	/**
	 * Validation errors accessor.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function validation_errors(): array {
		return $this->validation_errors;
	}

	/**
	 * Serialize to array.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array(
			'package_id'        => $this->package_id,
			'conversation_id'   => $this->conversation_id,
			'workflow'          => $this->workflow,
			'package_type'      => $this->package_type,
			'manifest_status'   => $this->manifest_status,
			'package_status'    => $this->package_status(),
			'workflow_snapshot' => $this->workflow_snapshot,
			'forms'             => $this->forms,
			'validation_errors' => $this->validation_errors,
		);
	}
}
