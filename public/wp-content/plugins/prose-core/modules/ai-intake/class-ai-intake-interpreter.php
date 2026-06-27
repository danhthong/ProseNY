<?php
/**
 * AI Intake Interpreter — orchestrates conversational intake.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Ai_Intake;

use ProSe\Core\Guidance\Filing_Guidance_Brief_Resolver;
use ProSe\Core\Guidance\Procedural_Roadmap_Presenter;
use ProSe\Core\Intake\Case_Summary_Presenter;
use ProSe\Core\Intake\Completion_Calculator;
use ProSe\Core\Intake\Procedural_State_Inferrer;
use ProSe\Core\Intake\Document_Request_Detector;
use ProSe\Core\Procedural\Procedural_Navigator;
use ProSe\Core\Search\Knowledge_Context_Provider;
use ProSe\Core\Users\User_Intake_Context;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class AI_Intake_Interpreter
 */
final class AI_Intake_Interpreter {

	/**
	 * AI provider.
	 *
	 * @var Ai_Provider_Interface
	 */
	private Ai_Provider_Interface $provider;

	/**
	 * Fact extractor.
	 *
	 * @var Fact_Extractor
	 */
	private Fact_Extractor $extractor;

	/**
	 * Required fields provider.
	 *
	 * @var Required_Fields_Provider
	 */
	private Required_Fields_Provider $fields_provider;

	/**
	 * Completion calculator.
	 *
	 * @var Completion_Calculator
	 */
	private Completion_Calculator $completion;

	/**
	 * Consistency checker.
	 *
	 * @var Consistency_Checker
	 */
	private Consistency_Checker $consistency;

	/**
	 * Clarification engine.
	 *
	 * @var Clarification_Engine
	 */
	private Clarification_Engine $clarification;

	/**
	 * Conversation memory.
	 *
	 * @var Conversation_Memory
	 */
	private Conversation_Memory $memory;

	/**
	 * Escalation detector.
	 *
	 * @var Escalation_Detector
	 */
	private Escalation_Detector $escalation;

	/**
	 * AI settings.
	 *
	 * @var AI_Settings
	 */
	private AI_Settings $settings;

	/**
	 * AI logger.
	 *
	 * @var AI_Logger
	 */
	private AI_Logger $logger;

	/**
	 * Conversation engine (single-call extract + reply).
	 *
	 * @var Conversation_Engine
	 */
	private Conversation_Engine $engine;

	/**
	 * Workflow catalog (for guidance context).
	 *
	 * @var \ProSe\Core\Routing\Workflow_Catalog
	 */
	private \ProSe\Core\Routing\Workflow_Catalog $workflows;

	/**
	 * Document request detector.
	 *
	 * @var Document_Request_Detector
	 */
	private Document_Request_Detector $documents;

	/**
	 * Procedural roadmap presenter.
	 *
	 * @var Procedural_Roadmap_Presenter
	 */
	private Procedural_Roadmap_Presenter $roadmap_presenter;

	/**
	 * Reference knowledge provider.
	 *
	 * @var Knowledge_Context_Provider
	 */
	private Knowledge_Context_Provider $knowledge_context;

	/**
	 * Procedural state inferrer.
	 *
	 * @var Procedural_State_Inferrer
	 */
	private Procedural_State_Inferrer $procedural_state;

	/**
	 * Constructor.
	 *
	 * @param Ai_Provider_Interface|null      $provider         Provider override.
	 * @param Fact_Extractor|null             $extractor        Extractor.
	 * @param Required_Fields_Provider|null   $fields_provider  Fields provider.
	 * @param Completion_Calculator|null      $completion       Completion calculator.
	 * @param Consistency_Checker|null        $consistency      Consistency checker.
	 * @param Clarification_Engine|null       $clarification    Clarification engine.
	 * @param Conversation_Memory|null        $memory           Memory.
	 * @param Escalation_Detector|null        $escalation       Escalation detector.
	 * @param AI_Settings|null                $settings         Settings.
	 * @param AI_Logger|null                  $logger           Logger.
	 * @param Conversation_Engine|null        $engine           Conversation engine.
	 * @param Document_Request_Detector|null  $documents        Document detector.
	 * @param Procedural_Roadmap_Presenter|null $roadmap_presenter Roadmap presenter.
	 * @param Knowledge_Context_Provider|null $knowledge_context Knowledge context.
	 */
	public function __construct(
		?Ai_Provider_Interface $provider = null,
		?Fact_Extractor $extractor = null,
		?Required_Fields_Provider $fields_provider = null,
		?Completion_Calculator $completion = null,
		?Consistency_Checker $consistency = null,
		?Clarification_Engine $clarification = null,
		?Conversation_Memory $memory = null,
		?Escalation_Detector $escalation = null,
		?AI_Settings $settings = null,
		?AI_Logger $logger = null,
		?Conversation_Engine $engine = null,
		?Document_Request_Detector $documents = null,
		?Procedural_Roadmap_Presenter $roadmap_presenter = null,
		?Knowledge_Context_Provider $knowledge_context = null
	) {
		$this->settings        = $settings ?? new AI_Settings();
		$this->logger          = $logger ?? new AI_Logger();
		$this->provider        = $provider ?? $this->settings->make_provider();
		$this->extractor       = $extractor ?? new Fact_Extractor( $this->settings );
		$this->fields_provider = $fields_provider ?? new Required_Fields_Provider();
		$this->completion      = $completion ?? new Completion_Calculator();
		$this->consistency     = $consistency ?? new Consistency_Checker();
		$this->clarification   = $clarification ?? new Clarification_Engine( $this->settings );
		$this->memory          = $memory ?? new Conversation_Memory( $this->settings );
		$this->escalation      = $escalation ?? new Escalation_Detector();
		$this->engine          = $engine ?? new Conversation_Engine( $this->settings, $this->extractor );
		$this->workflows       = new \ProSe\Core\Routing\Workflow_Catalog();
		$this->documents       = $documents ?? new Document_Request_Detector();
		$this->roadmap_presenter = $roadmap_presenter ?? new Procedural_Roadmap_Presenter();
		$this->knowledge_context = $knowledge_context ?? new Knowledge_Context_Provider();
		$this->procedural_state  = new Procedural_State_Inferrer();
	}

