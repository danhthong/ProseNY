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
	 * Form repository for runtime asset overlay.
	 *
	 * @var Form_Repository|null
	 */
	private ?Form_Repository $forms = null;

	/**
	 * Record enricher for runtime asset overlay.
	 *
	 * @var Form_Record_Enricher|null
	 */
	private ?Form_Record_Enricher $enricher = null;

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
		$form_code = trim( $form_code );

		if ( '' === $form_code ) {
			return null;
		}

		$all = $this->load();

		if ( isset( $all[ $form_code ] ) ) {
			return $this->overlay_prose_form_assets( $form_code, $all[ $form_code ] );
		}

		$needle = strtoupper( $form_code );

		foreach ( $all as $code => $record ) {
			if ( strtoupper( (string) $code ) === $needle ) {
				return $this->overlay_prose_form_assets( (string) $code, $record );
			}
		}

		return null;
	}

	/**
	 * Merge prose_form PDF metadata into a catalog record when JSON paths are empty.
	 *
	 * @param string               $form_code Form code.
	 * @param array<string, mixed> $record    Catalog record.
	 * @return array<string, mixed>
	 */
	private function overlay_prose_form_assets( string $form_code, array $record ): array {
		if ( null === $this->forms ) {
			$this->forms = new Form_Repository();
		}

		if ( null === $this->enricher ) {
			$this->enricher = new Form_Record_Enricher();
		}

		$post = $this->forms->get_by_form_code( $form_code );

		if ( ! $post instanceof \WP_Post ) {
			return $record;
		}

		$record = $this->enricher->enrich_assets_from_post( $record, $post );

		return $this->enricher->apply_computed_fields( $record );
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

			$codes = $this->workflow_catalog->required_form_codes( $this->workflow_catalog->by_key( $key ) ?? array() );
			$gaps  = array();

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
	 * Search forms by code, title, court, workflow, stage, county, or issue.
	 *
	 * @param array<string, mixed> $filters Optional filters: q, court, workflow, stage, county, issue.
	 * @param int                  $limit   Max results.
	 * @return array<int, array<string, mixed>>
	 */
	public function search( array $filters = array(), int $limit = 25 ): array {
		$query    = strtolower( trim( (string) ( $filters['q'] ?? '' ) ) );
		$court    = sanitize_key( (string) ( $filters['court'] ?? '' ) );
		$workflow = sanitize_key( (string) ( $filters['workflow'] ?? '' ) );
		$stage    = sanitize_key( (string) ( $filters['stage'] ?? '' ) );
		$county   = sanitize_key( (string) ( $filters['county'] ?? '' ) );
		$issue    = sanitize_key( (string) ( $filters['issue'] ?? '' ) );

		$refs_index = $this->build_workflow_references_index();
		$results    = array();

		foreach ( $this->all() as $code => $form ) {
			if ( $court && (string) ( $form['court'] ?? '' ) !== $court ) {
				continue;
			}

			if ( $county ) {
				$counties = (array) ( $form['counties_supported'] ?? array() );

				if ( ! empty( $counties ) && ! in_array( $county, $counties, true ) ) {
					continue;
				}
			}

			$refs = $refs_index[ $code ] ?? array();

			if ( $workflow || $stage || $issue ) {
				$matched_ref = false;

				foreach ( $refs as $ref ) {
					$wf_def = $this->workflow_catalog->by_key( (string) $ref['workflow'] );

					if ( $workflow && (string) $ref['workflow'] !== $workflow ) {
						continue;
					}

					if ( $stage && (string) $ref['stage'] !== $stage ) {
						continue;
					}

					if ( $issue && sanitize_key( (string) ( $wf_def['issue_type'] ?? '' ) ) !== $issue ) {
						continue;
					}

					$matched_ref = true;
					break;
				}

				if ( ! $matched_ref ) {
					continue;
				}
			}

			if ( '' !== $query ) {
				$haystack = strtolower(
					$code . ' ' . (string) ( $form['title'] ?? '' ) . ' ' . (string) ( $form['internal_code'] ?? '' )
				);

				if ( strtoupper( $query ) !== strtoupper( $code ) && false === strpos( $haystack, $query ) ) {
					continue;
				}
			}

			$results[] = array(
				'code'             => $code,
				'title'            => (string) ( $form['title'] ?? '' ),
				'court'            => (string) ( $form['court'] ?? '' ),
				'category'         => (string) ( $form['category'] ?? '' ),
				'workflows'        => $refs,
				'official_url'     => $this->official_url_for_form( $form ),
				'generation_ready' => ! empty( $form['generation_ready'] ),
			);
		}

		usort(
			$results,
			static function ( array $a, array $b ) use ( $query ): int {
				if ( '' !== $query ) {
					$exact_a = strtoupper( $query ) === strtoupper( (string) $a['code'] ) ? 0 : 1;
					$exact_b = strtoupper( $query ) === strtoupper( (string) $b['code'] ) ? 0 : 1;

					if ( $exact_a !== $exact_b ) {
						return $exact_a <=> $exact_b;
					}
				}

				return strcmp( (string) $a['code'], (string) $b['code'] );
			}
		);

		if ( $limit > 0 ) {
			$results = array_slice( $results, 0, $limit );
		}

		return $results;
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
	 * Resolve the official download URL from a form record.
	 *
	 * @param array<string, mixed> $form Form record.
	 * @return string
	 */
	private function official_url_for_form( array $form ): string {
		$preferred = (string) ( $form['preferred_source'] ?? '' );
		$sources   = (array) ( $form['source_files'] ?? array() );

		if ( '' !== $preferred && isset( $sources[ $preferred ]['url'] ) ) {
			return (string) $sources[ $preferred ]['url'];
		}

		foreach ( $sources as $entry ) {
			if ( is_array( $entry ) && ! empty( $entry['url'] ) ) {
				return (string) $entry['url'];
			}
		}

		return '';
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
