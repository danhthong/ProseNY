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
	 * Constructor.
	 *
	 * @param Package_Builder|null          $builder     Package builder.
	 * @param Workflow_Catalog|null         $workflows   Workflow catalog.
	 * @param Merged_Blank_Pdf_Service|null $merged      Merged blank PDF service.
	 * @param Form_Page_Resolver|null       $form_pages  Form page resolver.
	 */
	public function __construct(
		?Package_Builder $builder = null,
		?Workflow_Catalog $workflows = null,
		?Merged_Blank_Pdf_Service $merged = null,
		?Form_Page_Resolver $form_pages = null
	) {
		$this->builder    = $builder ?? new Package_Builder();
		$this->workflows  = $workflows ?? new Workflow_Catalog();
		$this->merged     = $merged ?? new Merged_Blank_Pdf_Service();
		$this->form_pages = $form_pages ?? new Form_Page_Resolver();
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

		if ( '' !== $current_stage ) {
			$required_count = 0;
			$optional_count = 0;
			$ready_count    = 0;
			$missing_count  = 0;

			foreach ( $stages as $stage_row ) {
				if ( 'current' !== ( $stage_row['status'] ?? '' ) ) {
					continue;
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
}
