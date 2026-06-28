<?php
/**
 * Package Preview Service — write-free, UI-friendly projection of a manifest.
 *
 * Produces a compact DTO for the front-page chat UI: counts, per-stage form
 * checklist, readiness flags, and statuses. Performs no disk writes, so it is
 * safe for the public, account-free intake widget.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\PackageBuilder;

use ProSe\Core\Forms\Engine\Stage_Form_Presenter;
use ProSe\Core\Forms\Engine\Workflow_Progression_Service;
use ProSe\Core\Forms\Form_Page_Resolver;
use ProSe\Core\Forms\Forms_Catalog;
use ProSe\Core\Routing\Workflow_Catalog;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Package_Preview_Service
 */
final class Package_Preview_Service {

	/**
	 * Package builder.
	 *
	 * @var Package_Builder
	 */
	private Package_Builder $builder;

	/**
	 * Workflow catalog (for the human-readable title).
	 *
	 * @var Workflow_Catalog
	 */
	private Workflow_Catalog $workflows;

	/**
	 * Merged blank PDF service.
	 *
	 * @var Merged_Blank_Pdf_Service
	 */
	private Merged_Blank_Pdf_Service $merged;

	/**
	 * Form page resolver.
	 *
	 * @var Form_Page_Resolver
	 */
	private Form_Page_Resolver $form_pages;

	/**
	 * Forms catalog.
	 *
	 * @var Forms_Catalog
	 */
	private Forms_Catalog $forms;

	/**
	 * Blank asset source for synthesizing missing alternate-path forms.
	 *
	 * @var Blank_Asset_Source
	 */
	private Blank_Asset_Source $blank_assets;

	/**
	 * Constructor.
	 *
	 * @param Package_Builder|null          $builder     Package builder.
	 * @param Workflow_Catalog|null         $workflows   Workflow catalog.
	 * @param Merged_Blank_Pdf_Service|null $merged      Merged blank PDF service.
	 * @param Form_Page_Resolver|null       $form_pages  Form page resolver.
	 * @param Forms_Catalog|null            $forms       Forms catalog.
	 * @param Blank_Asset_Source|null       $blank_assets Blank asset source.
	 */
	public function __construct(
		?Package_Builder $builder = null,
		?Workflow_Catalog $workflows = null,
		?Merged_Blank_Pdf_Service $merged = null,
		?Form_Page_Resolver $form_pages = null,
		?Forms_Catalog $forms = null,
		?Blank_Asset_Source $blank_assets = null
	) {
		$this->builder      = $builder ?? new Package_Builder();
		$this->workflows    = $workflows ?? new Workflow_Catalog();
		$this->merged       = $merged ?? new Merged_Blank_Pdf_Service();
		$this->form_pages   = $form_pages ?? new Form_Page_Resolver();
		$this->forms        = $forms ?? new Forms_Catalog();
		$this->blank_assets = $blank_assets ?? new Blank_Asset_Source();
	}

