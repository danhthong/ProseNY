<?php
/**
 * Stage transition guidance — ChatGPT instructions after "I completed this step".
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Ai_Intake;

use ProSe\Core\Forms\Engine\Stage_Form_Group_Presenter;
use ProSe\Core\Guidance\Filing_Guidance_Brief_Resolver;
use ProSe\Core\Guidance\Guidance_Repository;
use ProSe\Core\Intake\Case_Summary_Presenter;
use ProSe\Core\Intake\Case_Stage_Integrity;
use ProSe\Core\Intake\Completed_Stage_Document_Store;
use ProSe\Core\Routing\Workflow_Catalog;
use ProSe\Core\Routing\Workflow_Engine;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Stage_Transition_Guidance_Service
 */
final class Stage_Transition_Guidance_Service {

	/**
	 * Focused system instructions for post-stage-completion guidance.
	 */
	private const ROLE_GUIDANCE = <<<'TXT'
CASE_MANAGER_ROLE

The user just marked a procedural stage complete in CourtFlow. The Workflow Engine has already advanced their case.

Rules:
- Use ONLY the JSON context. Do not invent courts, forms, deadlines, fees, or legal strategy.
- Treat case_context.case_memory, procedural.filing_guidance_brief, procedural.stage_guidance, and procedural.workflow_summary as authoritative.
- Explain the NEW current stage (transition.current_stage), not the stage they just finished — except to acknowledge completion briefly with a progress summary.
- Cover in conversational prose (short paragraphs, blank lines between):
  1) Personalized opening from known_facts / routing_facts
  2) Why this stage matters (legal purpose in plain language)
  3) What to expect during this stage
  4) Required documents — explain each form's purpose, not just list codes
  5) Conditional documents — when they apply and why
  6) What information the user should prepare
  7) Common mistakes to avoid for this stage
  8) Clear next step action
  9) What happens after this stage (transition.next_stage / future_stages)
  10) Whether additional intake information is still needed — explain why if asking
- Tell the user they can use Get Documents in Case Actions for blank forms.
- End with a brief informational disclaimer that this is procedural guidance, not legal advice.
- Do not ask intake questions or extract facts.
- Do NOT include Case Snapshot, Stage Timeline, or Upcoming Documents — appended automatically.

Also produce a dynamic "Next Stage Checklist" derived from the workflow, completed_documents, current stage forms, and known facts — NOT hardcoded generic items. Mark items the user has likely already done (completed stages/documents) as completed: true.

