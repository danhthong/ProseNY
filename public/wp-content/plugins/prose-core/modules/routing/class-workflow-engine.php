<?php
/**
 * Workflow Engine — deterministic legal workflow authority (no conversational text).
 *
 * JSON workflow definitions are data sources. This engine resolves workflow,
 * stage, forms, conditions, and missing facts. ChatGPT consumes its output
 * through Case_Memory; it never generates user-facing prose.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Routing;

use ProSe\Core\Ai_Intake\Intake_State;
use ProSe\Core\Ai_Intake\Required_Fields_Provider;
use ProSe\Core\Forms\Engine\Stage_Form_Presenter;
use ProSe\Core\Forms\Engine\Workflow_Form_Applicability_Service;
use ProSe\Core\Forms\Engine\Workflow_Progression_Service;
use ProSe\Core\Intake\Completion_Calculator;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Workflow_Engine
 */
final class Workflow_Engine {

	/**
	 * Routing engine.
	 *
	 * @var Routing_Engine
	 */
	private Routing_Engine $routing;

	/**
	 * Workflow catalog.
	 *
	 * @var Workflow_Catalog
	 */
	private Workflow_Catalog $catalog;

	/**
	 * Required fields provider.
	 *
	 * @var Required_Fields_Provider
	 */
	private Required_Fields_Provider $fields;

	/**
	 * Completion calculator.
	 *
	 * @var Completion_Calculator
	 */
	private Completion_Calculator $completion;

	/**
	 * Stage presenter.
	 *
	 * @var Stage_Form_Presenter
	 */
	private Stage_Form_Presenter $stages;

	/**
	 * Form applicability evaluator.
	 *
	 * @var Workflow_Form_Applicability_Service
	 */
	private Workflow_Form_Applicability_Service $applicability;

	/**
	 * Canonical workflow state resolver.
	 *
	 * @var Workflow_State_Resolver
	 */
	private Workflow_State_Resolver $state_resolver;

	/**
	 * Constructor.
	 *
	 * @param Routing_Engine|null               $routing       Routing engine.
	 * @param Workflow_Catalog|null             $catalog       Workflow catalog.
	 * @param Required_Fields_Provider|null     $fields        Fields provider.
	 * @param Completion_Calculator|null        $completion    Completion calculator.
	 * @param Stage_Form_Presenter|null         $stages        Stage presenter.
	 * @param Workflow_Form_Applicability_Service|null $applicability Applicability service.
	 */
	public function __construct(
		?Routing_Engine $routing = null,
		?Workflow_Catalog $catalog = null,
		?Required_Fields_Provider $fields = null,
		?Completion_Calculator $completion = null,
		?Stage_Form_Presenter $stages = null,
		?Workflow_Form_Applicability_Service $applicability = null
	) {
		$this->catalog       = $catalog ?? new Workflow_Catalog();
		$this->routing       = $routing ?? new Routing_Engine( $this->catalog );
		$this->fields        = $fields ?? new Required_Fields_Provider( $this->routing, $this->catalog );
		$this->completion    = $completion ?? new Completion_Calculator();
		$this->stages          = $stages ?? new Stage_Form_Presenter();
		$this->applicability   = $applicability ?? new Workflow_Form_Applicability_Service();
		$this->state_resolver  = new Workflow_State_Resolver( $this->catalog );
	}

	/**
	 * Resolve canonical workflow state (single source of truth).
	 *
	 * @param string               $workflow            Workflow key.
	 * @param array<string, mixed> $facts               Plain facts.
	 * @param string               $procedural_node     Stored procedural node.
	 * @param array<int, array<string, mixed>> $required_field_defs Required field definitions.
	 * @return array<string, mixed>
	 */
	public function resolve_state(
		string $workflow,
		array $facts,
		string $procedural_node = '',
		array $required_field_defs = array(),
		int $completed_stage_count = 0
	): array {
		return $this->state_resolver->resolve(
			array(
				'workflow'               => $workflow,
				'facts'                  => $facts,
				'procedural_node'        => $procedural_node,
				'required_field_defs'    => $required_field_defs,
				'completed_stage_count'  => max( 0, $completed_stage_count ),
			)
		);
	}

	/**
	 * Identify workflow, issue, and court from message + state.
	 *
	 * @param string       $message Latest user message.
	 * @param Intake_State $state   Intake state (updated in place).
	 * @return array<string, mixed> Resolved routing payload.
	 */
	public function identify_workflow( string $message, Intake_State $state ): array {
		return $this->fields->resolve( $state, $message );
	}

	/**
	 * Evaluate workflow required_when / routing conditions against facts.
	 *
	 * @param string               $workflow Workflow key.
	 * @param array<string, mixed> $facts    Plain facts.
	 * @return array<string, mixed> Applicable forms and skipped forms with reasons.
	 */
	public function evaluate_conditions( string $workflow, array $facts ): array {
		$definition = $this->catalog->by_key( $workflow );

		if ( null === $definition ) {
			return array(
				'applicable_forms' => array(),
				'skipped_forms'    => array(),
			);
		}

		$forms = array();

		foreach ( (array) ( $definition['required_forms'] ?? array() ) as $stage_row ) {
			foreach ( (array) ( $stage_row['forms'] ?? array() ) as $form ) {
				if ( is_array( $form ) ) {
					$forms[] = $form;
				}
			}
		}

		$stage = sanitize_key( (string) ( $definition['stages'][0] ?? $definition['stages'][0]['id'] ?? 'commencement' ) );
		$out   = array(
			'applicable_forms' => array(),
			'skipped_forms'    => array(),
		);

		foreach ( $forms as $form ) {
			if ( ! is_array( $form ) ) {
				continue;
			}

			$code = (string) ( $form['code'] ?? '' );

			if ( '' === $code ) {
				continue;
			}

			$result = $this->applicability->evaluate( $form, $workflow, $stage, $facts );

			if ( ! empty( $result['applicable'] ) ) {
				$out['applicable_forms'][] = array(
					'code'     => $code,
					'title'    => (string) ( $form['title'] ?? $code ),
					'required' => ! empty( $form['required'] ),
				);
			} elseif ( ! empty( $result['reason'] ) ) {
				$out['skipped_forms'][] = array(
					'code'   => $code,
					'reason' => (string) $result['reason'],
				);
			}
		}

		return $out;
	}