	/**
	 * Interpret one intake turn.
	 *
	 * @param string                              $message      User message.
	 * @param array<string, mixed>                $state        Intake state array.
	 * @param array<int, array<string, string>>   $conversation Conversation history.
	 * @return array<string, mixed>
	 */
	public function interpret( string $message, array $state = array(), array $conversation = array() ): array {
		$intake = Intake_State::from_array( $state );

		if ( isset( $state['case_profile'] ) && is_array( $state['case_profile'] ) ) {
			$intake->import_case_profile( $state['case_profile'] );
		}

		$user_context = $this->resolve_user_context( $state );
		$this->apply_logged_in_user_facts( $intake, $user_context );

		$case_profile    = is_array( $state['case_profile'] ?? null ) ? $state['case_profile'] : array();
		$procedural_node = trim( (string) ( $case_profile['procedural_node'] ?? '' ) );

		if ( '' === $intake->conversation_id() ) {
			$intake->set_conversation_id( $this->generate_conversation_id() );
		}

		if ( '' === $intake->conversation_summary() ) {
			$intake->set_conversation_summary( $this->memory->fallback_summary( $intake ) );
		} else {
			$summary_presenter = new Case_Summary_Presenter();
			$conversation_notes = $summary_presenter->extract_conversation_notes( $intake->conversation_summary() );

			if ( $conversation_notes !== $intake->conversation_summary() ) {
				$intake->set_conversation_summary( $conversation_notes );
			}
		}

		$matter_switch = new \ProSe\Core\Intake\Matter_Switch();

		if ( $matter_switch->should_reset( $message, $intake->workflow() ) ) {
			$reset             = $matter_switch->reset_case_profile( $intake->to_case_profile( 0 ) );
			$conversation_id   = (string) ( $reset['conversation_id'] ?? $intake->conversation_id() );
			$intake            = Intake_State::from_array( array() );
			$intake->set_conversation_id( $conversation_id );
			$intake->set_conversation_summary( '' );

			if ( ! empty( $reset['facts']['county'] ) ) {
				$intake->merge_updates(
					array(
						'county' => array(
							'value'      => $reset['facts']['county'],
							'confidence' => 1.0,
						),
					)
				);
			}
		}

		if ( $this->is_stage_advance_request( $message ) ) {
			$advance = $this->attempt_stage_advance( $intake, $case_profile, $procedural_node );

			if ( null !== $advance ) {
				return $advance;
			}
		}

		// Direct path: the user wants blank forms and does not want to answer
		// intake questions. Blank forms need no facts — only which packet/forms.
		// The AI never decides forms here; routing and the forms catalog do.
		$direct = $this->detect_direct_request( $message );

		if ( ! empty( $direct['codes'] ) ) {
			if ( null === $intake->workflow() || '' === $intake->workflow() ) {
				$this->fields_provider->resolve( $intake, $message );
			}

			$workflow_for_forms = $intake->workflow();
			$allowed_codes      = $this->filter_form_codes_for_workflow( $direct['codes'], $workflow_for_forms );

			if ( null !== $workflow_for_forms && '' !== $workflow_for_forms && empty( $allowed_codes ) ) {
				return $this->build_mismatched_forms_result( $intake, $direct['codes'], $workflow_for_forms );
			}

			$codes_to_merge = ! empty( $allowed_codes ) ? $allowed_codes : $direct['codes'];
			$download       = $this->merge_forms( $codes_to_merge );

			if ( ! empty( $download['success'] ) ) {
				return $this->build_direct_forms_result( $intake, $download );
			}

			// Requested forms are not individually available — fall back to
			// offering the full packet for the routed matter.
			$direct['wants_forms'] = true;
		}

		if ( ! empty( $direct['wants_forms'] ) ) {
			$resolved_direct = $this->fields_provider->resolve( $intake, $message );
			$workflow_direct = $intake->workflow();
			$missing_direct  = $this->fields_provider->missing_prioritized( $resolved_direct['fields'], $intake );

			if ( null !== $workflow_direct && '' !== $workflow_direct && empty( $missing_direct ) ) {
				$completion_direct = $this->completion->calculate(
					$resolved_direct['required_field_defs'],
					$intake->plain_facts()
				);

				return $this->build_result(
					$intake,
					array(),
					array(),
					array(),
					array(),
					'request_forms',
					'guidance',
					$this->direct_package_message( $workflow_direct ),
					1.0,
					false,
					$completion_direct
				);
			}
			// Not routable yet, or intake still gathering: continue to the normal
			// flow so we can ask the questions needed to identify the matter.
		}

		// --- Deterministic pre-resolve (ProSe owns routing & required fields) ---
		$memory_ctx   = $this->memory->context( $intake, $conversation );
		$resolved_pre = $this->fields_provider->resolve( $intake, $message );
		$missing_pre  = $this->fields_provider->missing_prioritized( $resolved_pre['fields'], $intake );
		$workflow_pre = $intake->workflow();
		$was_complete = empty( $missing_pre ) && null !== $workflow_pre && '' !== $workflow_pre;
		$prefilled    = $this->apply_message_prefill( $message, $intake, $resolved_pre );

		if ( ! empty( $prefilled ) ) {
			$this->sync_child_facts( $intake );
			$resolved_pre = $this->fields_provider->resolve( $intake, $message );
			$missing_pre  = $this->fields_provider->missing_prioritized( $resolved_pre['fields'], $intake );
			$workflow_pre = $intake->workflow();
			$was_complete = empty( $missing_pre ) && null !== $workflow_pre && '' !== $workflow_pre;
		}

		$this->apply_supplemental_case_state( $message, $intake, $procedural_node, $workflow_pre );
		$resolved_pre = $this->fields_provider->resolve( $intake, $message );
		$missing_pre  = $this->fields_provider->missing_prioritized( $resolved_pre['fields'], $intake );
		$workflow_pre = $intake->workflow();

		$missing_ai   = $this->conversation_missing( $missing_pre, $workflow_pre );
		$stage_pre    = $this->stage_context( $workflow_pre, $intake, null !== $workflow_pre && '' !== $workflow_pre, $procedural_node );
		$brief_pre    = $this->resolve_filing_brief( $workflow_pre, $intake, $stage_pre );
		$brief_sent   = ! empty( $state['case_profile']['guidance_brief_delivered'] );

		$completion_pre = $this->completion->calculate(
			$resolved_pre['required_field_defs'],
			$intake->plain_facts()
		);

		$roadmap_pre = $this->roadmap_presenter->present(
			$this->roadmap_input(
				$intake,
				$workflow_pre,
				$missing_pre,
				$completion_pre,
				$stage_pre,
				$this->procedural_navigator_context( $intake, $workflow_pre ),
				$resolved_pre
			)
		);

		$case_profile_for_summary = is_array( $state['case_profile'] ?? null ) ? $state['case_profile'] : array();
		$roadmap_for_summary      = is_array( $case_profile_for_summary['roadmap'] ?? null ) ? $case_profile_for_summary['roadmap'] : $roadmap_pre;
		$summary_presenter        = new Case_Summary_Presenter();
		$case_summary             = $summary_presenter->build(
			array(
				'workflow'        => (string) ( $workflow_pre ?? '' ),
				'facts'           => $intake->plain_facts(),
				'stage_context'   => $stage_pre,
				'roadmap'         => $roadmap_for_summary,
				'procedural_node' => $procedural_node,
				'completion'      => $completion_pre,
				'court'           => (string) ( $intake->court() ?? $case_profile_for_summary['court'] ?? '' ),
				'issue'           => (string) ( $intake->issue() ?? $case_profile_for_summary['issue'] ?? '' ),
			)
		);
		$conversation_notes       = $memory_ctx['summary'];

		// --- Single conversational OpenAI call: extract facts + write reply ---
		$scope_note = isset( $state['scope_note'] ) && is_string( $state['scope_note'] ) ? trim( $state['scope_note'] ) : '';

		$turn = $this->engine->converse(
			$message,
			$intake,
			array(
				'extraction_defs'       => $resolved_pre['extraction_defs'] ?? $resolved_pre['required_field_defs'],
				'missing'               => $missing_ai,
				'workflow'              => $workflow_pre,
				'workflow_info'         => $this->workflow_info( $workflow_pre, $completion_pre, $intake, null !== $workflow_pre && '' !== $workflow_pre ),
				'package'               => $this->package_context( $missing_ai, $completion_pre, $workflow_pre ),
				'completion'            => $completion_pre,
				'contradictions'        => $this->consistency->check( $intake ),
				'summary'               => $conversation_notes,
				'case_summary'          => $case_summary,
				'recent'                => $memory_ctx['recent'],
				'scope_note'            => $scope_note,
				'procedural_navigator'  => $this->procedural_navigator_context( $intake, $workflow_pre ),
				'stage_context'         => $stage_pre,
				'filing_guidance_brief' => $brief_pre,
				'guidance_brief_sent'   => $brief_sent,
				'procedural_roadmap'    => $roadmap_pre,
				'reference_knowledge'   => $this->knowledge_context->for_message( $message, $workflow_pre, null ),
				'user_context'          => $user_context,
			),
			$this->provider,
			$this->logger
		);

		$applied = $intake->merge_updates( $turn['updates'], $this->is_correction_message( $message ) );
		$this->sync_child_facts( $intake );
		$this->apply_supplemental_case_state( $message, $intake, $procedural_node );

		// --- Deterministic post-resolve with the new facts (authoritative) ---
		$resolved       = $this->fields_provider->resolve( $intake, $message );
		$missing        = $this->fields_provider->missing_prioritized( $resolved['fields'], $intake );
		$completion_pct = $this->completion->calculate(
			$resolved['required_field_defs'],
			$intake->plain_facts()
		);
		$contradictions = $this->consistency->check( $intake );

		$this->memory->maybe_update_summary( $intake, $conversation, $this->provider, $this->logger );

		$reply        = trim( (string) $turn['reply'] );
		$workflow     = $intake->workflow();
		$has_workflow = null !== $workflow && '' !== $workflow;
		$stage_ctx    = $this->stage_context( $workflow, $intake, $has_workflow, $procedural_node );
		$filing_brief = $this->resolve_filing_brief( $workflow, $intake, $stage_ctx );
		$brief_extra  = array();
		$account_reply = $this->build_account_meta_reply( $message, $user_context, $intake );

		if ( '' !== $account_reply ) {
			$reply = $account_reply;
		}

		$reply        = $this->apply_filing_brief_reply(
			$reply,
			$filing_brief,
			$brief_sent,
			$message,
			! empty( $stage_ctx['forms_visible'] ),
			$stage_ctx,
			$intake,
			$brief_extra
		);
		$reply        = $this->reconcile_stale_commencement_brief( $reply, $stage_ctx, $intake->plain_facts(), $message );
		$reply        = $this->reconcile_case_state_statement_reply( $reply, $stage_ctx, $intake->plain_facts(), $message );
		$reply        = $this->reconcile_stage_forms_reply( $message, $reply, $stage_ctx, $workflow );
		$reply        = $this->reconcile_procedural_guidance_reply( $message, $reply, $stage_ctx, $has_workflow );
		$reply        = $this->reconcile_reply_after_intake( $reply, $applied, $missing );

		if ( ! User_Intake_Context::message_asks_about_account( $message ) ) {
			$reply = $this->reconcile_reply_for_logged_in_user( $reply, $user_context, $intake );
		}

		// --- Escalation safety net (repeated genuine uncertainty during routing only) ---
		$escalation = $this->escalation->detect( $message, $intake, $turn['raw_confidence'], $has_workflow );

		if ( $escalation['needs_review'] ) {
			$handoff = __( 'We need a little more help with your intake. A team member may follow up.', 'prose-core' );

			return $this->build_result(
				$intake,
				$applied,
				$missing,
				$contradictions,
				array(),
				'needs_review',
				'needs_review',
				$handoff,
				$turn['raw_confidence'],
				true,
				$completion_pct,
				'',
				$brief_extra
			);
		}

		// --- Gathering: keep the conversation going naturally ---
		if ( '' === $reply ) {
			if ( ! empty( $contradictions ) ) {
				$reply = (string) ( $contradictions[0]['message'] ?? '' );
			}
			if ( '' === $reply ) {
				$reply = $this->build_gathering_fallback( $missing );
			}
		}

		$pending_hint = '';

		if ( ! $has_workflow && ! empty( $missing ) ) {
			$pending_hint = (string) $missing[0]['field'];
		} elseif ( $has_workflow ) {
			$intake->set_pending_field( '' );
		}
		if ( $has_workflow && empty( $this->conversation_missing( $missing, $workflow ) ) && $completion_pct >= 100 ) {
			$intent      = 'intake_complete';
			$next_action = 'complete_intake';
		} elseif ( ! empty( $brief_extra['guidance_brief_delivered'] ) ) {
			$intent      = 'guidance';
			$next_action = 'guidance';
		} else {
			$intent      = 'gathering';
			$next_action = 'ask_question';
		}

		$stored_fingerprint = (string) ( $state['case_profile']['roadmap_fingerprint'] ?? '' );
		$roadmap_resolution = $this->roadmap_presenter->resolve_with_change_detection(
			$stored_fingerprint,
			$this->roadmap_input(
				$intake,
				$workflow,
				$missing,
				$completion_pct,
				$stage_ctx,
				$this->procedural_navigator_context( $intake, $workflow ),
				$resolved
			)
		);

		$roadmap_extra = array(
			'roadmap'             => $roadmap_resolution['roadmap'],
			'roadmap_fingerprint' => (string) ( $roadmap_resolution['fingerprint'] ?? '' ),
		);

		$result = $this->build_result(
			$intake,
			$applied,
			$missing,
			$contradictions,
			array(),
			$intent,
			$next_action,
			$reply,
			$turn['raw_confidence'],
			false,
			$completion_pct,
			$pending_hint,
			array_merge(
				$brief_extra,
				$roadmap_extra,
				array(
					'procedural_node' => (string) ( $stage_ctx['procedural_node'] ?? $procedural_node ),
				)
			)
		);

		$result['roadmap_changed'] = ! empty( $roadmap_resolution['changed'] );

		if ( ! empty( $roadmap_resolution['changed'] ) ) {
			$result['roadmap'] = $roadmap_resolution['roadmap'];
		}

		return $result;
	}

