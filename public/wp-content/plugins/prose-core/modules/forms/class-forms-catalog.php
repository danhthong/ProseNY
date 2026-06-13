<?php
/**
 * Forms Catalog — loads form definitions from docs/forms.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Forms;

use ProSe\Core\Routing\Workflow_Catalog;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Forms_Catalog
 *
 * Single read point for the Forms Repository JSON files.
 */
final class Forms_Catalog {

	/**
	 * Cached forms keyed by form code.
	 *
	 * @var array<string, array<string, mixed>>|null
	 */
	private static ?array $cache = null;

	/**
	 * Workflow catalog dependency.
	 *
	 * @var Workflow_Catalog
	 */
	private Workflow_Catalog $workflow_catalog;

	/**
	 * Constructor.
	 *
	 * @param Workflow_Catalog|null $workflow_catalog Optional workflow catalog.
	 */
	public function __construct( ?Workflow_Catalog $workflow_catalog = null ) {
		$this->workflow_catalog = $workflow_catalog ?? new Workflow_Catalog();
	}

	/**
	 * All forms keyed by form code.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function all(): array {
		return $this->load();
	}

	/**
	 * Get a form by code.
	 *
	 * @param string $form_code Form code.
	 * @return array<string, mixed>|null
	 */
	public function by_code( string $form_code ): ?array {
		$all = $this->load();

		return $all[ $form_code ] ?? null;
	}

	/**
	 * Get forms for a court.
	 *
	 * @param string $court Court key (supreme_court|family_court).
	 * @return array<string, array<string, mixed>>
	 */
	public function by_court( string $court ): array {
		$result = array();

		foreach ( $this->load() as $code => $form ) {
			if ( (string) ( $form['court'] ?? '' ) === $court ) {
				$result[ $code ] = $form;
			}
		}

		return $result;
	}

	/**
	 * Get required form codes for a workflow.
	 *
	 * @param string $workflow_key Workflow key.
	 * @return string[]
	 */
	public function get_forms_for_workflow( string $workflow_key ): array {
		$definition = $this->workflow_catalog->by_key( $workflow_key );

		if ( null === $definition ) {
			return array();
		}

		return $this->workflow_catalog->required_form_codes( $definition );
	}

	/**
	 * Get canonical form records required by a workflow.
	 *
	 * @param string $workflow_key Workflow key.
	 * @return array<string, array<string, mixed>>
	 */
	public function get_form_records_for_workflow( string $workflow_key ): array {
		$records = array();

		foreach ( $this->get_forms_for_workflow( $workflow_key ) as $code ) {
			$record = $this->by_code( $code );

			if ( null !== $record ) {
				$records[ $code ] = $record;
			}
		}

		return $records;
	}

	/**
	 * Validate that every workflow-required form exists in the repository.
	 *
	 * @return array<string, string[]> Missing form codes keyed by workflow.
	 */
	public function validate_workflow_coverage(): array {
		$missing = array();

		foreach ( $this->workflow_catalog->all() as $key => $workflow ) {
			unset( $workflow );

			$codes   = $this->workflow_catalog->required_form_codes( $this->workflow_catalog->by_key( $key ) ?? array() );
			$gaps    = array();

			foreach ( $codes as $code ) {
				if ( null === $this->by_code( $code ) ) {
					$gaps[] = $code;
				}
			}

			if ( ! empty( $gaps ) ) {
				$missing[ $key ] = $gaps;
			}
		}

		return $missing;
	}

	/**
	 * Build reverse index: form code => workflow references.
	 *
	 * @return array<string, array<int, array{workflow: string, stage: string, requirement: string}>>
	 */
	public function build_workflow_references_index(): array {
		$index = array();

		foreach ( $this->workflow_catalog->all() as $workflow_key => $workflow ) {
			foreach ( array( 'required_forms' => 'required', 'optional_forms' => 'optional' ) as $form_key => $requirement ) {
				foreach ( (array) ( $workflow[ $form_key ] ?? array() ) as $stage_block ) {
					$stage = (string) ( $stage_block['stage'] ?? '' );

					foreach ( (array) ( $stage_block['forms'] ?? array() ) as $form ) {
						$code = (string) ( $form['code'] ?? '' );

						if ( '' === $code ) {
							continue;
						}

						if ( ! isset( $index[ $code ] ) ) {
							$index[ $code ] = array();
						}

						$index[ $code ][] = array(
							'workflow'    => $workflow_key,
							'stage'       => $stage,
							'requirement' => $requirement,
						);
					}
				}
			}
		}

		return $index;
	}

	/**
	 * Load forms from repository files.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function load(): array {
		if ( null !== self::$cache ) {
			return self::$cache;
		}

		$base  = PROSE_CORE_PATH . 'docs/forms';
		$files = array_merge(
			glob( $base . '/supreme_court/*.json' ) ?: array(),
			glob( $base . '/family_court/*.json' ) ?: array()
		);

		$forms = array();

		foreach ( $files as $file ) {
			$raw = file_get_contents( $file );

			if ( false === $raw ) {
				continue;
			}

			$data = json_decode( $raw, true );

			if ( ! is_array( $data ) || empty( $data['form_code'] ) ) {
				continue;
			}

			$key          = (string) $data['form_code'];
			$forms[ $key ] = $data;
		}

		self::$cache = $forms;

		return $forms;
	}

	/**
	 * Reset cache (for tests).
	 *
	 * @return void
	 */
	public static function reset_cache(): void {
		self::$cache = null;
	}
}
