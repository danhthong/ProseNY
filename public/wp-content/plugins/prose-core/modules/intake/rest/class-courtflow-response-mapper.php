<?php
/**
 * Maps AI intake interpreter results to the CourtFlow workspace API shape.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Intake\Rest;

use ProSe\Core\Ai_Intake\Intake_State;
use ProSe\Core\Ai_Intake\Required_Fields_Provider;
use ProSe\Core\Forms\Engine\Stage_Form_Presenter;
use ProSe\Core\Intake\Case_Actions_Resolver;
use ProSe\Core\Procedural\Procedural_Navigator;
use ProSe\Core\Routing\Workflow_Catalog;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Courtflow_Response_Mapper
 */
final class Courtflow_Response_Mapper {

	/**
	 * Required fields provider.
	 *
	 * @var Required_Fields_Provider
	 */
	private Required_Fields_Provider $fields;

	/**
	 * Case actions resolver.
	 *
	 * @var Case_Actions_Resolver
	 */
	private Case_Actions_Resolver $actions;

	/**
	 * Workflow catalog.
	 *
	 * @var Workflow_Catalog
	 */
	private Workflow_Catalog $workflows;

	/**
	 * Procedural navigator.
	 *
	 * @var Procedural_Navigator
	 */
	private Procedural_Navigator $navigator;

	/**
	 * Stage form presenter.
	 *
	 * @var Stage_Form_Presenter
	 */
	private Stage_Form_Presenter $stage_presenter;

	/**
	 * Constructor.
	 *
	 * @param Required_Fields_Provider|null $fields           Fields provider.
	 * @param Case_Actions_Resolver|null    $actions          Actions resolver.
	 * @param Workflow_Catalog|null         $workflows        Workflow catalog.
	 * @param Procedural_Navigator|null     $navigator        Procedural navigator.
	 * @param Stage_Form_Presenter|null     $stage_presenter  Stage form presenter.
	 */
	public function __construct(
		?Required_Fields_Provider $fields = null,
		?Case_Actions_Resolver $actions = null,
		?Workflow_Catalog $workflows = null,
		?Procedural_Navigator $navigator = null,
		?Stage_Form_Presenter $stage_presenter = null
	) {
		$this->fields          = $fields ?? new Required_Fields_Provider();
		$this->actions         = $actions ?? new Case_Actions_Resolver();
		$this->workflows       = $workflows ?? new Workflow_Catalog();
		$this->navigator       = $navigator ?? new Procedural_Navigator();
		$this->stage_presenter = $stage_presenter ?? new Stage_Form_Presenter();
	}

	/**
	 * Build the workspace state payload (GET /sessions/{id}/state).
	 *
	 * @param array<string, mixed> $session Stored session.
	 * @return array<string, mixed>
	 */
	public function map_session_state( array $session ): array {
		$context = $this->build_context( $session );

		return array_merge(
			$context,
			array(
				'workflow_state' => array(
					'required_forms' => $context['required_forms'],
					'stage_context'  => $context['stage_context'],
					'current_node'   => $context['current_node'],
					'requirements'   => $context['requirements'],
				),
			)
		);
	}

	/**
	 * Build the message POST response for courtflow.js.
	 *
	 * @param array<string, mixed> $session         Stored session.
	 * @param array<string, mixed> $service_response AI_Intake_Service response.
	 * @param array<string, mixed> $applied_updates  Fact updates applied this turn.
	 * @param string               $user_message     Optional latest user message.
	 * @return array<string, mixed>
	 */
	public function map_message_response( array $session, array $service_response, array $applied_updates = array(), string $user_message = '' ): array {
		$context = $this->build_context( $session, $service_response, $applied_updates );
		$result  = is_array( $service_response['result'] ?? null ) ? $service_response['result'] : array();
		$message = (string) ( $result['question'] ?? '' );

		if ( '' === $message && isset( $service_response['message'] ) ) {
			$message = (string) $service_response['message'];
		}

		$response = array_merge(
			$context,
			array(
				'message'         => $message,
				'newly_captured'  => $this->map_newly_captured( $applied_updates ),
				'workflow_state'  => array(
					'required_forms' => $context['required_forms'],
					'stage_context'  => $context['stage_context'],
					'current_node'   => $context['current_node'],
					'requirements'   => $context['requirements'],
				),
			)
		);

		$card = $this->build_procedural_card( $session, $context, $user_message );

		if ( null !== $card ) {
			$response['card'] = $card;
		}

		return $response;
	}