	/**
	 * Determine current and next procedural stage for a workflow.
	 *
	 * @param string               $workflow        Workflow key.
	 * @param array<string, mixed> $facts           Plain facts.
	 * @param string               $procedural_node Current procedural node.
	 * @param bool                 $intake_complete Whether routing intake is complete.
	 * @return array<string, mixed> Stage context from the stage presenter.
	 */
	public function determine_stage(
		string $workflow,
		array $facts,
		string $procedural_node = '',
		bool $intake_complete = false,
		array $required_field_defs = array(),
		int $completed_stage_count = 0
	): array {
		$workflow_state = $this->resolve_state(
			$workflow,
			$facts,
			$procedural_node,
			$required_field_defs,
			$completed_stage_count
		);

		return $this->stages->present(
			array(
				'workflow'              => $workflow,
				'facts'                   => $facts,
				'intake_complete'         => (bool) ( $workflow_state['intake_complete'] ?? $intake_complete ),
				'issue'                   => (string) ( $facts['issue'] ?? 'divorce' ),
				'current_node'            => (string) ( $workflow_state['procedural_node'] ?? $procedural_node ),
				'current_stage'           => sanitize_key( (string) ( $workflow_state['current_stage']['id'] ?? '' ) ),
				'completed_stage_count'   => max( 0, $completed_stage_count ),
			)
		);
	}

	/**
	 * Required form codes for a workflow (static catalog list).
	 *
	 * @param string               $workflow Workflow key.
	 * @param array<string, mixed> $facts    Optional facts for applicability filtering.
	 * @return array<int, string>
	 */
	public function get_required_forms( string $workflow, array $facts = array() ): array {
		$definition = $this->catalog->by_key( $workflow );

		if ( null === $definition ) {
			return array();
		}

		if ( empty( $facts ) ) {
			return $this->catalog->required_form_codes( $definition );
		}

		$evaluated = $this->evaluate_conditions( $workflow, $facts );

		return array_values(
			array_map(
				static function ( array $form ): string {
					return (string) ( $form['code'] ?? '' );
				},
				(array) ( $evaluated['applicable_forms'] ?? array() )
			)
		);
	}

	/**
	 * Missing facts still needed for routing or workflow completion.
	 *
	 * @param Intake_State $state   Intake state.
	 * @param string       $message Latest user message.
	 * @return array{
	 *   all: array<int, array<string, mixed>>,
	 *   conversation: array<int, array<string, mixed>>,
	 *   resolved: array<string, mixed>,
	 *   completion: int
	 * }
	 */
	public function get_missing_facts( Intake_State $state, string $message ): array {
		$resolved   = $this->identify_workflow( $message, $state );
		$all        = $this->fields->missing_prioritized( $resolved['fields'], $state );
		$workflow   = $state->workflow();
		$completion = $this->completion->calculate(
			$resolved['required_field_defs'],
			$state->plain_facts()
		);

		return array(
			'all'          => $all,
			'conversation' => $this->fields->conversation_missing_information( $all, $workflow ),
			'resolved'     => $resolved,
			'completion'   => $completion,
		);
	}

	/**
	 * Generate a filing package descriptor (no conversational text).
	 *
	 * @param string               $workflow Workflow key.
	 * @param array<string, mixed> $facts    Plain facts.
	 * @param string               $stage_id Optional stage id filter.
	 * @return array<string, mixed>
	 */
	public function generate_package( string $workflow, array $facts, string $stage_id = '', string $procedural_node = '' ): array {
		$definition = $this->catalog->by_key( $workflow );
		$required   = is_array( $definition['required_fields'] ?? null ) ? $definition['required_fields'] : array();
		$stage      = $this->determine_stage( $workflow, $facts, $procedural_node, false, $required );
		$forms = array();

		foreach ( (array) ( $stage['stage_forms'] ?? array() ) as $form ) {
			if ( ! is_array( $form ) ) {
				continue;
			}

			$code = (string) ( $form['code'] ?? '' );

			if ( '' === $code ) {
				continue;
			}

			if ( '' !== $stage_id && sanitize_key( (string) ( $stage['current_stage']['id'] ?? '' ) ) !== sanitize_key( $stage_id ) ) {
				continue;
			}

			$forms[] = $code;
		}

		if ( empty( $forms ) ) {
			$forms = $this->get_required_forms( $workflow, $facts );
		}

		$progression = new Workflow_Progression_Service( $this->catalog );

		return array(
			'workflow'       => $workflow,
			'stage'          => (string) ( $stage['current_stage']['id'] ?? '' ),
			'form_codes'     => array_values( array_unique( $forms ) ),
			'stages'         => $progression->get_stages( $workflow, $facts ),
			'ready'          => ! empty( $workflow ) && ! empty( $forms ),
		);
	}
}