	/**
	 * Build a preview DTO for the chat UI.
	 *
	 * @param array<string, mixed> $input { conversation_id, workflow, facts, package_type }.
	 * @return array<string, mixed>
	 */
	public function preview( array $input ): array {
		$manifest = $this->builder->build_manifest( $input );

		$forms          = is_array( $manifest['forms'] ?? null ) ? $manifest['forms'] : array();
		$required_count = 0;
		$optional_count = 0;
		$ready_count    = 0;
		$missing_count  = 0;
		$stages         = array();

		foreach ( $forms as $form ) {
			$requirement = (string) ( $form['requirement'] ?? 'required' );
			$ready       = ! empty( $form['generation_ready'] );

			if ( 'optional' === $requirement ) {
				++$optional_count;
			} else {
				++$required_count;

				if ( ! $ready ) {
					++$missing_count;
				}
			}

			if ( $ready ) {
				++$ready_count;
			}

			$stage_label = (string) ( $form['stage'] ?? '' );

			if ( ! isset( $stages[ $stage_label ] ) ) {
				$stages[ $stage_label ] = array(
					'stage'  => $stage_label,
					'status' => 'locked',
					'forms'  => array(),
				);
			}

			$stages[ $stage_label ]['forms'][] = array(
				'code'             => (string) ( $form['code'] ?? '' ),
				'title'            => (string) ( $form['title'] ?? '' ),
				'url'              => $this->form_pages->resolve( (string) ( $form['code'] ?? '' ) ),
				'requirement'      => $requirement,
				'generation_ready' => $ready,
			);
		}

		$workflow_key    = (string) ( $manifest['workflow'] ?? '' );
		$facts           = is_array( $input['facts'] ?? null ) ? $input['facts'] : array();
		$procedural_node = trim( (string) ( $input['procedural_node'] ?? '' ) );
		$stage_hint      = sanitize_key( (string) ( $input['stage'] ?? '' ) );

		if ( '' === $procedural_node && '' !== $stage_hint && '' !== $workflow_key ) {
			$procedural_node = $this->node_for_stage( $workflow_key, $stage_hint, $facts );
		}

		$stage_context   = ( new Stage_Form_Presenter() )->present(
			array(
				'workflow'        => $workflow_key,
				'facts'           => $facts,
				'intake_complete' => true,
				'current_node'    => $procedural_node,
			)
		);
		$current_stage = is_array( $stage_context['current_stage'] ?? null )
			? (string) ( $stage_context['current_stage']['id'] ?? '' )
			: '';

		$stages = $this->finalize_stage_statuses( $stages, $current_stage, $workflow_key, $facts );

		$download_options = is_array( $stage_context['download_options'] ?? null )
			? $stage_context['download_options']
			: array();

		if ( count( $download_options ) >= 2 && '' !== $current_stage ) {
			foreach ( $stages as $index => $stage_row ) {
				if ( (string) ( $stage_row['stage'] ?? '' ) !== $current_stage ) {
					continue;
				}

				$stages[ $index ] = $this->apply_form_paths( $stage_row, $download_options, $current_stage );
				break;
			}
		}

		if ( '' !== $current_stage ) {
			$required_count = 0;
			$optional_count = 0;
			$ready_count    = 0;
			$missing_count  = 0;

			foreach ( $stages as $stage_row ) {
				if ( 'current' !== ( $stage_row['status'] ?? '' ) ) {
					continue;
				}

				if ( ! empty( $stage_row['form_paths'] ) ) {
					foreach ( (array) $stage_row['form_paths'] as $path ) {
						++$required_count;

						foreach ( (array) ( $path['forms'] ?? array() ) as $form ) {
							if ( ! empty( $form['generation_ready'] ) ) {
								++$ready_count;
							} else {
								++$missing_count;
							}
						}
					}

					break;
				}

				foreach ( (array) ( $stage_row['forms'] ?? array() ) as $form ) {
					$requirement = (string) ( $form['requirement'] ?? 'required' );
					$ready       = ! empty( $form['generation_ready'] );

					if ( 'optional' === $requirement ) {
						++$optional_count;
					} else {
						++$required_count;

						if ( ! $ready ) {
							++$missing_count;
						}
					}

					if ( $ready ) {
						++$ready_count;
					}
				}

				break;
			}
		}

		$blank_stage = $current_stage ?: null;

		return array(
			'package_id'        => (string) ( $manifest['package_id'] ?? '' ),
			'workflow'          => $workflow_key,
			'workflow_title'    => $this->workflow_title( $workflow_key ),
			'package_type'      => (string) ( $manifest['package_type'] ?? Package_Type::BLANK ),
			'manifest_status'   => (string) ( $manifest['manifest_status'] ?? Manifest_Status::DRAFT ),
			'package_status'    => (string) ( $manifest['package_status'] ?? Package_Status::INCOMPLETE ),
			'path_options'      => count( $download_options ) >= 2 ? count( $download_options ) : 0,
			'counts'            => array(
				'required' => $required_count,
				'optional' => $optional_count,
				'ready'    => $ready_count,
				'missing'  => $missing_count,
			),
			'stages'            => $stages,
			'stage_context'     => $stage_context,
			'validation_errors' => is_array( $manifest['validation_errors'] ?? null ) ? $manifest['validation_errors'] : array(),
			'blank_pdf'         => $this->merged->status( $workflow_key, $blank_stage, $facts ),
		);
	}

	/**
	 * Human-readable workflow title from its description, falling back to the key.
	 *
	 * @param string $workflow_key Workflow key.
	 * @return string
	 */
	private function workflow_title( string $workflow_key ): string {
		if ( '' === $workflow_key ) {
			return '';
		}

		$definition = $this->workflows->by_key( $workflow_key );

		if ( is_array( $definition ) && ! empty( $definition['description'] ) ) {
			return (string) $definition['description'];
		}

		return ucwords( str_replace( '_', ' ', $workflow_key ) );
	}

	/**
	 * Map a stage slug to its procedural node when the node was not persisted.
	 *
	 * @param string               $workflow_key Workflow key.
	 * @param string               $stage_slug   Stage slug.
	 * @param array<string, mixed> $facts        Facts.
	 * @return string
	 */
	private function node_for_stage( string $workflow_key, string $stage_slug, array $facts ): string {
		$map = ( new Workflow_Progression_Service() )->stage_node_map( $workflow_key, $facts );

		return isset( $map[ $stage_slug ] ) ? (string) $map[ $stage_slug ] : '';
	}