	/**
	 * Build a 422 payload when package generation is blocked.
	 *
	 * @param array<string, mixed> $session Stored session.
	 * @return array<string, mixed>
	 */
	public function map_generation_blocked( array $session ): array {
		$context = $this->build_context( $session );

		return array(
			'message'      => __( 'A few more details are needed before the filing package can be generated.', 'prose-core' ),
			'missing'      => $context['requirements']['missing'] ?? array(),
			'next'         => $context['requirements']['next'] ?? null,
			'completeness' => (int) ( $context['requirements']['completeness'] ?? 0 ),
			'blockers'     => $context['requirements']['blockers'] ?? array(),
		);
	}

	/**
	 * Shared workspace context derived from the stored session.
	 *
	 * @param array<string, mixed>      $session         Session.
	 * @param array<string, mixed>|null $service_response Optional fresh service response.
	 * @param array<string, mixed>      $applied_updates  Optional applied updates.
	 * @return array<string, mixed>
	 */
	private function build_context( array $session, ?array $service_response = null, array $applied_updates = array() ): array {
		unset( $applied_updates );

		$case_profile = is_array( $session['case_profile'] ?? null ) ? $session['case_profile'] : array();
		$result       = is_array( $service_response['result'] ?? null ) ? $service_response['result'] : array();
		$interpret    = ! empty( $result ) ? $result : $this->latest_interpret_snapshot( $session );

		$intake_state = Intake_State::from_array(
			is_array( $session['intake_state'] ?? null ) ? $session['intake_state'] : array()
		);
		$resolved     = $this->fields->resolve( $intake_state, '' );
		$missing      = $this->fields->missing_prioritized( $resolved['fields'], $intake_state );
		$completion   = (int) ( $interpret['completion'] ?? $case_profile['progress'] ?? 0 );
		$workflow     = trim( (string) ( $interpret['workflow'] ?? $case_profile['workflow'] ?? $resolved['workflow'] ?? '' ) );
		$actions      = is_array( $session['actions'] ?? null ) ? $session['actions'] : array();

		if ( empty( $actions ) ) {
			$actions = $this->actions->resolve( $case_profile, $interpret );
		}

		$contradictions = is_array( $interpret['contradictions'] ?? null ) ? $interpret['contradictions'] : array();
		$requirements   = $this->build_requirements( $missing, $completion, $workflow, $actions, $contradictions );
		$facts          = $this->build_facts( $case_profile, $interpret, $actions );
		$stage_context  = $this->build_stage_context( $session, $workflow, $facts, $actions, $interpret );
		$required_forms = $this->required_forms_from_context( $stage_context );
		$current_node   = $this->current_node( $completion, $workflow, $missing, $facts, $stage_context, $session );
		$procedural     = $this->procedural_navigation( $session, $case_profile, $interpret, $actions, $current_node );
		$next_steps     = $this->sanitize_next_steps( $procedural['next_steps'] ?? array(), $stage_context );

		return array(
			'facts'          => $facts,
			'validation'     => $this->build_validation( $contradictions, $requirements ),
			'requirements'   => $requirements,
			'required_forms' => $required_forms,
			'stage_context'  => $stage_context,
			'current_node'   => $current_node,
			'actions'        => $actions,
			'court_routing'  => is_array( $actions['court_routing'] ?? null ) ? $actions['court_routing'] : array(),
			'next_steps'     => $next_steps,
			'procedural'     => $procedural,
		);
	}

