<?php
/**
 * Case stage integrity — reconcile procedural_node → stage → roadmap → memory.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Intake;

use ProSe\Core\Ai_Intake\Case_Context_Builder;
use ProSe\Core\Ai_Intake\Intake_State;
use ProSe\Core\Guidance\Procedural_Roadmap_Presenter;
use ProSe\Core\Routing\Workflow_Catalog;
use ProSe\Core\Routing\Workflow_Engine;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Case_Stage_Integrity
 */
final class Case_Stage_Integrity {

	/**
	 * @var Workflow_Engine
	 */
	private Workflow_Engine $workflow_engine;

	/**
	 * @var Workflow_Catalog
	 */
	private Workflow_Catalog $workflows;

	/**
	 * @var Procedural_Roadmap_Presenter
	 */
	private Procedural_Roadmap_Presenter $roadmap_presenter;

	/**
	 * @var Case_Context_Builder
	 */
	private Case_Context_Builder $case_context;

	/**
	 * Constructor.
	 *
	 * @param Workflow_Engine|null              $workflow_engine   Workflow engine.
	 * @param Workflow_Catalog|null             $workflows         Workflow catalog.
	 * @param Procedural_Roadmap_Presenter|null $roadmap_presenter Roadmap presenter.
	 * @param Case_Context_Builder|null         $case_context      Case context builder.
	 */
	public function __construct(
		?Workflow_Engine $workflow_engine = null,
		?Workflow_Catalog $workflows = null,
		?Procedural_Roadmap_Presenter $roadmap_presenter = null,
		?Case_Context_Builder $case_context = null
	) {
		$this->workflows         = $workflows ?? new Workflow_Catalog();
		$this->workflow_engine   = $workflow_engine ?? new Workflow_Engine();
		$this->roadmap_presenter = $roadmap_presenter ?? new Procedural_Roadmap_Presenter();
		$this->case_context      = $case_context ?? new Case_Context_Builder( $this->workflow_engine );
	}

	/**
	 * Rebuild workflow_state, stage context, roadmap, and case memory from procedural_node.
	 *
	 * @param array<string, mixed> $case_profile Case profile snapshot.
	 * @param bool                 $forms_phase  Whether procedural forms are unlocked.
	 * @return array<string, mixed>
	 */
	public function reconcile_case_profile( array $case_profile, bool $forms_phase = true ): array {
		$workflow = trim( (string) ( $case_profile['workflow'] ?? '' ) );

		if ( '' === $workflow ) {
			return $case_profile;
		}

		$facts            = is_array( $case_profile['facts'] ?? null ) ? $case_profile['facts'] : array();
		$stored_node      = trim( (string) ( $case_profile['procedural_node'] ?? '' ) );
		$required_defs    = $this->required_field_defs( $workflow );
		$completed_count  = Completed_Stage_Document_Store::completed_stage_count( $case_profile );
		$workflow_state   = $this->workflow_engine->resolve_state(
			$workflow,
			$facts,
			$stored_node,
			$required_defs,
			$completed_count
		);
		$effective_node   = trim( (string) ( $workflow_state['procedural_node'] ?? $stored_node ) );

		$case_profile['procedural_node'] = $effective_node;
		$case_profile['workflow_state']  = $workflow_state;

		$stage_context = $this->workflow_engine->determine_stage(
			$workflow,
			$facts,
			$effective_node,
			$forms_phase,
			$required_defs,
			$completed_count
		);

		$case_profile = $this->refresh_roadmap( $case_profile, $stage_context );

		$intake = Intake_State::from_array( array() );
		$intake->import_case_profile( $case_profile );
		$intake->set_workflow( $workflow );

		$missing_payload = $this->workflow_engine->get_missing_facts( $intake, '' );
		$canonical       = $this->case_context->build(
			array(
				'intake'          => $intake,
				'case_profile'    => $case_profile,
				'procedural_node' => $effective_node,
				'missing_payload' => $missing_payload,
				'roadmap'         => is_array( $case_profile['roadmap'] ?? null ) ? $case_profile['roadmap'] : array(),
				'completion'      => (int) ( $missing_payload['completion'] ?? ( $case_profile['progress'] ?? 0 ) ),
				'court'           => (string) ( $case_profile['court'] ?? '' ),
				'issue'           => (string) ( $case_profile['issue'] ?? '' ),
			)
		);

		if ( ! empty( $canonical['workflow_state'] ) ) {
			$case_profile['workflow_state'] = $canonical['workflow_state'];
		}

		if ( ! empty( $canonical['case_memory'] ) ) {
			$case_profile['case_memory'] = $canonical['case_memory'];
		}

		return $case_profile;
	}