	/**
	 * Build a compact workflow context object for the conversation engine.
	 *
	 * @param string|null          $workflow   Workflow key.
	 * @param int                  $completion Completion percentage.
	 * @param Intake_State|null    $intake     Intake state.
	 * @param bool                 $complete   Whether intake is complete.
	 * @return array<string, mixed>
	 */
	private function workflow_info( ?string $workflow, int $completion, ?Intake_State $intake = null, bool $complete = false ): array {
		if ( null === $workflow || '' === $workflow ) {
			return array(
				'resolved'   => false,
				'completion' => $completion,
			);
		}

		$definition = $this->workflows->by_key( $workflow );

		if ( null === $definition ) {
			return array(
				'resolved'   => true,
				'key'        => $workflow,
				'completion' => $completion,
			);
		}

		$stage_context = null;

		if ( null !== $intake ) {
			$stage_context = $this->stage_context( $workflow, $intake, $complete, '' );
		}

		return array(
			'resolved'             => true,
			'key'                  => $workflow,
			'title'                => (string) ( $definition['description'] ?? '' ),
			'court'                => (string) ( $definition['court'] ?? '' ),
			'stages'               => is_array( $definition['stages'] ?? null ) ? $definition['stages'] : array(),
			'stage_context'        => $stage_context,
			'supporting_documents' => is_array( $definition['supporting_documents'] ?? null ) ? $definition['supporting_documents'] : array(),
			'completion'           => $completion,
		);
	}

	/**
	 * Build read-only stage context for the conversation engine.
	 *
	 * @param string|null  $workflow Workflow key.
	 * @param Intake_State $intake   Intake state.
	 * @param bool         $complete Whether intake is complete.
	 * @return array<string, mixed>
	 */
	private function stage_context( ?string $workflow, Intake_State $intake, bool $complete, string $current_node = '' ): array {
		if ( null === $workflow || '' === $workflow ) {
			return array(
				'forms_visible' => false,
			);
		}

		return ( new \ProSe\Core\Forms\Engine\Stage_Form_Presenter() )->present(
			array(
				'workflow'        => $workflow,
				'facts'           => $intake->plain_facts(),
				'intake_complete' => $complete || ( null !== $workflow && '' !== $workflow ),
				'issue'           => (string) ( $intake->plain_facts()['issue'] ?? 'divorce' ),
				'current_node'    => $current_node,
			)
		);
	}

	/**
	 * Build input for the procedural roadmap presenter.
	 *
	 * @param Intake_State                       $intake      Intake state.
	 * @param string|null                        $workflow    Workflow key.
	 * @param array<int, array<string, mixed>>   $missing     Missing fields.
	 * @param int                                $completion  Completion percent.
	 * @param array<string, mixed>               $stage_ctx   Stage context.
	 * @param array<string, mixed>               $navigator   Navigator context.
	 * @param array<string, mixed>               $resolved    Resolved fields payload.
	 * @return array<string, mixed>
	 */
	private function roadmap_input(
		Intake_State $intake,
		?string $workflow,
		array $missing,
		int $completion,
		array $stage_ctx,
		array $navigator,
		array $resolved
	): array {
		$facts = $intake->plain_facts();
		$issue = sanitize_key( (string) ( $facts['issue'] ?? $resolved['issue'] ?? '' ) );

		if ( '' === $issue && null !== $workflow && '' !== $workflow ) {
			$definition = $this->workflows->by_key( $workflow );
			$issue      = sanitize_key( (string) ( $definition['issue_type'] ?? $definition['workflow_category'] ?? '' ) );
		}

		$candidates = is_array( $resolved['candidate_workflows'] ?? null ) ? $resolved['candidate_workflows'] : array();

		return array(
			'issue'                 => $issue,
			'facts'                 => $facts,
			'workflow'              => (string) ( $workflow ?? '' ),
			'missing_fields'        => $missing,
			'completion'            => $completion,
			'stage_context'         => $stage_ctx,
			'procedural_navigator'  => $navigator,
			'workflow_resolved'     => null !== $workflow && '' !== $workflow,
			'intake_complete'       => empty( $missing ) && null !== $workflow && '' !== $workflow,
			'candidate_workflows'   => $candidates,
			'routing_status'        => (string) ( $resolved['routing_status'] ?? '' ),
			'procedural_node'       => (string) ( $stage_ctx['procedural_node'] ?? $stage_ctx['current_stage']['id'] ?? '' ),
		);
	}

	/**
	 * Build a lightweight package status object for the conversation engine.
	 *
	 * @param array<int, array<string, mixed>> $missing    Missing fields.
	 * @param int                              $completion Completion percentage.
	 * @param string|null                      $workflow   Workflow key.
	 * @return array<string, mixed>
	 */
	private function package_context( array $missing, int $completion, ?string $workflow ): array {
		return array(
			'completion'     => $completion,
			'ready'          => empty( $missing ) && null !== $workflow && '' !== $workflow,
			'missing_count'  => count( $missing ),
		);
	}

	/**
	 * Read-only procedural navigator summary for the conversation engine.
	 *
	 * @param Intake_State    $intake   Intake state.
	 * @param string|null     $workflow Workflow key.
	 * @return array<string, mixed>
	 */
	private function procedural_navigator_context( Intake_State $intake, ?string $workflow ): array {
		if ( null === $workflow || '' === $workflow ) {
			return array();
		}

		$facts = $intake->plain_facts();
		$issue = trim( (string) ( $facts['issue'] ?? 'divorce' ) );

		$result = ( new Procedural_Navigator() )->navigate(
			array(
				'issue'    => $issue,
				'facts'    => $facts,
				'workflow' => $workflow,
				'county'   => trim( (string) ( $facts['county'] ?? '' ) ),
			)
		);

		if ( empty( $result['success'] ) ) {
			return array();
		}

		$navigation = is_array( $result['navigation'] ?? null ) ? $result['navigation'] : array();

		return array(
			'court'        => is_array( $navigation['court'] ?? null ) ? $navigation['court'] : array(),
			'workflow'     => is_array( $navigation['workflow'] ?? null ) ? $navigation['workflow'] : array(),
			'forms'        => is_array( $navigation['forms'] ?? null ) ? $navigation['forms'] : array(),
			'next_steps'   => is_array( $navigation['next_steps'] ?? null ) ? $navigation['next_steps'] : array(),
			'stages'       => is_array( $navigation['stages'] ?? null ) ? $navigation['stages'] : array(),
			'instructions' => is_array( $navigation['instructions'] ?? null ) ? $navigation['instructions'] : array(),
		);
	}