	/**
	 * @param array<string, mixed> $session Session.
	 * @return array<string, mixed>
	 */
	private function latest_interpret_snapshot( array $session ): array {
		if ( is_array( $session['last_interpret'] ?? null ) && ! empty( $session['last_interpret'] ) ) {
			return $session['last_interpret'];
		}

		return array(
			'completion'     => (int) ( $session['case_profile']['progress'] ?? 0 ),
			'workflow'       => (string) ( $session['case_profile']['workflow'] ?? '' ),
			'contradictions' => array(),
			'missing_fields' => array(),
		);
	}

	/**
	 * @param array<int, array<string, mixed>> $missing        Missing field defs.
	 * @param int                              $completion     Completion percent.
	 * @param string                           $workflow       Workflow key.
	 * @param array<string, mixed>             $actions        Case actions.
	 * @param array<int, array<string, mixed>> $contradictions Contradictions.
	 * @return array<string, mixed>
	 */
	private function build_requirements( array $missing, int $completion, string $workflow, array $actions, array $contradictions ): array {
		$missing_rows = array();
		$required     = array();
		$collected    = array();

		foreach ( $missing as $field ) {
			$key  = (string) ( $field['field'] ?? '' );
			$path = 'case.' . $key;

			$row = array(
				'path'   => $path,
				'label'  => $this->humanize( $key ),
				'prompt' => (string) ( $field['question'] ?? '' ),
			);

			$missing_rows[] = $row;
			$required[]     = $row;
		}

		$next = ! empty( $missing_rows ) ? $missing_rows[0] : null;

		$ready = ! empty( $actions['intake_complete'] )
			&& ! empty( $actions['workflow_resolved'] )
			&& empty( $contradictions );

		return array(
			'required'          => $required,
			'collected'         => $collected,
			'missing'           => $missing_rows,
			'next'              => $next,
			'completeness'      => max( 0, min( 100, $completion ) ),
			'threshold'         => 80,
			'ready_to_generate' => $ready,
			'blockers'          => $this->blockers_from_contradictions( $contradictions ),
			'summary'           => array(
				'collected_count' => max( 0, count( $required ) - count( $missing_rows ) ),
				'required_count'  => count( $required ),
				'missing_count'   => count( $missing_rows ),
			),
		);
	}

	/**
	 * @param array<string, mixed> $case_profile Case profile.
	 * @param array<string, mixed> $interpret    Interpreter result.
	 * @param array<string, mixed> $actions      Case actions.
	 * @return array<string, mixed>
	 */
	private function build_facts( array $case_profile, array $interpret, array $actions ): array {
		$plain = is_array( $case_profile['facts'] ?? null ) ? $case_profile['facts'] : array();
		$state = is_array( $interpret['state']['facts'] ?? null ) ? $interpret['state']['facts'] : array();

		foreach ( $state as $key => $entry ) {
			if ( is_array( $entry ) && array_key_exists( 'value', $entry ) ) {
				$plain[ (string) $key ] = $entry['value'];
			}
		}

		$case = array();

		foreach ( $plain as $key => $value ) {
			if ( null === $value || '' === $value ) {
				continue;
			}

			$case[ (string) $key ] = $value;
		}

		if ( ! empty( $actions['workflow'] ) ) {
			$case['workflow'] = (string) $actions['workflow'];
		} elseif ( ! empty( $interpret['workflow'] ) ) {
			$case['workflow'] = (string) $interpret['workflow'];
		}

		if ( ! empty( $actions['issue'] ) ) {
			$case['issue'] = (string) $actions['issue'];
		}

		if ( ! empty( $actions['workflow_title'] ) ) {
			$case['workflow_title'] = (string) $actions['workflow_title'];
		}

		return array(
			'case' => $case,
			'user' => array(),
		);
	}