	/**
	 * Verify all stage representations match before an AI invocation.
	 *
	 * @param array<string, mixed> $snapshot Stage snapshot.
	 * @return array{valid: bool, canonical_stage: string, mismatches: array<int, array{source: string, stage: string}>}
	 */
	public function validate_stage_snapshot( array $snapshot ): array {
		$sources = array(
			'roadmap'         => $this->stage_id_from( $snapshot['roadmap']['current_stage'] ?? null ),
			'workflow_state'  => $this->stage_id_from( $snapshot['workflow_state']['current_stage'] ?? null ),
			'case_memory'     => $this->stage_id_from( $snapshot['case_memory']['current_stage'] ?? null ),
			'stage_context'   => $this->stage_id_from( $snapshot['stage_context']['current_stage'] ?? null ),
			'transition'      => $this->stage_id_from( $snapshot['transition']['current_stage'] ?? null ),
			'brief'           => sanitize_key( (string) ( $snapshot['brief_stage'] ?? '' ) ),
		);

		$canonical = '';
		$mismatches = array();

		foreach ( $sources as $source => $stage_id ) {
			if ( '' === $stage_id ) {
				continue;
			}

			if ( '' === $canonical ) {
				$canonical = $stage_id;
				continue;
			}

			if ( $stage_id !== $canonical ) {
				$mismatches[] = array(
					'source' => (string) $source,
					'stage'  => $stage_id,
				);
			}
		}

		if ( '' === $canonical && ! empty( $mismatches ) ) {
			$canonical = (string) ( $mismatches[0]['stage'] ?? '' );
		}

		return array(
			'valid'           => empty( $mismatches ),
			'canonical_stage' => $canonical,
			'mismatches'      => $mismatches,
		);
	}

	/**
	 * @param mixed $stage Stage array or string.
	 * @return string
	 */
	private function stage_id_from( $stage ): string {
		if ( is_string( $stage ) ) {
			return sanitize_key( $stage );
		}

		if ( ! is_array( $stage ) ) {
			return '';
		}

		return sanitize_key( (string) ( $stage['id'] ?? '' ) );
	}

	/**
	 * @param array<string, mixed> $case_profile Case profile.
	 * @param array<string, mixed> $stage_ctx    Stage context.
	 * @return array<string, mixed>
	 */
	private function refresh_roadmap( array $case_profile, array $stage_ctx ): array {
		$facts    = is_array( $case_profile['facts'] ?? null ) ? $case_profile['facts'] : array();
		$workflow = trim( (string) ( $case_profile['workflow'] ?? '' ) );

		$roadmap = $this->roadmap_presenter->present(
			array(
				'issue'                => (string) ( $case_profile['issue'] ?? $facts['issue'] ?? 'divorce' ),
				'facts'                => $facts,
				'workflow'             => $workflow,
				'completion'           => (int) ( $case_profile['progress'] ?? 0 ),
				'missing_fields'       => array(),
				'stage_context'        => $stage_ctx,
				'procedural_navigator' => array(),
				'workflow_resolved'    => true,
				'intake_complete'      => true,
				'procedural_node'      => (string) ( $case_profile['procedural_node'] ?? '' ),
			)
		);

		$case_profile['roadmap']             = $roadmap;
		$case_profile['roadmap_fingerprint'] = (string) ( $roadmap['fingerprint'] ?? '' );
		$case_profile['progress']            = (int) ( $roadmap['progress_percentage'] ?? ( $case_profile['progress'] ?? 0 ) );

		return $case_profile;
	}

	/**
	 * @param string $workflow Workflow key.
	 * @return array<int, array<string, mixed>>
	 */
	private function required_field_defs( string $workflow ): array {
		$definition = $this->workflows->by_key( $workflow );
		$fields     = is_array( $definition['required_fields'] ?? null ) ? $definition['required_fields'] : array();

		return $fields;
	}
}