Return ONLY valid JSON:
{
  "guidance": "<personalized multi-paragraph explanation>",
  "checklist": [
    {"label": "<short checklist item>", "completed": true|false}
  ]
}
TXT;

	/**
	 * @var AI_Settings
	 */
	private AI_Settings $settings;

	/**
	 * @var AI_Logger
	 */
	private AI_Logger $logger;

	/**
	 * @var Filing_Guidance_Brief_Resolver
	 */
	private Filing_Guidance_Brief_Resolver $briefs;

	/**
	 * @var Guidance_Repository
	 */
	private Guidance_Repository $guidance;

	/**
	 * @var Workflow_Catalog
	 */
	private Workflow_Catalog $workflows;

	/**
	 * @var Workflow_Engine
	 */
	private Workflow_Engine $workflow_engine;

	/**
	 * @var Case_Summary_Presenter
	 */
	private Case_Summary_Presenter $summary_presenter;

	/**
	 * @var Case_Manager_Presenter
	 */
	private Case_Manager_Presenter $case_manager;

	/**
	 * @var Ai_Provider_Interface|null
	 */
	private ?Ai_Provider_Interface $provider_override;

	/**
	 * Constructor.
	 *
	 * @param AI_Settings|null                    $settings          Settings.
	 * @param AI_Logger|null                      $logger            Logger.
	 * @param Filing_Guidance_Brief_Resolver|null $briefs            Brief resolver.
	 * @param Guidance_Repository|null            $guidance          Guidance repository.
	 * @param Workflow_Catalog|null               $workflows         Workflow catalog.
	 * @param Workflow_Engine|null                $workflow_engine   Workflow engine.
	 * @param Case_Summary_Presenter|null         $summary_presenter Summary presenter.
	 * @param Ai_Provider_Interface|null          $provider          Optional provider override (tests).
	 */
	public function __construct(
		?AI_Settings $settings = null,
		?AI_Logger $logger = null,
		?Filing_Guidance_Brief_Resolver $briefs = null,
		?Guidance_Repository $guidance = null,
		?Workflow_Catalog $workflows = null,
		?Workflow_Engine $workflow_engine = null,
		?Case_Summary_Presenter $summary_presenter = null,
		?Ai_Provider_Interface $provider = null
	) {
		$this->settings           = $settings ?? new AI_Settings();
		$this->logger             = $logger ?? new AI_Logger();
		$this->briefs             = $briefs ?? new Filing_Guidance_Brief_Resolver();
		$this->guidance           = $guidance ?? new Guidance_Repository();
		$this->workflows          = $workflows ?? new Workflow_Catalog();
		$this->workflow_engine    = $workflow_engine ?? new Workflow_Engine();
		$this->summary_presenter  = $summary_presenter ?? new Case_Summary_Presenter( $this->workflows );
		$this->provider_override  = $provider;
		$this->case_manager       = new Case_Manager_Presenter( $this->workflows );
	}

	/**
	 * Whether a live AI provider is configured.
	 *
	 * @return bool
	 */
	public function is_available(): bool {
		$api_key = trim( (string) $this->settings->get( 'api_key', '' ) );

		return '' !== $api_key;
	}

	/**
	 * Reconcile workflow state before building the AI payload.
	 *
	 * @param array<string, mixed> $completion_result Stage completion result.
	 * @param array<string, mixed> $intake_context    Session context.
	 * @return array<string, mixed>
	 */
	private function prepare_completion_result( array $completion_result ): array {
		$case_profile = is_array( $completion_result['case_profile'] ?? null ) ? $completion_result['case_profile'] : array();

		if ( empty( $case_profile ) ) {
			return $completion_result;
		}

		$integrity    = new Case_Stage_Integrity( $this->workflow_engine );
		$case_profile = $integrity->reconcile_case_profile( $case_profile, true );

		$completion_result['case_profile']    = $case_profile;
		$completion_result['procedural_node'] = (string) ( $case_profile['procedural_node'] ?? '' );

		$actions = ( new \ProSe\Core\Intake\Case_Actions_Resolver() )->resolve( $case_profile );

		$completion_result['actions']       = $actions;
		$completion_result['stage_context'] = is_array( $actions['stage_context'] ?? null ) ? $actions['stage_context'] : array();

		return $completion_result;
	}

	/**
	 * Generate next-stage guidance after procedural stage completion.
	 *
	 * @param array<string, mixed> $completion_result Output from Procedural_Stage_Completer.
	 * @param array<string, mixed> $intake_context      Optional session context (conversation, memory).
	 * @return array{guidance: string, checklist: array<int, array<string, mixed>>, ai_used: bool}
	 */
	public function generate( array $completion_result, array $intake_context = array() ): array {
		unset( $intake_context['case_memory'] );

		$completion_result = $this->prepare_completion_result( $completion_result );
		$payload           = $this->build_payload( $completion_result, $intake_context );
		$event_context     = $this->resolve_event_context( $completion_result, $intake_context );
		$payload['event_context'] = $event_context;

		$integrity  = new Case_Stage_Integrity( $this->workflow_engine );
		$validation = $integrity->validate_stage_snapshot(
			array(
				'roadmap'         => is_array( $completion_result['case_profile']['roadmap'] ?? null )
					? $completion_result['case_profile']['roadmap']
					: array(),
				'workflow_state'  => is_array( $completion_result['case_profile']['workflow_state'] ?? null )
					? $completion_result['case_profile']['workflow_state']
					: array(),
				'case_memory'     => is_array( $payload['case_context']['case_memory'] ?? null )
					? $payload['case_context']['case_memory']
					: array(),
				'stage_context'   => is_array( $completion_result['stage_context'] ?? null )
					? $completion_result['stage_context']
					: array(),
				'transition'      => is_array( $payload['transition'] ?? null ) ? $payload['transition'] : array(),
				'brief_stage'     => sanitize_key( (string) ( $payload['case_context']['brief_stage'] ?? '' ) ),
			)
		);

		if ( ! $validation['valid'] ) {
			$this->logger->log(
				array(
					'type'       => 'stage_integrity_error',
					'latency_ms' => 0,
					'prompt'     => array(
						'mismatches'      => $validation['mismatches'],
						'canonical_stage' => $validation['canonical_stage'],
						'completion'      => array(
							'completed_stage' => $completion_result['completed_stage'] ?? '',
							'procedural_node' => $completion_result['case_profile']['procedural_node'] ?? '',
						),
					),
					'response'   => '',
				)
			);

			return $this->build_deterministic_guidance( $payload, $completion_result );
		}

		if ( empty( $payload['transition']['current_stage']['id'] ?? '' ) ) {
			$fallback = trim( (string) ( $completion_result['message'] ?? '' ) );

			return array(
				'guidance'  => $fallback,
				'checklist' => array(),
				'ai_used'   => false,
			);
		}

		if ( ! $this->is_available() ) {
			return $this->build_deterministic_guidance( $payload, $completion_result );
		}

		try {
			$provider = $this->settings->make_provider( $this->provider_override );
			$messages = array(
				array(
					'role'    => 'system',
					'content' => $this->build_system_content( $event_context ),
				),
				array(
					'role'    => 'user',
					'content' => wp_json_encode( $payload ),
				),
			);

			$response = $provider->complete(
				$messages,
				array_merge(
					$this->settings->provider_options(),
					array(
						'mode'            => 'stage_transition',
						'response_format' => 'json_object',
						'max_tokens'      => 3000,
						'timeout'         => 60,
					)
				)
			);

			$this->logger->log(
				array(
					'type'       => 'stage_transition',
					'latency_ms' => (int) ( $response['latency_ms'] ?? 0 ),
					'prompt'     => $messages,
					'response'   => (string) ( $response['content'] ?? '' ),
				)
			);

			$this->settings->record_request(
				array(
					'type'       => 'stage_transition',
					'latency_ms' => (int) ( $response['latency_ms'] ?? 0 ),
					'provider'   => $provider->name(),
				)
			);

			$parsed = $this->decode_response( (string) ( $response['content'] ?? '' ) );

			if ( '' === trim( (string) ( $parsed['guidance'] ?? '' ) ) ) {
				return $this->build_deterministic_guidance( $payload, $completion_result );
			}

			$checklist = is_array( $parsed['checklist'] ?? null ) ? $parsed['checklist'] : array();

			return array(
				'guidance'  => $this->format_guidance_response( $parsed, $payload ),
				'checklist' => $checklist,
				'ai_used'   => true,
			);
		} catch ( \Throwable $e ) {
			$this->settings->record_error( $e->getMessage() );

			return $this->build_deterministic_guidance( $payload, $completion_result );
		}
	}

	/**
	 * Rebuild stage-transition assistant guidance for conversation restore (no AI call).
	 *
	 * @param array<string, mixed> $case_profile      Current case profile snapshot.
	 * @param int                  $transition_index  Zero-based completed stage index.
	 * @return string
	 */
	public function restored_transition_guidance( array $case_profile, int $transition_index ): string {
		$workflow = trim( (string) ( $case_profile['workflow'] ?? '' ) );

		if ( '' === $workflow || $transition_index < 0 ) {
			return '';
		}

		$facts         = is_array( $case_profile['facts'] ?? null ) ? $case_profile['facts'] : array();
		$required_defs = $this->required_field_defs( $workflow );
		$stages        = ( new \ProSe\Core\Forms\Engine\Workflow_Progression_Service() )->get_stages( $workflow, $facts );

		if ( $transition_index >= count( $stages ) - 1 ) {
			return '';
		}

		$completed_stage = sanitize_key( (string) $stages[ $transition_index ] );
		$restored        = $this->profile_at_completed_count( $case_profile, $transition_index + 1, $stages );
		$actions         = ( new \ProSe\Core\Intake\Case_Actions_Resolver() )->resolve( $restored );
		$stage_context   = is_array( $actions['stage_context'] ?? null ) ? $actions['stage_context'] : array();

		$completion_result = array(
			'advanced'        => true,
			'completed_stage' => $completed_stage,
			'case_profile'    => $restored,
			'stage_context'   => $stage_context,
			'actions'         => $actions,
		);

		$completion_result = $this->prepare_completion_result( $completion_result );
		$payload           = $this->build_payload( $completion_result, array() );
		$result            = $this->build_deterministic_guidance( $payload, $completion_result );

		return trim( (string) ( $result['guidance'] ?? '' ) );
	}

	/**
	 * @param array<string, mixed> $case_profile     Case profile snapshot.
	 * @param int                  $completed_count  Number of completed stages.
	 * @param string[]             $stages           Ordered stage slugs.
	 * @return array<string, mixed>
	 */
	private function profile_at_completed_count( array $case_profile, int $completed_count, array $stages ): array {
		$allowed = array_flip( array_map( 'sanitize_key', array_slice( $stages, 0, max( 0, $completed_count ) ) ) );
		$profile = $case_profile;

		$profile['completed_documents'] = array_values(
			array_filter(
				Completed_Stage_Document_Store::entries_from_profile( $case_profile ),
				static function ( $entry ) use ( $allowed ): bool {
					if ( ! is_array( $entry ) ) {
						return false;
					}

					$stage_id = sanitize_key( (string) ( $entry['stage_id'] ?? '' ) );

					return '' !== $stage_id && isset( $allowed[ $stage_id ] );
				}
			)
		);

		$workflow     = trim( (string) ( $case_profile['workflow'] ?? '' ) );
		$facts        = is_array( $case_profile['facts'] ?? null ) ? $case_profile['facts'] : array();
		$required     = $this->required_field_defs( $workflow );
		$stored_node  = trim( (string) ( $case_profile['procedural_node'] ?? '' ) );
		$workflow_state = $this->workflow_engine->resolve_state(
			$workflow,
			$facts,
			$stored_node,
			$required,
			$completed_count
		);

		$profile['procedural_node']  = (string) ( $workflow_state['procedural_node'] ?? $stored_node );
		$profile['workflow_state']   = $workflow_state;
		$profile['progress']       = (int) ( $case_profile['progress'] ?? 0 );

		return ( new Case_Stage_Integrity( $this->workflow_engine ) )->reconcile_case_profile( $profile, true );
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

	/**
	 * Append guidance text only when it is not already present (normalized).
	 *
	 * @param array<int, string> $parts Guidance paragraphs.
	 * @param string             $text  Candidate paragraph.
	 */
	private function append_unique_guidance_part( array &$parts, string $text ): void {
		$text = trim( $text );

		if ( '' === $text ) {
			return;
		}

		$needle = strtolower( preg_replace( '/\s+/', ' ', $text ) );

		foreach ( $parts as $existing ) {
			if ( strtolower( preg_replace( '/\s+/', ' ', (string) $existing ) ) === $needle ) {
				return;
			}
		}

		$parts[] = $text;
	}

	/**
	 * Rich procedural guidance assembled from workflow data when ChatGPT is unavailable.
	 *
	 * @param array<string, mixed> $payload           Structured case payload.
	 * @param array<string, mixed> $completion_result Stage completion result.
	 * @return array{guidance: string, checklist: array<int, array<string, mixed>>, ai_used: bool}
	 */
	private function build_deterministic_guidance( array $payload, array $completion_result ): array {
		$advanced        = ! empty( $completion_result['advanced'] );
		$completed_title = trim( (string) ( $payload['transition']['completed_stage']['title'] ?? '' ) );
		$current         = is_array( $payload['transition']['current_stage'] ?? null ) ? $payload['transition']['current_stage'] : array();
		$current_title   = trim( (string) ( $current['title'] ?? '' ) );
		$facts           = is_array( $payload['case_context']['routing_facts'] ?? null ) ? $payload['case_context']['routing_facts'] : array();
		$parts           = array();

		if ( $advanced && '' !== $completed_title && '' !== $current_title ) {
			/* translators: 1: completed stage title, 2: new current stage title. */
			$parts[] = sprintf(
				__( 'You\'ve successfully completed the %1$s stage. The next step is %2$s.', 'prose-core' ),
				$completed_title,
				$current_title
			);
		} elseif ( '' !== $current_title ) {
			/* translators: %s: current procedural stage title. */
			$parts[] = sprintf( __( 'You are now at the %s stage.', 'prose-core' ), $current_title );
		}

		$personal = $this->personalized_fact_paragraph( $facts );

		if ( '' !== $personal ) {
			$this->append_unique_guidance_part( $parts, $personal );
		}

		$description = trim( (string) ( $current['description'] ?? '' ) );

		if ( '' !== $description ) {
			$this->append_unique_guidance_part( $parts, $description );
		}

		$stage_guidance = is_array( $payload['procedural']['stage_guidance'] ?? null ) ? $payload['procedural']['stage_guidance'] : array();
		$overview       = trim( (string) ( $stage_guidance['overview'] ?? $stage_guidance['summary'] ?? '' ) );

		if ( '' !== $overview ) {
			$this->append_unique_guidance_part( $parts, $overview );
		}

		$brief = is_array( $payload['procedural']['filing_guidance_brief'] ?? null ) ? $payload['procedural']['filing_guidance_brief'] : array();

		foreach ( (array) ( $brief['steps'] ?? array() ) as $step ) {
			if ( ! is_array( $step ) ) {
				continue;
			}

			$text = trim( (string) ( $step['text'] ?? $step['description'] ?? '' ) );

			if ( '' !== $text ) {
				$this->append_unique_guidance_part( $parts, $text );
			}
		}

		$required = (array) ( $payload['procedural']['required_forms'] ?? array() );

		if ( ! empty( $required ) ) {
			$form_lines = array( __( 'For this stage, the primary documents include:', 'prose-core' ) );

			foreach ( $required as $form ) {
				if ( ! is_array( $form ) ) {
					continue;
				}

				$code    = trim( (string) ( $form['code'] ?? '' ) );
				$title   = trim( (string) ( $form['title'] ?? $code ) );
				$purpose = trim( (string) ( $form['purpose'] ?? '' ) );
				$line    = '' !== $code ? $code . ' — ' . $title : $title;

				if ( '' !== $purpose ) {
					$line .= '. ' . $purpose;
				}

				if ( '' !== trim( $line ) ) {
					$form_lines[] = $line;
				}
			}

			$parts[] = implode( "\n", $form_lines );
		}

		$conditional = (array) ( $payload['procedural']['conditional_forms'] ?? array() );

		if ( ! empty( $conditional ) ) {
			$parts[] = __( 'Depending on your circumstances, additional forms may also apply:', 'prose-core' );

			foreach ( $conditional as $form ) {
				if ( ! is_array( $form ) ) {
					continue;
				}

				$code   = trim( (string) ( $form['code'] ?? '' ) );
				$reason = trim( (string) ( $form['reason'] ?? '' ) );
				$line   = '' !== $code ? $code : trim( (string) ( $form['title'] ?? '' ) );

				if ( '' !== $reason ) {
					$line .= ' — ' . $reason;
				}

				if ( '' !== $line ) {
					$parts[] = $line;
				}
			}
		}

		$next_action = trim( (string) ( $current['next_action'] ?? '' ) );

		if ( '' !== $next_action ) {
			$this->append_unique_guidance_part( $parts, $next_action );
		}

		$next_stage = is_array( $payload['transition']['next_stage'] ?? null ) ? $payload['transition']['next_stage'] : null;

		if ( is_array( $next_stage ) && ! empty( $next_stage['title'] ?? '' ) ) {
			/* translators: %s: next procedural stage title. */
			$parts[] = sprintf(
				__( 'Once this stage is complete, you\'ll move to %s.', 'prose-core' ),
				(string) $next_stage['title']
			);
		}

		$parts[] = __( 'Use Get Documents in Case Actions for blank forms.', 'prose-core' );
		$parts[] = __( 'This is informational guidance only, not legal advice.', 'prose-core' );

		$checklist = $this->build_deterministic_checklist( $payload, $completion_result );

		return array(
			'guidance'  => $this->format_guidance_response(
				array(
					'guidance'  => implode( "\n\n", array_filter( $parts ) ),
					'checklist' => $checklist,
				),
				$payload
			),
			'checklist' => $checklist,
			'ai_used'   => false,
		);
	}

	/**
	 * @param array<string, mixed> $facts Routing facts.
	 * @return string
	 */
	private function personalized_fact_paragraph( array $facts ): string {
		if ( empty( $facts ) ) {
			return '';
		}

		$lines = array();

		if ( array_key_exists( 'spouse_agrees', $facts ) ) {
			$lines[] = ! empty( $facts['spouse_agrees'] )
				? __( 'your spouse agrees to the divorce', 'prose-core' )
				: __( 'your spouse does not agree to the divorce', 'prose-core' );
		}

		if ( array_key_exists( 'children', $facts ) ) {
			$lines[] = ! empty( $facts['children'] )
				? __( 'you have children under 21', 'prose-core' )
				: __( 'there are no children under 21', 'prose-core' );
		}

		if ( ! empty( $facts['county'] ) ) {
			/* translators: %s: county name. */
			$lines[] = sprintf( __( 'your matter is in %s County', 'prose-core' ), (string) $facts['county'] );
		}

		if ( empty( $lines ) ) {
			return '';
		}

		return __( 'Based on what you\'ve told me so far:', 'prose-core' ) . ' ' . implode( '; ', $lines ) . '.';
	}

	/**
	 * @param array<string, mixed> $payload           Structured payload.
	 * @param array<string, mixed> $completion_result Completion result.
	 * @return array<int, array<string, mixed>>
	 */
	private function build_deterministic_checklist( array $payload, array $completion_result ): array {
		$checklist           = array();
		$seen_completed_stage = array();

		foreach ( (array) ( $payload['case_context']['completed_documents'] ?? array() ) as $doc ) {
			if ( ! is_array( $doc ) ) {
				continue;
			}

			$stage_id    = sanitize_key( (string) ( $doc['stage_id'] ?? '' ) );
			$stage_title = trim( (string) ( $doc['stage_title'] ?? $doc['stage_id'] ?? '' ) );
			$dedupe_key  = '' !== $stage_id ? $stage_id : strtolower( $stage_title );

			if ( '' === $dedupe_key || isset( $seen_completed_stage[ $dedupe_key ] ) ) {
				continue;
			}

			$seen_completed_stage[ $dedupe_key ] = true;

			if ( '' !== $stage_title ) {
				/* translators: %s: completed procedural stage title. */
				$checklist[] = array(
					'label'     => sprintf( __( 'Finished documents for %s', 'prose-core' ), $stage_title ),
					'completed' => true,
				);
			}
		}

		if ( ! empty( $completion_result['advanced'] ) ) {
			$completed_title = trim( (string) ( $payload['transition']['completed_stage']['title'] ?? '' ) );

			if ( '' !== $completed_title ) {
				/* translators: %s: completed stage title. */
				$checklist[] = array(
					'label'     => sprintf( __( 'Marked %s as complete', 'prose-core' ), $completed_title ),
					'completed' => true,
				);
			}
		}

		foreach ( (array) ( $payload['procedural']['required_forms'] ?? array() ) as $form ) {
			if ( ! is_array( $form ) ) {
				continue;
			}

			$code  = trim( (string) ( $form['code'] ?? '' ) );
			$title = trim( (string) ( $form['title'] ?? $code ) );

			if ( '' === $code && '' === $title ) {
				continue;
			}

			/* translators: 1: form code, 2: form title. */
			$checklist[] = array(
				'label'     => sprintf( __( 'Prepare %1$s (%2$s)', 'prose-core' ), $code, $title ),
				'completed' => false,
			);
		}

		$brief = is_array( $payload['procedural']['filing_guidance_brief'] ?? null ) ? $payload['procedural']['filing_guidance_brief'] : array();

		foreach ( (array) ( $brief['checklist'] ?? array() ) as $item ) {
			$label = is_array( $item )
				? trim( (string) ( $item['label'] ?? $item['text'] ?? '' ) )
				: trim( (string) $item );

			if ( '' === $label ) {
				continue;
			}

			$checklist[] = array(
				'label'     => $label,
				'completed' => is_array( $item ) ? ! empty( $item['completed'] ) : false,
			);
		}

		return $checklist;
	}

	/**
	 * @param array<string, mixed> $completion_result Completion result.
	 * @param array<string, mixed> $intake_context    Session context.
	 * @return array<string, mixed>
	 */
	private function build_payload( array $completion_result, array $intake_context = array() ): array {
		$case_profile    = is_array( $completion_result['case_profile'] ?? null ) ? $completion_result['case_profile'] : array();
		$actions         = is_array( $completion_result['actions'] ?? null ) ? $completion_result['actions'] : array();
		$stage_context   = is_array( $completion_result['stage_context'] ?? null )
			? $completion_result['stage_context']
			: ( is_array( $actions['stage_context'] ?? null ) ? $actions['stage_context'] : array() );
		$workflow        = trim( (string) ( $case_profile['workflow'] ?? $actions['workflow'] ?? '' ) );
		$facts           = is_array( $case_profile['facts'] ?? null ) ? $case_profile['facts'] : array();
		$completed_stage = sanitize_key( (string) ( $completion_result['completed_stage'] ?? '' ) );
		$completed_title = $this->stage_title( $completed_stage );
		$current_stage   = is_array( $stage_context['current_stage'] ?? null ) ? $stage_context['current_stage'] : array();
		$current_id      = sanitize_key( (string) ( $current_stage['id'] ?? '' ) );
		$roadmap         = is_array( $case_profile['roadmap'] ?? null ) ? $case_profile['roadmap'] : array();
		$procedural_node = trim( (string) ( $case_profile['procedural_node'] ?? $stage_context['procedural_node'] ?? '' ) );
		$workflow_state  = is_array( $case_profile['workflow_state'] ?? null ) ? $case_profile['workflow_state'] : array();

		$intake = Intake_State::from_array( array() );
		$intake->import_case_profile( $case_profile );

		if ( '' !== $workflow ) {
			$intake->set_workflow( $workflow );
		}

		$missing_payload = $this->workflow_engine->get_missing_facts( $intake, '' );
		$case_memory     = Case_Memory::build(
			$intake,
			$missing_payload,
			$stage_context,
			1.0,
			$this->completed_form_codes( $case_profile ),
			is_array( $workflow_state ) ? $workflow_state : array()
		);

		$summary_structured = $this->summary_presenter->build(
			array(
				'workflow'        => $workflow,
				'facts'           => $facts,
				'stage_context'   => $stage_context,
				'roadmap'         => $roadmap,
				'court'           => trim( (string) ( $case_profile['court'] ?? $actions['court_routing']['court'] ?? '' ) ),
				'issue'           => trim( (string) ( $case_profile['issue'] ?? $actions['issue'] ?? '' ) ),
				'procedural_node' => $procedural_node,
				'completion'      => (int) ( $missing_payload['completion'] ?? ( $case_profile['progress'] ?? 0 ) ),
			)
		);

		$brief = $this->briefs->resolve(
			array(
				'workflow' => $workflow,
				'facts'    => $facts,
				'stage'    => '' !== $current_id ? $current_id : 'commencement',
				'county'   => (string) ( $facts['county'] ?? '' ),
			)
		);
		$brief_stage = '' !== $current_id ? $current_id : 'commencement';

		$form_groups = is_array( $stage_context['form_groups'] ?? null ) ? $stage_context['form_groups'] : array();
		$groups_text = ( new Stage_Form_Group_Presenter() )->format_groups_text( $form_groups );
		$stage_guidance = $this->guidance->read_stage( $current_id );

		return array(
			'task'          => 'stage_transition_guidance',
			'event'         => array(
				'user_action'     => 'marked_stage_complete',
				'advanced'        => ! empty( $completion_result['advanced'] ),
				'completed_stage' => array(
					'id'    => $completed_stage,
					'title' => $completed_title,
				),
			),
			'case_context'  => array(
				'summary'               => $summary_structured,
				'summary_text'          => $this->summary_presenter->to_prompt_text( $summary_structured ),
				'case_memory'           => $case_memory,
				'brief_stage'           => $brief_stage,
				'known_facts'           => $facts,
				'routing_facts'         => $this->routing_facts( $facts ),
				'missing_facts'         => array(
					'conversation' => is_array( $missing_payload['conversation'] ?? null ) ? $missing_payload['conversation'] : array(),
					'internal'     => is_array( $case_memory['internal_missing'] ?? null ) ? $case_memory['internal_missing'] : array(),
				),
				'progress'              => (int) ( $missing_payload['completion'] ?? ( $case_profile['progress'] ?? 0 ) ),
				'conversation_summary'  => trim( (string) ( $intake_context['conversation_summary'] ?? $case_profile['conversation_summary'] ?? '' ) ),
				'conversation_tail'     => $this->compact_conversation( $intake_context ),
				'completed_documents'   => $this->compact_completed_documents( $case_profile ),
				'procedural_node'       => $procedural_node,
				'workflow_state'        => $workflow_state,
				'package_label'         => trim( (string) ( $actions['package_label'] ?? '' ) ),
				'court_routing'         => is_array( $actions['court_routing'] ?? null ) ? $actions['court_routing'] : array(),
				'actions_summary'       => $this->compact_summary( $actions ),
			),
			'transition'    => array(
				'completed_stage' => array(
					'id'    => $completed_stage,
					'title' => $completed_title,
				),
				'current_stage'   => array(
					'id'          => $current_id,
					'title'       => (string) ( $current_stage['title'] ?? '' ),
					'description' => (string) ( $current_stage['description'] ?? '' ),
					'next_action' => trim( (string) ( $stage_context['next_action']['message'] ?? '' ) ),
				),
				'next_stage'      => $this->resolve_next_stage( $stage_context, $case_memory ),
			),
			'procedural'    => array(
				'required_forms'        => $this->compact_required_forms( $stage_context ),
				'conditional_forms'     => $this->compact_conditional_forms( $stage_context ),
				'form_groups'           => $form_groups,
				'form_groups_text'      => $groups_text,
				'download_options'      => $this->compact_download_options( $stage_context ),
				'future_stages'         => $this->compact_future_stages( $stage_context ),
				'filing_guidance_brief' => is_array( $brief ) ? $brief : null,
				'stage_guidance'        => is_array( $stage_guidance ) ? $stage_guidance : null,
				'workflow_summary'      => $this->compact_workflow_definition( $workflow ),
				'roadmap'               => array(
					'next_likely_step'   => is_array( $roadmap['next_likely_step'] ?? null ) ? $roadmap['next_likely_step'] : null,
					'suggested_question' => trim( (string) ( $roadmap['suggested_next_question'] ?? '' ) ),
					'full'               => $roadmap,
				),
			),
		);
	}

	/**
	 * @param array<string, mixed> $parsed Decoded model response.
	 * @return string
	 */
	private function format_guidance_response( array $parsed, array $payload = array() ): string {
		$guidance  = trim( (string) ( $parsed['guidance'] ?? $parsed['conversation_reply'] ?? '' ) );
		$checklist = is_array( $parsed['checklist'] ?? null ) ? $parsed['checklist'] : array();

		if ( ! empty( $checklist ) ) {
			$lines = array(
				__( 'Before continuing, please make sure you have completed:', 'prose-core' ),
			);

			foreach ( $checklist as $item ) {
				if ( ! is_array( $item ) ) {
					continue;
				}

				$label = trim( (string) ( $item['label'] ?? $item['item'] ?? '' ) );

				if ( '' === $label ) {
					continue;
				}

				$done    = ! empty( $item['completed'] ) || ! empty( $item['done'] );
				$lines[] = ( $done ? "\u{2713} " : "\u{25A1} " ) . $label;
			}

			if ( count( $lines ) > 1 ) {
				$guidance = $guidance . "\n\n" . implode( "\n", $lines );
			}
		}

		if ( empty( $payload ) ) {
			return $guidance;
		}

		$case_context = is_array( $payload['case_context'] ?? null ) ? $payload['case_context'] : array();
		$transition   = is_array( $payload['transition'] ?? null ) ? $payload['transition'] : array();
		$current      = is_array( $transition['current_stage'] ?? null ) ? $transition['current_stage'] : array();
		$workflow     = trim( (string) ( $case_context['summary']['workflow'] ?? '' ) );
		$facts        = is_array( $case_context['known_facts'] ?? null ) ? $case_context['known_facts'] : array();
		$stage_context = array(
			'current_stage' => $current,
			'future_stages' => is_array( $payload['procedural']['future_stages'] ?? null ) ? $payload['procedural']['future_stages'] : array(),
		);

		return $this->case_manager->append_sections(
			$guidance,
			array(
				'message'       => 'stage_complete',
				'case_summary'  => is_array( $case_context['summary'] ?? null ) ? $case_context['summary'] : array(),
				'case_memory'   => is_array( $case_context['case_memory'] ?? null ) ? $case_context['case_memory'] : array(),
				'stage_context' => $stage_context,
				'workflow'      => $workflow,
				'facts'         => $facts,
				'raw_confidence' => 1.0,
			)
		);
	}

	/**
	 * @return string
	 */
	private function role_guidance(): string {
		return str_replace(
			'CASE_MANAGER_ROLE',
			Case_Manager_Presenter::ROLE_INSTRUCTIONS,
			self::ROLE_GUIDANCE
		);
	}

	/**
	 * @param array{type?: string, meta?: array<string, mixed>} $event_context Event context.
	 * @return string
	 */
	private function build_system_content( array $event_context ): string {
		return $this->settings->system_prompt()
			. "\n\n"
			. $this->role_guidance()
			. "\n\n"
			. Ai_Event_Context::system_block( $event_context );
	}

	/**
	 * @param array<string, mixed> $completion_result Completion result.
	 * @param array<string, mixed> $intake_context    Session context.
	 * @return array{type: string, meta: array<string, mixed>}
	 */
	private function resolve_event_context( array $completion_result, array $intake_context ): array {
		if ( isset( $completion_result['ai_event'] ) ) {
			return Ai_Event_Context::normalize( $completion_result['ai_event'] );
		}

		if ( isset( $intake_context['ai_event'] ) ) {
			return Ai_Event_Context::normalize( $intake_context['ai_event'] );
		}

		$completed_stage = sanitize_key( (string) ( $completion_result['completed_stage'] ?? '' ) );

		if ( ! empty( $completion_result['advanced'] ) ) {
			return Ai_Event_Context::build(
				Ai_Event_Context::TYPE_STAGE_TRANSITION,
				array(
					'completed_stage' => $completed_stage,
					'source'          => 'stage_completer',
				)
			);
		}

		return Ai_Event_Context::build(
			Ai_Event_Context::TYPE_COMPLETION_CONFIRMATION,
			array(
				'completed_stage' => $completed_stage,
			)
		);
	}

	/**
	 * @param string $content Raw model output.
	 * @return array<string, mixed>
	 */
	private function decode_response( string $content ): array {
		$content = trim( $content );

		if ( '' === $content ) {
			return array();
		}

		if ( preg_match( '/```(?:json)?\s*(\{.*\})\s*```/s', $content, $matches ) ) {
			$content = $matches[1];
		} elseif ( preg_match( '/(\{.*\})/s', $content, $matches ) ) {
			$content = $matches[1];
		}

		$parsed = json_decode( $content, true );

		return is_array( $parsed ) ? $parsed : array();
	}

	/**
	 * @param array<string, mixed> $case_profile Case profile.
	 * @return array<int, string>
	 */
	private function completed_form_codes( array $case_profile ): array {
		$codes = array();

		foreach ( Completed_Stage_Document_Store::entries_from_profile( $case_profile ) as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}

			foreach ( (array) ( $entry['form_codes'] ?? array() ) as $code ) {
				$code = strtoupper( trim( (string) $code ) );

				if ( '' !== $code ) {
					$codes[] = $code;
				}
			}
		}

		return array_values( array_unique( $codes ) );
	}

	/**
	 * @param array<string, mixed> $case_profile Case profile.
	 * @return array<int, array<string, mixed>>
	 */
	private function compact_completed_documents( array $case_profile ): array {
		$rows = array();

		foreach ( Completed_Stage_Document_Store::entries_from_profile( $case_profile ) as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}

			$rows[] = array(
				'stage_id'     => sanitize_key( (string) ( $entry['stage_id'] ?? '' ) ),
				'stage_title'  => trim( (string) ( $entry['stage_title'] ?? '' ) ),
				'form_codes'   => array_values( array_filter( (array) ( $entry['form_codes'] ?? array() ) ) ),
				'completed_at' => trim( (string) ( $entry['completed_at'] ?? '' ) ),
			);
		}

		return $rows;
	}

	/**
	 * @param array<string, mixed> $intake_context Intake context.
	 * @return array<int, array<string, string>>
	 */
	private function compact_conversation( array $intake_context ): array {
		$tail = $intake_context['conversation_tail'] ?? $intake_context['conversation'] ?? array();

		if ( ! is_array( $tail ) ) {
			return array();
		}

		$rows  = array();
		$slice = array_slice( $tail, -8 );

		foreach ( $slice as $turn ) {
			if ( ! is_array( $turn ) ) {
				continue;
			}

			$role    = (string) ( $turn['role'] ?? '' );
			$content = trim( (string) ( $turn['content'] ?? '' ) );

			if ( '' === $content || ! in_array( $role, array( 'user', 'assistant' ), true ) ) {
				continue;
			}

			if ( 'user' === $role && str_starts_with( $content, 'I just marked a procedural stage as complete in CourtFlow.' ) ) {
				continue;
			}

			$rows[] = array(
				'role'    => $role,
				'content' => $content,
			);
		}

		return $rows;
	}

	/**
	 * @param array<string, mixed> $stage_context Stage context.
	 * @param array<string, mixed> $case_memory   Case memory.
	 * @return array<string, mixed>|null
	 */
	private function resolve_next_stage( array $stage_context, array $case_memory ): ?array {
		$next = $case_memory['next_stage'] ?? null;

		if ( is_array( $next ) && ! empty( $next['id'] ?? $next['title'] ?? '' ) ) {
			return $next;
		}

		$future = (array) ( $stage_context['future_stages'] ?? array() );

		if ( ! empty( $future[0] ) && is_array( $future[0] ) ) {
			return array(
				'id'    => sanitize_key( (string) ( $future[0]['id'] ?? '' ) ),
				'title' => trim( (string) ( $future[0]['title'] ?? '' ) ),
			);
		}

		return null;
	}

	/**
	 * @param string $workflow Workflow key.
	 * @return array<string, mixed>|null
	 */
	private function compact_workflow_definition( string $workflow ): ?array {
		if ( '' === $workflow ) {
			return null;
		}

		$definition = $this->workflows->by_key( $workflow );

		if ( ! is_array( $definition ) ) {
			return null;
		}

		$stages = array();

		foreach ( (array) ( $definition['stages'] ?? array() ) as $stage ) {
			if ( ! is_array( $stage ) ) {
				continue;
			}

			$id = sanitize_key( (string) ( $stage['id'] ?? $stage['stage'] ?? '' ) );

			if ( '' === $id ) {
				continue;
			}

			$stages[] = array(
				'id'    => $id,
				'title' => trim( (string) ( $stage['title'] ?? $stage['name'] ?? '' ) ),
			);
		}

		return array(
			'key'         => $workflow,
			'title'       => trim( (string) ( $definition['title'] ?? $definition['name'] ?? $definition['description'] ?? '' ) ),
			'description' => trim( (string) ( $definition['description'] ?? '' ) ),
			'court'       => trim( (string) ( $definition['court'] ?? '' ) ),
			'issue_type'  => trim( (string) ( $definition['issue_type'] ?? $definition['workflow_category'] ?? '' ) ),
			'stages'      => $stages,
			'conditions'  => is_array( $definition['conditions'] ?? null ) ? $definition['conditions'] : array(),
		);
	}

	/**
	 * @param array<string, mixed> $facts Plain facts.
	 * @return array<string, mixed>
	 */
	private function routing_facts( array $facts ): array {
		$keys = array(
			'county',
			'children',
			'child_count',
			'spouse_agrees',
			'marital_property_resolved',
			'active_divorce',
			'defendant_executes_affirmation',
			'religious_barrier_exists',
			'issue',
			'path',
			'spouse_responded',
			'grounds',
		);
		$out  = array();

		foreach ( $keys as $key ) {
			if ( array_key_exists( $key, $facts ) && null !== $facts[ $key ] && '' !== $facts[ $key ] ) {
				$out[ $key ] = $facts[ $key ];
			}
		}

		return $out;
	}

	/**
	 * @param array<string, mixed> $stage_context Stage context.
	 * @return array<int, array<string, mixed>>
	 */
	private function compact_required_forms( array $stage_context ): array {
		$rows = array();

		foreach ( (array) ( $stage_context['stage_forms'] ?? array() ) as $form ) {
			if ( ! is_array( $form ) ) {
				continue;
			}

			$code = trim( (string) ( $form['code'] ?? '' ) );

			if ( '' === $code ) {
				continue;
			}

			$rows[] = array(
				'code'     => $code,
				'title'    => trim( (string) ( $form['title'] ?? $code ) ),
				'required' => ! empty( $form['required'] ),
				'purpose'  => trim( (string) ( $form['purpose'] ?? '' ) ),
			);
		}

		return $rows;
	}

	/**
	 * @param array<string, mixed> $stage_context Stage context.
	 * @return array<int, array<string, mixed>>
	 */
	private function compact_conditional_forms( array $stage_context ): array {
		$rows = array();

		foreach ( (array) ( $stage_context['pending_forms'] ?? array() ) as $form ) {
			if ( ! is_array( $form ) ) {
				continue;
			}

			$code = trim( (string) ( $form['code'] ?? '' ) );

			if ( '' === $code ) {
				continue;
			}

			$rows[] = array(
				'code'    => $code,
				'title'   => trim( (string) ( $form['title'] ?? $code ) ),
				'reason'  => trim( (string) ( $form['reason'] ?? '' ) ),
				'trigger' => trim( (string) ( $form['trigger'] ?? '' ) ),
			);
		}

		return $rows;
	}

	/**
	 * @param array<string, mixed> $stage_context Stage context.
	 * @return array<int, array<string, string>>
	 */
	private function compact_download_options( array $stage_context ): array {
		$rows = array();

		foreach ( (array) ( $stage_context['download_options'] ?? array() ) as $option ) {
			if ( ! is_array( $option ) ) {
				continue;
			}

			$label = trim( (string) ( $option['label'] ?? '' ) );

			if ( '' === $label ) {
				continue;
			}

			$rows[] = array(
				'label' => $label,
				'forms' => array_values(
					array_filter(
						array_map(
							static function ( $code ): string {
								return strtoupper( trim( (string) $code ) );
							},
							(array) ( $option['form_codes'] ?? array() )
						)
					)
				),
			);
		}

		return $rows;
	}

	/**
	 * @param array<string, mixed> $stage_context Stage context.
	 * @return array<int, array<string, string>>
	 */
	private function compact_future_stages( array $stage_context ): array {
		$rows = array();

		foreach ( (array) ( $stage_context['future_stages'] ?? array() ) as $stage ) {
			if ( ! is_array( $stage ) ) {
				continue;
			}

			$id = sanitize_key( (string) ( $stage['id'] ?? '' ) );

			if ( '' === $id ) {
				continue;
			}

			$rows[] = array(
				'id'    => $id,
				'title' => trim( (string) ( $stage['title'] ?? '' ) ),
			);
		}

		return $rows;
	}

	/**
	 * @param array<string, mixed> $actions Case actions.
	 * @return array<int, array<string, string>>
	 */
	private function compact_summary( array $actions ): array {
		$rows = array();

		foreach ( (array) ( $actions['summary'] ?? array() ) as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$label = trim( (string) ( $row['label'] ?? '' ) );
			$value = trim( (string) ( $row['value'] ?? '' ) );

			if ( '' === $label || '' === $value ) {
				continue;
			}

			$rows[] = array(
				'label' => $label,
				'value' => $value,
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
			return '';
		}

		return trim( (string) ( $definition['title'] ?? $definition['name'] ?? '' ) );
	}

	/**
	 * @param string $stage_id Stage slug.
	 * @return string
	 */
	private function stage_title( string $stage_id ): string {
		if ( '' === $stage_id ) {
			return '';
		}

		$guidance = $this->guidance->read_stage( $stage_id );
		$title    = is_array( $guidance ) ? trim( (string) ( $guidance['title'] ?? '' ) ) : '';

		if ( '' !== $title ) {
			return $title;
		}

		return ucwords( str_replace( '_', ' ', $stage_id ) );
	}
}