	/**
	 * @param array<int, array<string, mixed>> $contradictions Contradictions.
	 * @param array<string, mixed>             $requirements   Requirements block.
	 * @return array<string, mixed>
	 */
	private function build_validation( array $contradictions, array $requirements ): array {
		$errors   = array();
		$warnings = array();

		foreach ( $contradictions as $item ) {
			$warnings[] = array(
				'path'    => 'case.' . (string) ( $item['field'] ?? '' ),
				'message' => (string) ( $item['message'] ?? '' ),
			);
		}

		if ( ! empty( $requirements['blockers'] ) ) {
			foreach ( $requirements['blockers'] as $blocker ) {
				$errors[] = array(
					'path'    => (string) ( $blocker['path'] ?? '' ),
					'message' => (string) ( $blocker['message'] ?? '' ),
				);
			}
		}

		return array(
			'valid'    => empty( $errors ),
			'errors'   => $errors,
			'warnings' => $warnings,
		);
	}

	/**
	 * @param string                           $workflow Workflow key.
	 * @return array<int, string>
	 */
	private function required_forms_for_workflow( string $workflow ): array {
		if ( '' === $workflow ) {
			return array();
		}

		return $this->stage_presenter->current_stage_form_codes(
			array(
				'workflow'        => $workflow,
				'intake_complete' => true,
			)
		);
	}

	/**
	 * @param array<string, mixed> $stage_context Stage context DTO.
	 * @return array<int, string>
	 */
	private function required_forms_from_context( array $stage_context ): array {
		$codes = array();

		foreach ( (array) ( $stage_context['stage_forms'] ?? array() ) as $form ) {
			$code = trim( (string) ( $form['code'] ?? '' ) );

			if ( '' !== $code ) {
				$codes[] = $code;
			}
		}

		return $codes;
	}

	/**
	 * @param array<string, mixed> $session  Session.
	 * @param string               $workflow Workflow key.
	 * @param array<string, mixed> $facts    Facts block.
	 * @param array<string, mixed> $actions  Case actions.
	 * @param array<string, mixed> $interpret Interpreter snapshot.
	 * @return array<string, mixed>
	 */
	private function build_stage_context( array $session, string $workflow, array $facts, array $actions, array $interpret ): array {
		$case_facts = is_array( $facts['case'] ?? null ) ? $facts['case'] : array();
		$node       = trim( (string) ( $session['procedural_node'] ?? '' ) );

		if ( '' === $node && ! empty( $session['case_id'] ) ) {
			$node = trim( (string) ( $session['case_current_node'] ?? '' ) );
		}

		return $this->stage_presenter->present(
			array(
				'workflow'        => $workflow,
				'facts'           => $case_facts,
				'intake_complete' => ! empty( $actions['intake_complete'] ),
				'current_node'    => $node,
				'issue'           => (string) ( $actions['issue'] ?? $case_facts['issue'] ?? 'divorce' ),
				'routing_missing' => is_array( $interpret['routing_missing'] ?? null ) ? $interpret['routing_missing'] : array(),
			)
		);
	}

	/**
	 * Strip forms from locked stages in workspace next_steps payload.
	 *
	 * @param array<int, array<string, mixed>> $next_steps    Raw next steps.
	 * @param array<string, mixed>             $stage_context Stage context.
	 * @return array<int, array<string, mixed>>
	 */
	private function sanitize_next_steps( array $next_steps, array $stage_context ): array {
		$current_id = is_array( $stage_context['current_stage'] ?? null )
			? (string) ( $stage_context['current_stage']['id'] ?? '' )
			: '';

		$sanitized = array();

		foreach ( $next_steps as $step ) {
			if ( ! is_array( $step ) ) {
				continue;
			}

			$step_id = (string) ( $step['id'] ?? '' );
			$current = '' !== $current_id && $step_id === $current_id;

			if ( ! $current ) {
				unset( $step['forms'] );
				$step['locked'] = true;
			}

			$step['current'] = $current;
			$sanitized[]     = $step;
		}

		return $sanitized;
	}