	/**
	 * Deterministic guidance message when the model returns no reply.
	 *
	 * Picks one of several natural phrasings so a completed intake does not
	 * repeat the exact same sentence every turn.
	 *
	 * @param string $workflow   Workflow key.
	 * @param int    $completion Completion percentage.
	 * @return string
	 */
	private function build_guidance_fallback( string $workflow, int $completion ): string {
		$info  = $this->workflow_info( $workflow, $completion );
		$title = (string) ( $info['title'] ?? '' );

		if ( '' === $title ) {
			$title = ucwords( str_replace( array( '_', '-' ), ' ', $workflow ) );
		}

		$templates = array(
			/* translators: %s: matter description. */
			__( 'Good news — I have everything I need for your %s. Review the case summary in Case Actions and use Get Documents when you are ready. Ask me anything about the forms or what happens after you file.', 'prose-core' ),
			/* translators: %s: matter description. */
			__( 'That covers it for your %s. Use Get Documents in Case Actions whenever you are ready. Want me to walk you through the forms or the next steps?', 'prose-core' ),
			/* translators: %s: matter description. */
			__( 'We are all set on your %s. I am still here if you have questions about filing, deadlines, or anything on the forms.', 'prose-core' ),
			/* translators: %s: matter description. */
			__( 'I have enough to move forward with your %s. Feel free to ask me how to file or what to expect next.', 'prose-core' ),
			/* translators: %s: matter description. */
			__( 'Your %s intake is complete. Let me know if you would like help understanding any form or the filing process.', 'prose-core' ),
		);

		$index = function_exists( 'wp_rand' ) ? wp_rand( 0, count( $templates ) - 1 ) : array_rand( $templates );

		return sprintf( $templates[ $index ], $title );
	}

	/**
	 * Apply deterministic fact extraction before the conversational model runs.
	 *
	 * Ensures short answers (dates, NYC boroughs) are in intake_state so the model
	 * does not acknowledge a fact and still ask for it again in the same turn.
	 *
	 * @param string               $message  User message.
	 * @param Intake_State         $intake   Intake state.
	 * @param array<string, mixed> $resolved Resolved field context.
	 * @return array<string, array{value: mixed, confidence: float, confirmed?: bool}>
	 */
	private function apply_message_prefill( string $message, Intake_State $intake, array $resolved ): array {
		$defs = $resolved['extraction_defs'] ?? $resolved['required_field_defs'] ?? array();

		if ( ! is_array( $defs ) || array() === $defs ) {
			return array();
		}

		$processed = $this->extractor->process_raw(
			array(),
			0.0,
			'converse',
			$message,
			$defs,
			$intake
		);

		return $intake->merge_updates( $processed['updates'], $this->is_correction_message( $message ) );
	}

	/**
	 * Merge lexicon cues and advance procedural node from case-state statements.
	 *
	 * @param string       $message         User message.
	 * @param Intake_State $intake          Intake state.
	 * @param string       $procedural_node Current node (updated by reference).
	 * @param string|null  $workflow        Workflow key hint.
	 * @return void
	 */
	private function apply_supplemental_case_state(
		string $message,
		Intake_State $intake,
		string &$procedural_node,
		?string $workflow = null
	): void {
		$plain   = $intake->plain_facts();
		$updates = $this->procedural_state->supplemental_fact_updates( $message, $plain );

		if ( ! empty( $updates ) ) {
			$intake->merge_updates( $updates, $this->is_correction_message( $message ) );
			$this->sync_child_facts( $intake );
			$plain = $intake->plain_facts();
		}

		$workflow = null !== $workflow ? trim( $workflow ) : trim( (string) ( $intake->workflow() ?? '' ) );

		if ( '' === $workflow ) {
			return;
		}

		$procedural_node = $this->procedural_state->infer_procedural_node(
			$workflow,
			$procedural_node,
			$plain,
			$message
		);
	}

	/**
	 * Replace stale commencement filing briefs when the case is already underway.
	 *
	 * @param string               $reply     Assistant reply.
	 * @param array<string, mixed> $stage_ctx Stage context.
	 * @param array<string, mixed> $facts     Plain facts.
	 * @param string               $message   User message.
	 * @return string
	 */
	private function reconcile_stale_commencement_brief(
		string $reply,
		array $stage_ctx,
		array $facts,
		string $message
	): string {
		$reply = trim( $reply );

		if ( '' === $reply || ! $this->procedural_state->case_already_filed( $facts, $message ) ) {
			return $reply;
		}

		$stage_id = sanitize_key( (string) ( $stage_ctx['current_stage']['id'] ?? '' ) );

		if ( 'commencement' === $stage_id ) {
			return $reply;
		}

		$reply_lc = strtolower( $reply );

		foreach ( array(
			'how a new divorce case usually starts',
			'summons with notice (form ud-1)',
			'option 1 — summons with notice',
			'option 1 - summons with notice',
			'verified complaint (ud-2)',
		) as $marker ) {
			if ( str_contains( $reply_lc, $marker ) ) {
				$next = trim( (string) ( $stage_ctx['next_action']['message'] ?? '' ) );

				return '' !== $next ? $next : $reply;
			}
		}

		return $reply;
	}

