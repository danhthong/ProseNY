<?php
/**
 * Case Context Builder — single canonical procedural snapshot for all surfaces.
 *
 * Roadmap, sidebar, chat, Case Memory, workflow state, and download actions
 * must all derive current stage and progress from this builder — never duplicate.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Ai_Intake;

use ProSe\Core\Intake\Case_Summary_Presenter;
use ProSe\Core\Intake\Completed_Stage_Document_Store;
use ProSe\Core\Routing\Routing_Discriminator_Catalog;
use ProSe\Core\Routing\Workflow_Catalog;
use ProSe\Core\Routing\Workflow_Engine;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Case_Context_Builder
 */
final class Case_Context_Builder {

	/**
	 * @var Workflow_Engine
	 */
	private Workflow_Engine $workflow_engine;

	/**
	 * @var Required_Fields_Provider
	 */
	private Required_Fields_Provider $fields;

	/**
	 * @var Case_Summary_Presenter
	 */
	private Case_Summary_Presenter $summary_presenter;

	/**
	 * @var Workflow_Catalog
	 */
	private Workflow_Catalog $workflows;

	/**
	 * Constructor.
	 *
	 * @param Workflow_Engine|null          $workflow_engine   Workflow engine.
	 * @param Required_Fields_Provider|null $fields            Fields provider.
	 * @param Case_Summary_Presenter|null   $summary_presenter Summary presenter.
	 * @param Workflow_Catalog|null       $workflows         Workflow catalog.
	 */
	public function __construct(
		?Workflow_Engine $workflow_engine = null,
		?Required_Fields_Provider $fields = null,
		?Case_Summary_Presenter $summary_presenter = null,
		?Workflow_Catalog $workflows = null
	) {
		$this->workflows         = $workflows ?? new Workflow_Catalog();
		$this->fields            = $fields ?? new Required_Fields_Provider();
		$this->workflow_engine   = $workflow_engine ?? new Workflow_Engine( null, null, $this->fields );
		$this->summary_presenter = $summary_presenter ?? new Case_Summary_Presenter( $this->workflows );
	}

	/**
	 * Build canonical case context consumed by chat, UI, and presenters.
	 *
	 * @param array<string, mixed> $input Context input.
	 * @return array<string, mixed>
	 */
	public function build( array $input ): array {
		$intake          = $input['intake'] ?? null;
		$case_profile    = is_array( $input['case_profile'] ?? null ) ? $input['case_profile'] : array();
		$missing_payload = is_array( $input['missing_payload'] ?? null ) ? $input['missing_payload'] : array();
		$procedural_node = trim( (string) ( $input['procedural_node'] ?? $case_profile['procedural_node'] ?? '' ) );
		$roadmap         = is_array( $input['roadmap'] ?? null ) ? $input['roadmap'] : array();
		$completion      = (int) ( $input['completion'] ?? $missing_payload['completion'] ?? 0 );
		$raw_confidence  = (float) ( $input['raw_confidence'] ?? 0.0 );

		if ( ! $intake instanceof Intake_State ) {
			return array();
		}

		$workflow     = (string) ( $intake->workflow() ?? '' );
		$resolved     = $this->fields->resolve( $intake, '' );
		$workflow_state = '' !== $workflow
			? $this->workflow_engine->resolve_state(
				$workflow,
				$intake->plain_facts(),
				$procedural_node,
				(array) ( $resolved['required_field_defs'] ?? array() ),
				Completed_Stage_Document_Store::completed_stage_count( $case_profile )
			)
			: array();

		$stage_context = $this->workflow_engine->determine_stage(
			$workflow,
			$intake->plain_facts(),
			(string) ( $workflow_state['procedural_node'] ?? $procedural_node ),
			! empty( $workflow_state['intake_complete'] ),
			(array) ( $resolved['required_field_defs'] ?? array() ),
			Completed_Stage_Document_Store::completed_stage_count( $case_profile )
		);

		$case_summary = $this->summary_presenter->build(
			array(
				'workflow'        => $workflow,
				'facts'           => $intake->plain_facts(),
				'stage_context'   => $stage_context,
				'roadmap'         => $roadmap,
				'procedural_node' => (string) ( $workflow_state['procedural_node'] ?? $procedural_node ),
				'completion'      => $completion,
				'court'           => (string) ( $input['court'] ?? $intake->court() ?? $case_profile['court'] ?? '' ),
				'issue'           => (string) ( $input['issue'] ?? $intake->issue() ?? $case_profile['issue'] ?? '' ),
			)
		);

		$workflow_assessment = $this->workflow_assessment(
			$intake,
			$missing_payload,
			$resolved,
			$workflow
		);

		$case_memory = Case_Memory::build(
			$intake,
			$missing_payload,
			$stage_context,
			$raw_confidence,
			array(),
			$workflow_state,
			$workflow_assessment
		);

		return array(
			'workflow_state'      => $workflow_state,
			'stage_context'       => $stage_context,
			'case_summary'        => $case_summary,
			'case_memory'         => $case_memory,
			'workflow_assessment' => $workflow_assessment,
		);
	}