	/**
	 * @param int                              $completion Completion percent.
	 * @param string                           $workflow   Workflow key.
	 * @param array<int, array<string, mixed>> $missing    Missing fields.
	 * @param array<string, mixed>             $facts      Workspace facts.
	 * @return array<string, mixed>
	 */
	private function current_node( int $completion, string $workflow, array $missing, array $facts, array $stage_context = array(), array $session = array() ): array {
		unset( $session );

		if ( ! empty( $stage_context['forms_visible'] ) && is_array( $stage_context['current_stage'] ?? null ) ) {
			$stage = (string) ( $stage_context['current_stage']['id'] ?? '' );

			if ( '' !== $stage ) {
				return array(
					'id'    => $stage,
					'slug'  => $stage,
					'label' => (string) ( $stage_context['current_stage']['title'] ?? ucwords( str_replace( '_', ' ', $stage ) ) ),
				);
			}
		}

		$slug  = 'intake_basics';
		$label = __( 'Intake', 'prose-core' );

		if ( $completion >= 100 && '' !== $workflow ) {
			$slug  = 'collect_marriage_info';
			$label = __( 'Case details', 'prose-core' );
		} elseif ( $this->missing_targets_children( $missing, $facts ) ) {
			$slug  = 'collect_child_information';
			$label = __( 'Child information', 'prose-core' );
		} elseif ( '' !== $workflow ) {
			$slug  = 'collect_marriage_info';
			$label = __( 'Case details', 'prose-core' );
		}

		return array(
			'id'    => $slug,
			'slug'  => $slug,
			'label' => $label,
		);
	}

	/**
	 * @param array<int, array<string, mixed>> $missing Missing fields.
	 * @param array<string, mixed>             $facts   Facts block.
	 * @return bool
	 */
	private function missing_targets_children( array $missing, array $facts ): bool {
		foreach ( $missing as $field ) {
			$key = (string) ( $field['field'] ?? '' );

			if ( preg_match( '/^child_|^children|^has_minor_children|^minor_children/', $key ) ) {
				return true;
			}
		}

		$case = is_array( $facts['case'] ?? null ) ? $facts['case'] : array();

		if ( ! empty( $case['child_count'] ) || ! empty( $case['has_minor_children'] ) || ! empty( $case['children'] ) ) {
			return true;
		}

		return false;
	}

	/**
	 * @param array<string, mixed> $applied_updates Applied fact updates.
	 * @return array<int, array{path: string, value: mixed}>
	 */
	private function map_newly_captured( array $applied_updates ): array {
		$rows = array();

		foreach ( $applied_updates as $key => $update ) {
			if ( ! is_array( $update ) || ! array_key_exists( 'value', $update ) ) {
				continue;
			}

			$value = $update['value'];

			if ( null === $value || '' === $value ) {
				continue;
			}

			$rows[] = array(
				'path'  => 'case.' . (string) $key,
				'value' => $value,
			);
		}

		return $rows;
	}

	/**
	 * @param array<int, array<string, mixed>> $contradictions Contradictions.
	 * @return array<int, array{path: string, message: string}>
	 */
	private function blockers_from_contradictions( array $contradictions ): array {
		$blockers = array();

		foreach ( $contradictions as $item ) {
			$blockers[] = array(
				'path'    => 'case.' . (string) ( $item['field'] ?? '' ),
				'message' => (string) ( $item['message'] ?? '' ),
			);
		}

		return $blockers;
	}

	/**
	 * @param string $key Field key.
	 * @return string
	 */
	private function humanize( string $key ): string {
		$key = str_replace( '_', ' ', $key );

		return ucwords( trim( $key ) );
	}