	/**
	 * @param string $reply Assistant reply.
	 * @return bool
	 */
	private function reply_contains_commencement_brief( string $reply ): bool {
		$reply_lc = strtolower( $reply );

		foreach ( array(
			'how a new divorce case usually starts',
			'summons with notice (form ud-1)',
			'verified complaint (ud-2)',
		) as $marker ) {
			if ( str_contains( $reply_lc, $marker ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * When the user states the case is already filed, answer with the current stage guidance.
	 *
	 * @param string               $reply     Assistant reply.
	 * @param array<string, mixed> $stage_ctx Stage context.
	 * @param array<string, mixed> $facts     Plain facts.
	 * @param string               $message   User message.
	 * @return string
	 */
	private function reconcile_case_state_statement_reply(
		string $reply,
		array $stage_ctx,
		array $facts,
		string $message
	): string {
		if ( ! $this->procedural_state->case_already_filed( $facts, $message ) ) {
			return $reply;
		}

		if ( ! $this->reply_is_generic_gathering_prompt( $reply )
			&& ! $this->reply_contains_commencement_brief( $reply ) ) {
			return $reply;
		}

		$next = trim( (string) ( $stage_ctx['next_action']['message'] ?? '' ) );

		if ( '' === $next ) {
			return $reply;
		}

		$stage_title = trim( (string) ( $stage_ctx['current_stage']['title'] ?? '' ) );
		$lead        = __( 'Thanks for sharing those details.', 'prose-core' ) . ' '
			. __( 'Because you have already filed the divorce papers, the case is now in the service stage.', 'prose-core' );

		if ( '' !== $stage_title ) {
			/* translators: %s: procedural stage title. */
			$lead = __( 'Thanks for sharing those details.', 'prose-core' ) . ' '
				. sprintf( __( 'Your case appears to be at the %s stage.', 'prose-core' ), $stage_title );
		}

		return $lead . ' ' . $next;
	}

	/**
	 * Strip stale follow-up questions when facts were just stored.
	 *
	 * @param string                                                            $reply   Model reply.
	 * @param array<string, array{value: mixed, confidence: float, confirmed?: bool}> $applied Applied updates.
	 * @param array<int, array<string, mixed>>                                  $missing Missing fields after merge.
	 * @return string
	 */
	private function reconcile_reply_after_intake( string $reply, array $applied, array $missing ): string {
		$reply = trim( $reply );

		if ( '' === $reply || empty( $applied ) ) {
			return $reply;
		}

		$missing_keys = array_map(
			static function ( array $field ): string {
				return (string) ( $field['field'] ?? '' );
			},
			$missing
		);

		foreach ( array_keys( $applied ) as $key ) {
			if ( in_array( $key, $missing_keys, true ) ) {
				continue;
			}

			if ( ! $this->reply_reasks_field( $reply, (string) $key ) ) {
				continue;
			}

			$ack = preg_split( '/\?\s*/', $reply, 2 )[0] ?? $reply;
			$ack = trim( (string) $ack );
			$next = $this->build_gathering_fallback( $missing );

			if ( '' === $next ) {
				return '' !== $ack ? $ack . '.' : $reply;
			}

			return ( '' !== $ack ? rtrim( $ack, '.' ) . '. ' : '' ) . $next;
		}

		return $reply;
	}

	/**
	 * Whether a conversational reply still asks for a field that was just filled.
	 *
	 * @param string $reply     Reply text.
	 * @param string $field_key Field key.
	 * @return bool
	 */
	private function reply_reasks_field( string $reply, string $field_key ): bool {
		$patterns = array(
			'marriage_location' => '/where were you married|city and state or country|marriage location/i',
			'marriage_date'     => '/when were you married|what date were you married|date were you married/i',
			'separation_date'   => '/when did you separate|date of separation|separation date/i',
			'county'            => '/which county|what county|county (?:will you|do you) file/i',
		);

		if ( ! isset( $patterns[ $field_key ] ) ) {
			return false;
		}

		return (bool) preg_match( $patterns[ $field_key ], $reply );
	}

	/**
	 * Deterministic gathering message when the model returns no reply.
	 *
	 * @param array<int, array<string, mixed>> $missing Missing fields.
	 * @return string
	 */
	private function build_gathering_fallback( array $missing ): string {
		foreach ( $missing as $field ) {
			$question = trim( (string) ( $field['question'] ?? '' ) );

			if ( '' !== $question ) {
				return $question;
			}
		}

		return __( 'Could you tell me a bit more about your legal matter so I can help you find the right path?', 'prose-core' );
	}

	/**
	 * Build interpreter contract result.
	 *
	 * @param Intake_State                                                     $state          State.
	 * @param array<string, array{value: mixed, confidence: float, confirmed?: bool}> $applied Applied updates.
	 * @param array<int, array{field: string, priority: int}>                  $missing        Missing fields.
	 * @param array<int, array{field: string, message: string}>                 $contradictions Contradictions.
	 * @param array<int, array{field: string, message: string}>                 $clarifications Clarifications.
	 * @param string                                                           $intent         Intent.
	 * @param string                                                           $next_action    Next action.
	 * @param string                                                           $question       Question text.
	 * @param float                                                            $confidence     Confidence.
	 * @param bool                                                             $needs_review   Needs review flag.
	 * @param int                                                              $completion     Completion percent.
	 * @param string                                                           $pending_field  Pending field override.
	 * @return array<string, mixed>
	 */
	private function build_result(
		Intake_State $state,
		array $applied,
		array $missing,
		array $contradictions,
		array $clarifications,
		string $intent,
		string $next_action,
		string $question,
		float $confidence,
		bool $needs_review,
		int $completion,
		string $pending_field = '',
		array $case_profile_extra = array()
	): array {
		if ( '' !== $pending_field ) {
			$state->set_pending_field( $pending_field );
		}

		$missing_fields = array_map(
			static function ( array $field ): array {
				return array(
					'field'    => (string) $field['field'],
					'priority' => (int) ( $field['priority'] ?? 0 ),
				);
			},
			$missing
		);

		return array(
			'intent'               => $intent,
			'fact_updates'         => $applied,
			'missing_fields'       => $missing_fields,
			'contradictions'       => $contradictions,
			'clarifications'       => $clarifications,
			'pending_field'        => $state->pending_field(),
			'conversation_summary' => $state->conversation_summary(),
			'needs_review'         => $needs_review,
			'next_action'          => $next_action,
			'question'             => $question,
			'confidence'           => $confidence,
			'state'                => $state->to_array(),
			'case_profile'         => array_merge( $state->to_case_profile( $completion ), $case_profile_extra ),
			'completion'           => $completion,
			'workflow'             => $state->workflow(),
			'conversation_id'      => $state->conversation_id(),
		);
	}

	/**
	 * Detect a "direct" request: the user wants blank forms without answering
	 * intake questions, and/or names specific form codes.
	 *
	 * @param string $message User message.
	 * @return array{wants_forms: bool, codes: array<int, string>}
	 */
	private function detect_direct_request( string $message ): array {
		$wants = $this->documents->wants_documents( $message );

		return array(
			'wants_forms' => $wants,
			'codes'       => $this->extract_form_codes( $message ),
		);
	}

	/**
	 * Extract valid form codes mentioned in the message, validated against the
	 * forms catalog so arbitrary numbers are not treated as codes.
	 *
	 * @param string $message User message.
	 * @return array<int, string>
	 */
	private function extract_form_codes( string $message ): array {
		if ( ! preg_match_all( '/\b([A-Za-z]{1,5})[-\s]?(\d{1,3}[A-Za-z]?(?:\(\d+\))?)\b/', $message, $matches, PREG_SET_ORDER ) ) {
			return array();
		}

		$upper_map = array();

		if ( class_exists( '\ProSe\Core\Forms\Forms_Catalog' ) ) {
			$catalog = new \ProSe\Core\Forms\Forms_Catalog();

			if ( method_exists( $catalog, 'all' ) ) {
				foreach ( array_keys( (array) $catalog->all() ) as $key ) {
					$upper_map[ strtoupper( (string) $key ) ] = (string) $key;
				}
			}
		}

		$codes = array();

		foreach ( $matches as $set ) {
			$candidate = strtoupper( $set[1] . '-' . $set[2] );

			if ( empty( $upper_map ) ) {
				continue;
			}

			if ( isset( $upper_map[ $candidate ] ) && ! in_array( $upper_map[ $candidate ], $codes, true ) ) {
				$codes[] = $upper_map[ $candidate ];
			}
		}

		return $codes;
	}

	/**
	 * Merge explicit form codes into one blank PDF via the package builder.
	 *
	 * @param array<int, string> $codes Form codes.
	 * @return array<string, mixed>
	 */
	private function merge_forms( array $codes ): array {
		if ( ! class_exists( '\ProSe\Core\PackageBuilder\Merged_Blank_Pdf_Service' ) ) {
			return array( 'success' => false );
		}

		try {
			$service = new \ProSe\Core\PackageBuilder\Merged_Blank_Pdf_Service();

			return $service->build_for_codes( $codes );
		} catch ( \Throwable $e ) {
			return array( 'success' => false );
		}
	}

	/**
	 * Build the interpreter result for an explicit-forms download.
	 *
	 * @param Intake_State          $intake   State.
	 * @param array<string, mixed>  $download Merge result.
	 * @return array<string, mixed>
	 */
	private function build_direct_forms_result( Intake_State $intake, array $download ): array {
		$merged  = (array) ( $download['merged'] ?? array() );
		$missing = (array) ( $download['missing'] ?? array() );

		$message = sprintf(
			/* translators: %s: comma-separated list of form codes. */
			__( 'Here are the blank forms you asked for: %s. Your download will open in a new tab.', 'prose-core' ),
			implode( ', ', $merged )
		);

		if ( ! empty( $missing ) ) {
			$message .= ' ' . sprintf(
				/* translators: %s: comma-separated list of form codes. */
				__( 'These were not available individually: %s.', 'prose-core' ),
				implode( ', ', $missing )
			);
		}

		$result = $this->build_result(
			$intake,
			array(),
			array(),
			array(),
			array(),
			'request_forms',
			'offer_forms',
			$message,
			1.0,
			false,
			0
		);

		$result['forms']    = $merged;
		$result['download'] = array(
			'download_url' => (string) ( $download['download_url'] ?? '' ),
			'merged'       => $merged,
			'missing'      => $missing,
		);

		return $result;
	}

	/**
	 * Build the guidance message when offering the full blank packet.
	 *
	 * @param string $workflow Workflow key.
	 * @return string
	 */
	private function direct_package_message( string $workflow ): string {
		$title = ucwords( str_replace( array( '_', '-' ), ' ', $workflow ) );
		$title = trim( str_ireplace( array( ' Nyc', ' Ny' ), '', $title ) );

		return sprintf(
			/* translators: %s: human-readable matter title. */
			__( 'No problem — you do not need to answer every question to get blank forms. Complete intake first, then use Get Documents in Case Actions for %s.', 'prose-core' ),
			$title
		);
	}

	/**
	 * Whether the user is correcting a prior answer.
	 *
	 * @param string $message User message.
	 * @return bool
	 */
	private function is_correction_message( string $message ): bool {
		$text = strtolower( trim( $message ) );

		$phrases = array(
			'sorry',
			'actually',
			'i meant',
			'correction',
			'wait',
			'change that',
			'i misspoke',
			'oh no',
		);

		foreach ( $phrases as $phrase ) {
			if ( str_contains( $text, $phrase ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Keep child-related boolean fields aligned with child_count.
	 *
	 * @param Intake_State $state Intake state.
	 * @return void
	 */
	private function sync_child_facts( Intake_State $state ): void {
		$plain = $state->plain_facts();
		$count = null;

		foreach ( array( 'child_count', 'children_count' ) as $key ) {
			if ( isset( $plain[ $key ] ) && is_numeric( $plain[ $key ] ) ) {
				$count = (int) $plain[ $key ];
				break;
			}
		}

		if ( null === $count ) {
			return;
		}

		$has_children = $count > 0;
		$updates      = array();

		foreach ( array( 'has_minor_children', 'children', 'minor_children_involved' ) as $key ) {
			$existing = $state->get_fact( $key );

			if ( null !== $existing && $this->values_equal_fact( $existing['value'], $has_children ) ) {
				continue;
			}

			$updates[ $key ] = array(
				'value'      => $has_children,
				'confidence' => 0.98,
			);
		}

		if ( ! empty( $updates ) ) {
			$state->merge_updates( $updates, true );
		}
	}

	/**
	 * Compare fact values for sync logic.
	 *
	 * @param mixed $a First value.
	 * @param mixed $b Second value.
	 * @return bool
	 */
	private function values_equal_fact( $a, $b ): bool {
		if ( is_bool( $a ) || is_bool( $b ) ) {
			return (bool) $a === (bool) $b;
		}

		return (string) $a === (string) $b;
	}

	/**
	 * Personal intake fields that do not block routing, downloads, or filing guidance.
	 *
	 * @var string[]
	 */
	private const OPTIONAL_CONVERSATION_FIELDS = array(
		'marriage_date',
		'separation_date',
		'grounds_for_divorce',
		'plaintiff_information',
		'defendant_information',
		'petitioner_information',
		'respondent_information',
		'has_minor_children',
		'child_count',
		'child_names',
		'child_birth_dates',
		'child_name',
		'child_birth_date',
		'custody_arrangement',
		'visitation_arrangement',
		'child_support_terms',
		'existing_orders',
		'assets',
		'debts',
		'income',
		'spouse_name',
		'plaintiff_name',
		'defendant_name',
	);

	/**
	 * @param array<int, array<string, mixed>> $missing  Missing fields.
	 * @param string|null                        $workflow Workflow key.
	 * @return array<int, array<string, mixed>>
	 */
	private function conversation_missing( array $missing, ?string $workflow ): array {
		if ( null === $workflow || '' === $workflow ) {
			return $missing;
		}

		return array_values(
			array_filter(
				$missing,
				function ( array $field ): bool {
					$key = (string) ( $field['field'] ?? '' );

					return '' !== $key && ! in_array( $key, self::OPTIONAL_CONVERSATION_FIELDS, true );
				}
			)
		);
	}

	/**
	 * @param string|null               $workflow Workflow key.
	 * @param Intake_State              $intake   Intake state.
	 * @param array<string, mixed>|null $stage    Stage context.
	 * @return array<string, mixed>|null
	 */
	private function resolve_filing_brief( ?string $workflow, Intake_State $intake, ?array $stage ): ?array {
		if ( null === $workflow || '' === $workflow || empty( $stage['forms_visible'] ) ) {
			return null;
		}

		$facts = $intake->plain_facts();

		return ( new Filing_Guidance_Brief_Resolver() )->resolve(
			array(
				'workflow' => $workflow,
				'facts'    => $facts,
				'stage'    => (string) ( $stage['current_stage']['id'] ?? 'commencement' ),
				'county'   => (string) ( $facts['county'] ?? '' ),
			)
		);
	}

	/**
	 * @param string               $reply         Model or fallback reply.
	 * @param array<string, mixed>|null $brief    Resolved brief.
	 * @param bool                 $already_sent  Brief already delivered.
	 * @param string               $message       User message.
	 * @param bool                 $forms_visible Forms are visible.
	 * @param array<string, mixed> $stage_ctx     Current stage context.
	 * @param Intake_State         $intake        Intake state.
	 * @param array<string, mixed> $profile_extra Case profile extras (by reference).
	 * @return string
	 */
	private function apply_filing_brief_reply(
		string $reply,
		?array $brief,
		bool $already_sent,
		string $message,
		bool $forms_visible,
		array $stage_ctx,
		Intake_State $intake,
		array &$profile_extra
	): string {
		if ( ! $forms_visible || ! is_array( $brief ) ) {
			return $reply;
		}

		if ( User_Intake_Context::message_asks_about_account( $message ) ) {
			return $reply;
		}

		if ( $this->message_asks_current_stage_forms( $message ) ) {
			return $reply;
		}

		if ( $this->procedural_state->case_already_filed( $intake->plain_facts(), $message ) ) {
			if ( '' === trim( $reply ) ) {
				$profile_extra['guidance_brief_delivered'] = true;
				$next                                      = trim( (string) ( $stage_ctx['next_action']['message'] ?? '' ) );

				if ( '' !== $next ) {
					return $next;
				}
			}

			return $reply;
		}

		$stage_id = sanitize_key( (string) ( $stage_ctx['current_stage']['id'] ?? '' ) );

		if ( 'commencement' !== $stage_id && '' !== $stage_id ) {
			return $reply;
		}

		$resolver  = new Filing_Guidance_Brief_Resolver();
		$formatted = $resolver->format( $brief );

		if ( ! $already_sent ) {
			if ( '' === trim( $reply ) ) {
				$profile_extra['guidance_brief_delivered'] = true;

				return $formatted;
			}

			return $reply;
		}

		if ( $this->message_requests_guidance( $message ) && strlen( $reply ) < 200 ) {
			return $formatted;
		}

		return $reply;
	}

	/**
	 * Whether the user is asking which forms apply at the current procedural stage.
	 *
	 * @param string $message User message.
	 * @return bool
	 */
	private function message_asks_current_stage_forms( string $message ): bool {
		$text = strtolower( trim( $message ) );

		foreach ( array(
			'which form',
			'what form',
			'which forms',
			'what forms',
			'forms for this stage',
			'forms for this step',
			'forms for this state',
			'forms do i need',
			'forms need for',
			'forms at this stage',
		) as $phrase ) {
			if ( str_contains( $text, $phrase ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Replace commencement or empty replies when the user asks about current-stage forms.
	 *
	 * @param string               $message   User message.
	 * @param string               $reply     Model reply.
	 * @param array<string, mixed> $stage_ctx Stage context.
	 * @param string|null          $workflow  Workflow key.
	 * @return string
	 */
	private function reconcile_stage_forms_reply( string $message, string $reply, array $stage_ctx, ?string $workflow ): string {
		if ( ! $this->message_asks_current_stage_forms( $message ) ) {
			return $reply;
		}

		if ( empty( $stage_ctx['forms_visible'] ) ) {
			return $reply;
		}

		$stage_id    = sanitize_key( (string) ( $stage_ctx['current_stage']['id'] ?? '' ) );
		$stage_title = strtolower( trim( (string) ( $stage_ctx['current_stage']['title'] ?? '' ) ) );
		$next_type   = sanitize_key( (string) ( $stage_ctx['next_action']['type'] ?? '' ) );

		if ( 'calendar' === $stage_id || 'stage_calendar' === $next_type || str_contains( $stage_title, 'final papers' ) ) {
			return $this->format_calendar_stage_advance_message( $stage_ctx );
		}

		$forms = (array) ( $stage_ctx['stage_forms'] ?? array() );

		if ( empty( $forms ) ) {
			return $reply;
		}

		$reply_lc = strtolower( $reply );

		$mentions_wrong_stage_forms = 'commencement' !== $stage_id && (
			str_contains( $reply_lc, 'ud-1' )
			|| str_contains( $reply_lc, 'ud-2' )
			|| str_contains( $reply_lc, 'summons with notice' )
		);

		if ( ! $mentions_wrong_stage_forms && strlen( $reply ) >= 200 && $this->reply_mentions_current_forms( $reply, $forms ) ) {
			return $reply;
		}

		unset( $workflow );

		return $this->format_stage_forms_reply( $stage_ctx );
	}

	/**
	 * @param string                             $reply Assistant reply.
	 * @param array<int, array<string, mixed>>   $forms Stage forms.
	 * @return bool
	 */
	private function reply_mentions_current_forms( string $reply, array $forms ): bool {
		$reply_lc = strtolower( $reply );

		foreach ( $forms as $form ) {
			$code = strtolower( trim( (string) ( $form['code'] ?? '' ) ) );

			if ( '' !== $code && str_contains( $reply_lc, $code ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Deterministic form list for the user's current procedural stage.
	 *
	 * @param array<string, mixed> $stage_ctx Stage context.
	 * @return string
	 */
	private function format_stage_forms_reply( array $stage_ctx ): string {
		$stage_id = sanitize_key( (string) ( $stage_ctx['current_stage']['id'] ?? '' ) );

		if ( 'calendar' === $stage_id ) {
			return $this->format_calendar_stage_advance_message( $stage_ctx );
		}

		$title = trim( (string) ( $stage_ctx['current_stage']['title'] ?? '' ) );

		if ( '' === $title ) {
			$title = ucwords( str_replace( '_', ' ', $stage_id ) );
		}

		$message = sprintf(
			/* translators: %s: procedural stage title. */
			__( 'For the %s stage, typical forms may include:', 'prose-core' ),
			$title
		);

		$form_lines = array();

		foreach ( (array) ( $stage_ctx['stage_forms'] ?? array() ) as $form ) {
			$code = trim( (string) ( $form['code'] ?? '' ) );

			if ( '' === $code ) {
				continue;
			}

			$form_title   = trim( (string) ( $form['title'] ?? $code ) );
			$form_lines[] = '• ' . $code . ' — ' . $form_title;
		}

		if ( ! empty( $form_lines ) ) {
			$message .= "\n\n" . implode( "\n", $form_lines );
		}

		$message .= "\n\n" . __( 'Informational guidance only — not legal advice.', 'prose-core' );

		return $message;
	}

	/**
	 * Answer next-step questions from stage context when workflow is resolved.
	 *
	 * @param string               $message      User message.
	 * @param string               $reply        Model reply.
	 * @param array<string, mixed> $stage_ctx    Stage context.
	 * @param bool                 $has_workflow Workflow resolved.
	 * @return string
	 */
	private function reconcile_procedural_guidance_reply(
		string $message,
		string $reply,
		array $stage_ctx,
		bool $has_workflow
	): string {
		if ( $this->message_asks_current_stage_forms( $message ) ) {
			return $reply;
		}

		if ( ! $has_workflow || ! $this->message_requests_guidance( $message ) ) {
			return $reply;
		}

		if ( empty( $stage_ctx['forms_visible'] ) ) {
			return $reply;
		}

		$next = trim( (string) ( $stage_ctx['next_action']['message'] ?? '' ) );

		if ( '' === $next ) {
			return $reply;
		}

		if ( $this->reply_is_generic_gathering_prompt( $reply ) || $this->reply_only_asks_optional_fields( $reply ) ) {
			return $next;
		}

		return $reply;
	}

	/**
	 * Whether the model returned a generic continue-intake prompt instead of guidance.
	 *
	 * @param string $reply Assistant reply.
	 * @return bool
	 */
	private function reply_is_generic_gathering_prompt( string $reply ): bool {
		$text = strtolower( trim( $reply ) );

		foreach ( array(
			'could you tell me a little more about your legal matter',
			'could you tell me a bit more about your situation',
			'tell me more about your situation',
		) as $phrase ) {
			if ( str_contains( $text, $phrase ) ) {
				return true;
			}
		}

		return strlen( $reply ) < 80;
	}

	/**
	 * @param string $reply Assistant reply.
	 * @return bool
	 */
	private function reply_only_asks_optional_fields( string $reply ): bool {
		$text = strtolower( $reply );

		foreach ( array( 'marriage date', 'annual income', 'assets', 'debts', 'full legal name', 'contact information' ) as $needle ) {
			if ( str_contains( $text, $needle ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @param string $message User message.
	 * @return bool
	 */
	private function message_requests_guidance( string $message ): bool {
		$text = strtolower( trim( $message ) );

		foreach ( array(
			'how do i file',
			'how to file',
			'what happens next',
			'what do i do next',
			'what do i need to do',
			'what need to do',
			'what should i do',
			'what now',
			'need to do now',
			'next step',
			'next steps',
			'how to start',
			'ud-1',
			'ud-2',
			'summons',
			'complaint',
			'commencement',
			'file divorce',
			'which form',
			'what form',
		) as $phrase ) {
			if ( str_contains( $text, $phrase ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Generate conversation id.
	 *
	 * @return string
	 */
	private function generate_conversation_id(): string {
		if ( function_exists( 'wp_generate_uuid4' ) ) {
			return wp_generate_uuid4();
		}

		$data = random_bytes( 16 );
		$data[6] = chr( ( ord( $data[6] ) & 0x0f ) | 0x40 );
		$data[8] = chr( ( ord( $data[8] ) & 0x3f ) | 0x80 );

		return vsprintf( '%s%s-%s-%s-%s-%s%s%s', str_split( bin2hex( $data ), 4 ) );
	}

	/**
	 * Resolve signed-in user context from state or the current WordPress session.
	 *
	 * @param array<string, mixed> $state Interpreter state.
	 * @return array<string, mixed>
	 */
	private function resolve_user_context( array $state ): array {
		if ( isset( $state['user_context'] ) && is_array( $state['user_context'] ) ) {
			return array_merge( User_Intake_Context::guest(), $state['user_context'] );
		}

		return User_Intake_Context::for_current_user();
	}

	/**
	 * Prefill plaintiff/petitioner name fields from a signed-in account.
	 *
	 * @param Intake_State           $intake       Intake state.
	 * @param array<string, mixed>   $user_context User context.
	 * @return void
	 */
	private function apply_logged_in_user_facts( Intake_State $intake, array $user_context ): void {
		if ( empty( $user_context['logged_in'] ) ) {
			return;
		}

		$display = trim( (string) ( $user_context['display_name'] ?? '' ) );

		if ( '' === $display || User_Intake_Context::is_placeholder_display_name( $display ) ) {
			return;
		}

		$updates = array();

		foreach ( User_Intake_Context::name_field_keys() as $key ) {
			if ( $intake->is_filled( $key ) ) {
				continue;
			}

			$updates[ $key ] = array(
				'value'      => $display,
				'confidence' => 0.92,
			);
		}

		if ( ! empty( $updates ) ) {
			$intake->merge_updates( $updates );
		}
	}

	/**
	 * Build a direct reply when the user asks what name is on file.
	 *
	 * @param string               $message      User message.
	 * @param array<string, mixed> $user_context User context.
	 * @param Intake_State         $intake       Intake state.
	 * @return string
	 */
	private function build_account_meta_reply( string $message, array $user_context, Intake_State $intake ): string {
		if ( ! User_Intake_Context::message_asks_about_account( $message ) ) {
			return '';
		}

		$known_name = $this->resolve_known_user_name( $user_context, $intake );

		if ( empty( $user_context['logged_in'] ) ) {
			if ( '' !== $known_name ) {
				return sprintf(
					/* translators: %s: name on file in the current intake */
					__( 'Yes — I have you as %s in this case so far. Sign in to link your account name automatically.', 'prose-core' ),
					$known_name
				);
			}

			return __( "I don't have your name on file yet. Share your legal name whenever you're ready.", 'prose-core' );
		}

		if ( '' === $known_name ) {
			return __( "You're signed in, but I don't have your name saved yet. What name should I use on your court forms?", 'prose-core' );
		}

		return sprintf(
			/* translators: %s: account or intake name */
			__( 'Yes — I have you as %s on file from your account. Tell me if that is not your full legal name for court papers.', 'prose-core' ),
			$known_name
		);
	}

	/**
	 * Resolve the best-known user name from intake facts or account context.
	 *
	 * @param array<string, mixed> $user_context User context.
	 * @param Intake_State         $intake       Intake state.
	 * @return string
	 */
	private function resolve_known_user_name( array $user_context, Intake_State $intake ): string {
		foreach ( User_Intake_Context::name_field_keys() as $key ) {
			$fact = $intake->get_fact( $key );

			if ( null === $fact || ! is_string( $fact['value'] ) ) {
				continue;
			}

			$name = trim( $fact['value'] );

			if ( '' !== $name ) {
				return $name;
			}
		}

		return trim( (string) ( $user_context['display_name'] ?? '' ) );
	}

	/**
	 * Remove stale name/contact asks when the account name is already on file.
	 *
	 * @param string               $reply        Assistant reply.
	 * @param array<string, mixed> $user_context User context.
	 * @param Intake_State         $intake       Intake state.
	 * @return string
	 */
	private function reconcile_reply_for_logged_in_user( string $reply, array $user_context, Intake_State $intake ): string {
		$reply = trim( $reply );

		if ( '' === $reply || empty( $user_context['logged_in'] ) ) {
			return $reply;
		}

		$has_name = false;

		foreach ( User_Intake_Context::name_field_keys() as $key ) {
			if ( $intake->is_filled( $key ) ) {
				$has_name = true;
				break;
			}
		}

		if ( ! $has_name || ! preg_match( '/full legal name|contact information|your legal name/i', $reply ) ) {
			return $reply;
		}

		$sentences = preg_split( '/(?<=[.!?])\s+/', $reply );

		if ( ! is_array( $sentences ) ) {
			return $reply;
		}

		$kept = array();

		foreach ( $sentences as $sentence ) {
			$sentence = trim( (string) $sentence );

			if ( '' === $sentence ) {
				continue;
			}

			if ( preg_match( '/full legal name|contact information|your legal name/i', $sentence ) ) {
				if ( preg_match( '/\b(have you as|on file|from your account)\b/i', $sentence ) ) {
					$kept[] = $sentence;
				}
				continue;
			}

			$kept[] = $sentence;
		}

		if ( empty( $kept ) ) {
			return $reply;
		}

		return trim( implode( ' ', $kept ) );
	}

	/**
	 * Whether the user is asking to advance within the current procedural workflow.
	 *
	 * @param string $message User message.
	 * @return bool
	 */
	private function is_stage_advance_request( string $message ): bool {
		$text = strtolower( trim( $message ) );

		if ( '' === $text ) {
			return false;
		}

		$patterns = array(
			'/\b(?:move|go|advance|proceed|continue)\s+(?:to\s+)?(?:the\s+)?(?:next|new)\s+stage\b/',
			'/\bnext\s+stage\b/',
			'/\bnew\s+stage\b/',
			'/\bmove\s+to\s+the\s+next\s+step\b/',
		);

		foreach ( $patterns as $pattern ) {
			if ( preg_match( $pattern, $text ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Advance the procedural stage within the current workflow.
	 *
	 * @param Intake_State           $intake          Intake state.
	 * @param array<string, mixed>   $case_profile    Case profile extras.
	 * @param string                 $procedural_node Current procedural node.
	 * @return array<string, mixed>|null
	 */
	private function attempt_stage_advance( Intake_State $intake, array $case_profile, string $procedural_node ): ?array {
		$workflow = $intake->workflow();

		if ( null === $workflow || '' === $workflow ) {
			return null;
		}

		$presenter = new \ProSe\Core\Forms\Engine\Stage_Form_Presenter();
		$facts     = $intake->plain_facts();
		$stage_ctx = $presenter->present(
			array(
				'workflow'        => $workflow,
				'facts'           => $facts,
				'intake_complete'   => true,
				'issue'           => (string) ( $intake->issue() ?? 'divorce' ),
				'current_node'    => $procedural_node,
			)
		);

		if ( empty( $stage_ctx['forms_visible'] ) ) {
			$resolved   = $this->fields_provider->resolve( $intake, '' );
			$missing    = $this->fields_provider->missing_prioritized( $resolved['fields'], $intake );
			$completion = $this->completion->calculate(
				$resolved['required_field_defs'],
				$intake->plain_facts()
			);

			return $this->build_result(
				$intake,
				array(),
				$missing,
				array(),
				array(),
				'gathering',
				'ask_question',
				__( 'Complete the current intake details before advancing to the next procedural stage.', 'prose-core' ),
				1.0,
				false,
				$completion,
				! empty( $missing ) ? (string) $missing[0]['field'] : '',
				array(
					'procedural_node' => $procedural_node,
				)
			);
		}

		$current_stage = (string) ( $stage_ctx['current_stage']['id'] ?? '' );
		$current_node  = (string) ( $stage_ctx['procedural_node'] ?? $procedural_node );

		if ( '' === $current_stage ) {
			return null;
		}

		$advanced_node = $presenter->advance_after_stage( $workflow, $current_node, $current_stage, $facts );

		if ( $advanced_node === $current_node ) {
			return $this->build_result(
				$intake,
				array(),
				array(),
				array(),
				array(),
				'guidance',
				'guidance',
				__( 'You are already at the latest available stage for your case. Complete the current step or tell me what you need next.', 'prose-core' ),
				1.0,
				false,
				(int) ( $case_profile['progress'] ?? 0 ),
				'',
				array(
					'procedural_node' => $current_node,
				)
			);
		}

		$next_ctx = $presenter->present(
			array(
				'workflow'        => $workflow,
				'facts'           => $facts,
				'intake_complete'   => true,
				'issue'           => (string) ( $intake->issue() ?? 'divorce' ),
				'current_node'    => $advanced_node,
			)
		);

		$resolved   = $this->fields_provider->resolve( $intake, '' );
		$missing    = $this->fields_provider->missing_prioritized( $resolved['fields'], $intake );
		$completion = $this->completion->calculate(
			$resolved['required_field_defs'],
			$intake->plain_facts()
		);

		return $this->build_result(
			$intake,
			array(),
			$missing,
			array(),
			array(),
			'guidance',
			'guidance',
			$this->format_stage_advance_message( $next_ctx, $workflow ),
			1.0,
			false,
			$completion,
			! empty( $missing ) ? (string) $missing[0]['field'] : '',
			array(
				'procedural_node'          => $advanced_node,
				'guidance_brief_delivered' => (bool) ( $case_profile['guidance_brief_delivered'] ?? false ),
			)
		);
	}

	/**
	 * @param array<string, mixed> $stage_ctx Stage context.
	 * @param string               $workflow  Workflow key.
	 * @return string
	 */
	private function format_stage_advance_message( array $stage_ctx, string $workflow ): string {
		$stage_id = sanitize_key( (string) ( $stage_ctx['current_stage']['id'] ?? '' ) );

		if ( 'calendar' === $stage_id && $this->is_uncontested_divorce_workflow( $workflow ) ) {
			return $this->format_calendar_stage_advance_message( $stage_ctx );
		}

		$title = trim( (string) ( $stage_ctx['current_stage']['title'] ?? '' ) );
		$desc  = trim( (string) ( $stage_ctx['current_stage']['description'] ?? '' ) );

		if ( '' === $title ) {
			$title = ucwords( str_replace( '_', ' ', (string) ( $stage_ctx['current_stage']['id'] ?? 'next stage' ) ) );
		}

		$message = sprintf(
			/* translators: %s: procedural stage title. */
			__( 'Moving you to the %s stage.', 'prose-core' ),
			$title
		);

		if ( '' !== $desc ) {
			$message .= ' ' . $desc;
		}

		$form_lines = array();

		foreach ( (array) ( $stage_ctx['stage_forms'] ?? array() ) as $form ) {
			$code = trim( (string) ( $form['code'] ?? '' ) );

			if ( '' === $code ) {
				continue;
			}

			$form_title = trim( (string) ( $form['title'] ?? $code ) );
			$form_lines[] = '• ' . $code . ' — ' . $form_title;
		}

		if ( ! empty( $form_lines ) ) {
			$message .= "\n\n" . implode( "\n", $form_lines );
		}

		$message .= "\n\n" . __( 'Informational guidance only — not legal advice.', 'prose-core' );

		return $message;
	}

	/**
	 * Calendar-stage transition copy for NYC uncontested divorce workflows.
	 *
	 * @param array<string, mixed> $stage_ctx Stage context.
	 * @return string
	 */
	private function format_calendar_stage_advance_message( array $stage_ctx ): string {
		$lines   = array();
		$lines[] = __( 'Final Papers & Calendar', 'prose-core' );
		$lines[] = '';
		$lines[] = __( 'You\'re ready to prepare the Final Papers that are submitted to the court for review before a judgment of divorce can be issued.', 'prose-core' );
		$lines[] = '';
		$lines[] = __( 'Based on the information you\'ve provided, the system will prepare the required final submission forms. Some forms are mandatory for every case, while others are included only if they apply to your circumstances (for example, if you have children, are requesting child support, or the marriage was performed by clergy).', 'prose-core' );
		$lines[] = '';
		$lines[] = __( 'Typical forms at this stage may include:', 'prose-core' );
		$lines[] = '';

		foreach ( (array) ( $stage_ctx['stage_forms'] ?? array() ) as $form ) {
			if ( ! empty( $form['uncertain'] ) ) {
				continue;
			}

			$code = trim( (string) ( $form['code'] ?? '' ) );

			if ( '' === $code ) {
				continue;
			}

			$form_title = trim( (string) ( $form['title'] ?? $code ) );
			$suffix     = $this->calendar_form_applicability_suffix( $code, ! empty( $form['required'] ) );
			$lines[]    = '• ' . $code . ' — ' . $form_title . $suffix;
		}

		$skipped = (array) ( $stage_ctx['skipped_forms'] ?? array() );

		if ( ! empty( $skipped ) ) {
			$lines[] = '';
			$lines[] = __( 'Forms not included for your situation:', 'prose-core' );

			foreach ( $skipped as $form ) {
				$code   = trim( (string) ( $form['code'] ?? '' ) );
				$reason = trim( (string) ( $form['reason'] ?? '' ) );

				if ( '' === $code ) {
					continue;
				}

				$line = '• ' . $code;

				if ( '' !== $reason ) {
					$line .= ' — ' . $reason;
				}

				$lines[] = $line;
			}
		}

		$lines[] = '';
		$lines[] = __( 'Once these documents are completed and filed, the court can review your case and determine whether it is ready for a Judgment of Divorce.', 'prose-core' );
		$lines[] = '';
		$lines[] = __( 'Informational guidance only — not legal advice.', 'prose-core' );

		return implode( "\n", $lines );
	}

	/**
	 * @param string $code              Form code.
	 * @param bool   $workflow_required Whether the workflow marks the form required.
	 * @return string
	 */
	private function calendar_form_applicability_suffix( string $code, bool $workflow_required ): string {
		if ( ! $workflow_required ) {
			return ' ' . __( '(if applicable)', 'prose-core' );
		}

		$conditional_required = array(
			'UD-4',
			'UD-7',
			'UD-8(1)',
			'UD-8(2)',
			'UD-8(3)',
			'UD-8a',
		);

		foreach ( $conditional_required as $conditional_code ) {
			if ( 0 === strcasecmp( $code, $conditional_code ) ) {
				return ' ' . __( '(if applicable)', 'prose-core' );
			}
		}

		return '';
	}

	/**
	 * @param string $workflow Workflow key.
	 * @return bool
	 */
	private function is_uncontested_divorce_workflow( string $workflow ): bool {
		return str_starts_with( $workflow, 'uncontested_divorce_' );
	}

	/**
	 * @param array<int, string> $codes    Requested form codes.
	 * @param string|null        $workflow Workflow key.
	 * @return array<int, string>
	 */
	private function filter_form_codes_for_workflow( array $codes, ?string $workflow ): array {
		if ( null === $workflow || '' === $workflow ) {
			return $codes;
		}

		$allowed = array_map(
			'strtoupper',
			$this->form_codes_allowed_for_workflow( $workflow )
		);

		if ( empty( $allowed ) ) {
			return array();
		}

		return array_values(
			array_filter(
				$codes,
				static function ( string $code ) use ( $allowed ): bool {
					return in_array( strtoupper( $code ), $allowed, true );
				}
			)
		);
	}

	/**
	 * @param string $workflow Workflow key.
	 * @return array<int, string>
	 */
	private function form_codes_allowed_for_workflow( string $workflow ): array {
		$definition = $this->workflows->by_key( $workflow );

		if ( ! is_array( $definition ) ) {
			return array();
		}

		return $this->workflows->required_form_codes( $definition );
	}

	/**
	 * @param Intake_State         $intake   Intake state.
	 * @param array<int, string>   $codes    Requested codes.
	 * @param string               $workflow Current workflow.
	 * @return array<string, mixed>
	 */
	private function build_mismatched_forms_result( Intake_State $intake, array $codes, string $workflow ): array {
		$title = ucwords( str_replace( array( '_', '-' ), ' ', $workflow ) );
		$title = trim( str_ireplace( array( ' Nyc', ' Ny' ), '', $title ) );

		$message = sprintf(
			/* translators: 1: comma-separated form codes, 2: human-readable workflow title. */
			__( 'Those forms (%1$s) are not part of your current matter (%2$s). Tell me your filing county or use Get Documents for the forms tied to this case.', 'prose-core' ),
			implode( ', ', $codes ),
			$title
		);

		return $this->build_result(
			$intake,
			array(),
			array(),
			array(),
			array(),
			'request_forms',
			'ask_question',
			$message,
			1.0,
			false,
			0
		);
	}
}