	/**
	 * Assign current/completed/locked status and order stages for the UI.
	 *
	 * Forms remain on every stage so the preview can toggle sections; counts still
	 * reflect only the current procedural step.
	 *
	 * @param array<string, array<string, mixed>> $stages         Stage rows keyed by slug.
	 * @param string                              $current_stage  Active stage slug.
	 * @param string                              $workflow_key   Workflow key.
	 * @param array<string, mixed>                $facts          Plain facts.
	 * @return array<int, array<string, mixed>>
	 */
	private function finalize_stage_statuses( array $stages, string $current_stage, string $workflow_key, array $facts ): array {
		$order         = ( new Workflow_Progression_Service() )->get_stages( $workflow_key, $facts );
		$current_index = array_search( $current_stage, $order, true );

		foreach ( $stages as $label => $stage_row ) {
			$index = array_search( $label, $order, true );

			if ( $label === $current_stage ) {
				$stages[ $label ]['status'] = 'current';
			} elseif ( false !== $current_index && false !== $index && $index < $current_index ) {
				$stages[ $label ]['status'] = 'completed';
			} else {
				$stages[ $label ]['status'] = 'locked';
			}
		}

		$ordered = array();
		$seen    = array();

		foreach ( $order as $stage_slug ) {
			if ( ! isset( $stages[ $stage_slug ] ) ) {
				continue;
			}

			$ordered[]               = $stages[ $stage_slug ];
			$seen[ $stage_slug ] = true;
		}

		foreach ( $stages as $label => $stage_row ) {
			if ( empty( $seen[ $label ] ) ) {
				$ordered[] = $stage_row;
			}
		}

		return $ordered;
	}

	/**
	 * Replace a flat form list with grouped alternate filing paths.
	 *
	 * @param array<string, mixed>             $stage_row        Stage preview row.
	 * @param array<int, array<string, mixed>> $download_options Alternate path options.
	 * @param string                           $stage_slug       Active stage slug.
	 * @return array<string, mixed>
	 */
	private function apply_form_paths( array $stage_row, array $download_options, string $stage_slug ): array {
		$forms_by_code = array();

		foreach ( (array) ( $stage_row['forms'] ?? array() ) as $form ) {
			if ( ! is_array( $form ) ) {
				continue;
			}

			$code = trim( (string) ( $form['code'] ?? '' ) );

			if ( '' === $code ) {
				continue;
			}

			$forms_by_code[ $this->normalize_form_code_key( $code ) ] = $form;
		}

		$paths = array();

		foreach ( $download_options as $option ) {
			if ( ! is_array( $option ) ) {
				continue;
			}

			$path_forms = array();

			foreach ( (array) ( $option['form_codes'] ?? array() ) as $code ) {
				$code = trim( (string) $code );

				if ( '' === $code ) {
					continue;
				}

				$key = $this->normalize_form_code_key( $code );

				if ( isset( $forms_by_code[ $key ] ) ) {
					$path_forms[] = $this->form_for_path_display( $forms_by_code[ $key ] );
					continue;
				}

				$resolved = $this->resolve_path_form( $code, $stage_slug );

				if ( null !== $resolved ) {
					$path_forms[] = $resolved;
				}
			}

			if ( empty( $path_forms ) ) {
				continue;
			}

			$label = trim( (string) ( $option['title'] ?? '' ) );

			if ( '' === $label ) {
				$label = trim( (string) ( $option['label'] ?? '' ) );
			}

			$paths[] = array(
				'id'    => (string) ( $option['id'] ?? '' ),
				'label' => $label,
				'forms' => $path_forms,
			);
		}

		if ( count( $paths ) < 2 ) {
			return $stage_row;
		}

		$stage_row['form_paths'] = $paths;
		$stage_row['forms']      = array();

		return $stage_row;
	}

	/**
	 * Normalize a form code for case-insensitive lookup.
	 *
	 * @param string $code Form code.
	 * @return string
	 */
	private function normalize_form_code_key( string $code ): string {
		return strtoupper( trim( $code ) );
	}

	/**
	 * Forms listed under an alternate filing path are required for that path.
	 *
	 * @param array<string, mixed> $form Preview form row.
	 * @return array<string, mixed>
	 */
	private function form_for_path_display( array $form ): array {
		$form['requirement'] = 'required';

		return $form;
	}

	/**
	 * Build a preview form row when an alternate path references a form outside the manifest.
	 *
	 * @param string $code       Form code.
	 * @param string $stage_slug Stage slug.
	 * @return array<string, mixed>|null
	 */
	private function resolve_path_form( string $code, string $stage_slug ): ?array {
		$record = $this->forms->by_code( $code );
		$entry  = $this->blank_assets->resolve(
			is_array( $record ) ? $record : array(),
			$code,
			$stage_slug,
			'required'
		);

		$resolved_code = trim( (string) ( $entry['code'] ?? $code ) );

		if ( '' === $resolved_code ) {
			return null;
		}

		return array(
			'code'             => $resolved_code,
			'title'            => (string) ( $entry['title'] ?? $resolved_code ),
			'url'              => $this->form_pages->resolve( $resolved_code ),
			'requirement'      => (string) ( $entry['requirement'] ?? 'required' ),
			'generation_ready' => ! empty( $entry['generation_ready'] ),
		);
	}
}