	/**
	 * Resolve procedural navigation when intake is complete.
	 *
	 * @param array<string, mixed> $session      Session.
	 * @param array<string, mixed> $case_profile Case profile.
	 * @param array<string, mixed> $interpret    Interpreter snapshot.
	 * @param array<string, mixed> $actions      Case actions.
	 * @param array<string, mixed> $current_node Current node block.
	 * @return array<string, mixed>
	 */
	private function procedural_navigation( array $session, array $case_profile, array $interpret, array $actions, array $current_node ): array {
		if ( empty( $actions['intake_complete'] ) && empty( $actions['workflow_resolved'] ) ) {
			return array();
		}

		$facts    = is_array( $case_profile['facts'] ?? null ) ? $case_profile['facts'] : array();
		$workflow = trim( (string) ( $actions['workflow'] ?? $interpret['workflow'] ?? $case_profile['workflow'] ?? '' ) );

		if ( '' === $workflow ) {
			return array();
		}

		$issue = trim( (string) ( $actions['issue'] ?? $facts['issue'] ?? 'divorce' ) );
		$county = trim( (string) ( $facts['county'] ?? $session['county'] ?? '' ) );

		$result = $this->navigator->navigate(
			array(
				'issue'         => $issue,
				'facts'         => $facts,
				'workflow'      => $workflow,
				'county'        => $county,
				'current_node'  => (string) ( $current_node['slug'] ?? '' ),
			)
		);

		if ( empty( $result['success'] ) ) {
			return array();
		}

		return is_array( $result['navigation'] ?? null ) ? $result['navigation'] : array();
	}

	/**
	 * Build a procedural card when the user asks a procedural question after intake.
	 *
	 * @param array<string, mixed> $session      Session.
	 * @param array<string, mixed> $context      Mapped context.
	 * @param string               $user_message User message.
	 * @return array<string, mixed>|null
	 */
	private function build_procedural_card( array $session, array $context, string $user_message ): ?array {
		unset( $session );

		if ( ! $this->is_procedural_question( $user_message ) ) {
			return null;
		}

		if ( empty( $context['requirements']['ready_to_generate'] ) && empty( $context['actions']['intake_complete'] ) ) {
			return null;
		}

		$procedural = is_array( $context['procedural'] ?? null ) ? $context['procedural'] : array();
		$next_steps = is_array( $procedural['next_steps'] ?? null ) ? $procedural['next_steps'] : array();

		if ( empty( $next_steps ) ) {
			return null;
		}

		$current = $next_steps[0];

		foreach ( $next_steps as $step ) {
			if ( ! empty( $step['current'] ) ) {
				$current = $step;
				break;
			}
		}

		$lines   = array();
		$court   = is_array( $procedural['court'] ?? null ) ? $procedural['court'] : array();
		$forms   = is_array( $procedural['forms'] ?? null ) ? $procedural['forms'] : array();

		if ( ! empty( $court['label'] ) ) {
			$lines[] = sprintf(
				/* translators: %s: court name. */
				__( 'Court: %s', 'prose-core' ),
				(string) $court['label']
			);
		}

		$lines[] = (string) ( $current['title'] ?? '' );

		if ( ! empty( $forms ) ) {
			$lines[] = sprintf(
				/* translators: %s: comma-separated form codes. */
				__( 'Related forms: %s', 'prose-core' ),
				implode( ', ', array_slice( $forms, 0, 5 ) )
			);
		}

		return array(
			'type'    => 'procedure',
			'eyebrow' => __( 'What happens next', 'prose-core' ),
			'title'   => (string) ( $current['title'] ?? __( 'Next procedural step', 'prose-core' ) ),
			'text'    => implode( "\n", array_filter( $lines ) ),
			'steps'   => $next_steps,
		);
	}

	/**
	 * Whether the user is asking a procedural (not strategy) question.
	 *
	 * @param string $message User message.
	 * @return bool
	 */
	private function is_procedural_question( string $message ): bool {
		$text = strtolower( trim( $message ) );

		if ( '' === $text ) {
			return false;
		}

		$phrases = array(
			'how do i file',
			'how to file',
			'what happens next',
			'what do i do next',
			'next step',
			'next steps',
			'after i file',
			'where do i file',
			'where to file',
			'how do i serve',
			'how to serve',
			'serve papers',
			'service of process',
			'deadline',
			'what is ud-1',
			'what is ud-',
			'what form',
			'explain the form',
		);

		foreach ( $phrases as $phrase ) {
			if ( str_contains( $text, $phrase ) ) {
				return true;
			}
		}

		return false;
	}
}