	/**
	 * @param Intake_State         $intake          Intake state.
	 * @param array<string, mixed> $missing_payload Missing facts payload.
	 * @param array<string, mixed> $resolved        Resolved fields payload.
	 * @param string               $workflow        Workflow key.
	 * @return array<string, mixed>
	 */
	private function workflow_assessment(
		Intake_State $intake,
		array $missing_payload,
		array $resolved,
		string $workflow
	): array {
		$conversation_missing = is_array( $missing_payload['conversation'] ?? null )
			? $missing_payload['conversation']
			: array();
		$candidates           = $this->candidate_rows( $resolved );
		$confirmed            = $this->confirmed_routing_facts( $intake->plain_facts() );
		$outstanding          = $this->outstanding_rows( $conversation_missing );

		if ( '' !== $workflow ) {
			$title = $this->workflow_title( $workflow );

			return array(
				'status'              => 'confirmed',
				'status_label'        => __( 'Confirmed', 'prose-core' ),
				'workflow'            => $workflow,
				'workflow_title'      => $title,
				'confidence'          => 1.0,
				'confidence_percent'  => 100,
				'confirmed_facts'     => $confirmed,
				'outstanding'         => $outstanding,
				'candidate_workflows' => array(),
				'reason'              => '',
			);
		}

		$routing_keys   = Routing_Discriminator_Catalog::keys();
		$known_count    = count( $confirmed );
		$missing_count  = count( $outstanding );
		$total_routing  = max( 1, $known_count + $missing_count );
		$engine_conf    = (float) ( $resolved['routing_confidence'] ?? 0.0 );
		$derived_conf   = $known_count / $total_routing;
		$confidence     = $engine_conf > 0 ? max( $derived_conf, min( 1.0, $engine_conf ) ) : $derived_conf;

		$likely_title = '';

		if ( ! empty( $candidates[0]['title'] ?? '' ) ) {
			$likely_title = (string) $candidates[0]['title'];
		}

		$reason = '';

		if ( ! empty( $outstanding ) ) {
			$reason = __( 'I need these facts before confirming the final workflow.', 'prose-core' );
		}

		return array(
			'status'              => ! empty( $likely_title ) ? 'likely' : 'gathering',
			'status_label'        => ! empty( $likely_title ) ? __( 'Likely', 'prose-core' ) : __( 'Gathering', 'prose-core' ),
			'workflow'            => '',
			'workflow_title'      => $likely_title,
			'confidence'            => $confidence,
			'confidence_percent'  => (int) round( $confidence * 100 ),
			'confirmed_facts'     => $confirmed,
			'outstanding'         => $outstanding,
			'candidate_workflows' => $candidates,
			'reason'              => $reason,
		);
	}

	/**
	 * @param array<string, mixed> $resolved Resolved payload.
	 * @return array<int, array<string, string>>
	 */
	private function candidate_rows( array $resolved ): array {
		$raw = is_array( $resolved['candidate_workflows'] ?? null ) ? $resolved['candidate_workflows'] : array();
		$rows = array();

		foreach ( $raw as $candidate ) {
			if ( is_array( $candidate ) ) {
				$key   = trim( (string) ( $candidate['workflow'] ?? $candidate['key'] ?? '' ) );
				$title = trim( (string) ( $candidate['title'] ?? '' ) );
			} else {
				$key   = trim( (string) $candidate );
				$title = '';
			}

			if ( '' === $key ) {
				continue;
			}

			if ( '' === $title ) {
				$title = $this->workflow_title( $key );
			}

			$rows[] = array(
				'workflow' => $key,
				'title'    => $title,
			);
		}

		return $rows;
	}

	/**
	 * @param array<string, mixed> $facts Plain facts.
	 * @return string[]
	 */
	private function confirmed_routing_facts( array $facts ): array {
		$rows = array();
		$seen = array();

		foreach ( Routing_Discriminator_Catalog::keys() as $key ) {
			if ( ! array_key_exists( $key, $facts ) ) {
				continue;
			}

			$canonical = Routing_Discriminator_Catalog::canonical_key( $key );

			if ( isset( $seen[ $canonical ] ) ) {
				continue;
			}

			$ack = Routing_Discriminator_Catalog::confirmed_acknowledgment( $key, $facts[ $key ] );

			if ( '' !== $ack ) {
				$seen[ $canonical ] = true;
				$rows[]             = $ack;
			}
		}

		return $rows;
	}

	/**
	 * @param array<int, array<string, mixed>> $conversation_missing Missing rows.
	 * @return array<int, array<string, string>>
	 */
	private function outstanding_rows( array $conversation_missing ): array {
		$rows = array();
		$seen = array();

		foreach ( $conversation_missing as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$key = Routing_Discriminator_Catalog::canonical_key( (string) ( $row['key'] ?? $row['field'] ?? '' ) );

			if ( '' === $key || isset( $seen[ $key ] ) ) {
				continue;
			}

			$seen[ $key ] = true;
			$rows[]       = array(
				'key'   => $key,
				'label' => Routing_Discriminator_Catalog::outstanding_label( $key ),
				'why'   => Routing_Discriminator_Catalog::why_for( $key ),
			);
		}

		return $rows;
	}

	/**
	 * @param string $workflow Workflow key.
	 * @return string
	 */
	private function workflow_title( string $workflow ): string {
		if ( '' === $workflow ) {
			return '';
		}

		$definition = $this->workflows->by_key( $workflow );

		if ( ! is_array( $definition ) ) {
			return ucwords( str_replace( array( '_', '-' ), ' ', $workflow ) );
		}

		$title = trim( (string) ( $definition['description'] ?? $definition['title'] ?? $definition['name'] ?? '' ) );

		return '' !== $title ? $title : ucwords( str_replace( array( '_', '-' ), ' ', $workflow ) );
	}
}
