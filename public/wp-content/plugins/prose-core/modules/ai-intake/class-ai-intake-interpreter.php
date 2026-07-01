<?php
/**
 * AI Intake Interpreter — orchestrates conversational intake.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Ai_Intake;

use ProSe\Core\Forms\Engine\Stage_Form_Group_Presenter;
use ProSe\Core\Guidance\Filing_Guidance_Brief_Resolver;
use ProSe\Core\Guidance\Procedural_Roadmap_Presenter;
use ProSe\Core\Intake\Case_Summary_Presenter;
use ProSe\Core\Intake\Completed_Stage_Document_Store;
use ProSe\Core\Intake\Completion_Calculator;
use ProSe\Core\Intake\Procedural_State_Inferrer;
use ProSe\Core\Intake\Document_Request_Detector;
use ProSe\Core\Procedural\Procedural_Navigator;
use ProSe\Core\Routing\Routing_Discriminator_Catalog;
use ProSe\Core\Routing\Workflow_Engine;
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
	 * Workflow engine (deterministic — no conversational text).
	 *
	 * @var Workflow_Engine
	 */
	private Workflow_Engine $workflow_engine;

	/**
	 * Case manager presenter (snapshot, timeline, upcoming documents).
	 *
	 * @var Case_Manager_Presenter
	 */
	private Case_Manager_Presenter $case_manager;

	/**
	 * Canonical case context builder.
	 *
	 * @var Case_Context_Builder
	 */
	private Case_Context_Builder $case_context;

	/**
	 * Procedural state inferrer.
	 *
	 * @var Procedural_State_Inferrer
	 */
	private Procedural_State_Inferrer $procedural_state;

	/**
	 * Case profile snapshot at the start of interpret() — preserves stage progress.
	 *
	 * @var array<string, mixed>
	 */
	private array $pinned_case_profile = array();

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
	 * @param Workflow_Engine|null            $workflow_engine   Workflow engine.
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
		?Knowledge_Context_Provider $knowledge_context = null,
		?Workflow_Engine $workflow_engine = null
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
		$this->workflow_engine   = $workflow_engine ?? new Workflow_Engine( null, null, $this->fields_provider );
		$this->case_manager      = new Case_Manager_Presenter();
		$this->case_context      = new Case_Context_Builder( $this->workflow_engine, $this->fields_provider );
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
		$this->pinned_case_profile = $case_profile;
		$workflow_at_entry         = trim( (string) ( $intake->workflow() ?? $case_profile['workflow'] ?? '' ) );

		if ( ! empty( $state['stage_guidance_only'] ) ) {
			return $this->build_stage_guidance_result( $intake, $case_profile, $state );
		}

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

		if ( $this->is_stage_advance_request( $message ) && empty( $state['stage_guidance_only'] ) ) {
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

		// --- Deterministic pre-resolve (Workflow Engine owns routing & missing facts) ---
		$memory_ctx          = $this->memory->context( $intake, $conversation );
		$missing_payload_pre = $this->workflow_engine->get_missing_facts( $intake, $message );
		$resolved_pre        = $missing_payload_pre['resolved'];
		$missing_pre         = $missing_payload_pre['all'];
		$workflow_pre        = $intake->workflow();
		$was_complete        = empty( $missing_pre ) && null !== $workflow_pre && '' !== $workflow_pre;
		$prefilled           = $this->apply_message_prefill( $message, $intake, $resolved_pre );

		if ( ! empty( $prefilled ) ) {
			$this->sync_child_facts( $intake );
			$missing_payload_pre = $this->workflow_engine->get_missing_facts( $intake, $message );
			$resolved_pre          = $missing_payload_pre['resolved'];
			$missing_pre           = $missing_payload_pre['all'];
			$workflow_pre          = $intake->workflow();
			$was_complete          = empty( $missing_pre ) && null !== $workflow_pre && '' !== $workflow_pre;
		}

		$this->apply_supplemental_case_state( $message, $intake, $procedural_node, $workflow_pre );
		$missing_payload_pre = $this->workflow_engine->get_missing_facts( $intake, $message );
		$resolved_pre        = $missing_payload_pre['resolved'];
		$missing_pre         = $missing_payload_pre['all'];
		$workflow_pre        = $intake->workflow();

		$missing_ai     = $missing_payload_pre['conversation'];
		$completion_pre = (int) $missing_payload_pre['completion'];
		$workflow_state_pre = $this->resolve_workflow_state( $intake, $procedural_node, $case_profile );
		$procedural_node    = $this->prefer_pinned_procedural_node(
			$case_profile,
			(string) ( $workflow_state_pre['procedural_node'] ?? $procedural_node )
		);

		if ( is_array( $workflow_state_pre ) ) {
			$workflow_state_pre['procedural_node'] = $procedural_node;
		}

		$stage_pre    = $this->stage_context( $workflow_pre, $intake, ! empty( $workflow_state_pre['intake_complete'] ), $procedural_node, $case_profile );
		$brief_pre    = $this->resolve_filing_brief( $workflow_pre, $intake, $stage_pre );
		$brief_sent   = ! empty( $state['case_profile']['guidance_brief_delivered'] );

		if ( $this->is_conversational_closing_message( $message ) ) {
			$case_memory_pre = Case_Memory::build(
				$intake,
				$missing_payload_pre,
				$stage_pre
			);

			return $this->build_result(
				$intake,
				array(),
				$missing_pre,
				$this->consistency->check( $intake ),
				array(),
				null !== $workflow_pre && '' !== $workflow_pre ? 'guidance' : 'gathering',
				'guidance',
				$this->build_closing_acknowledgment_reply(),
				1.0,
				false,
				$completion_pre,
				'',
				array_merge(
					$case_profile,
					array(
						'case_memory' => $case_memory_pre,
					)
				)
			);
		}

		$case_memory_pre = Case_Memory::build(
			$intake,
			$missing_payload_pre,
			$stage_pre
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

		$event_context = Ai_Event_Context::resolve(
			array(
				'state'             => $state,
				'message'           => $message,
				'workflow_at_entry' => $workflow_at_entry,
				'workflow_now'      => (string) ( $workflow_pre ?? '' ),
			)
		);

		$turn = $this->engine->converse(
			$message,
			$intake,
			array(
				'extraction_defs'       => $resolved_pre['extraction_defs'] ?? $resolved_pre['required_field_defs'],
				'case_memory'           => $case_memory_pre,
				'workflow'              => $workflow_pre,
				'workflow_info'         => $this->workflow_info( $workflow_pre, $completion_pre, $intake, null !== $workflow_pre && '' !== $workflow_pre ),
				'package'               => $this->package_context( $missing_pre, $completion_pre, $workflow_pre ),
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
				'reference_knowledge'   => $this->is_conversational_closing_message( $message )
					? array()
					: $this->knowledge_context->for_message( $message, $workflow_pre, null ),
				'user_context'          => $user_context,
				'gathering_hints'       => array(
					'bullets' => \ProSe\Core\Routing\Routing_Discriminator_Catalog::gathering_bullets( $missing_ai ),
					'issue'   => (string) ( $intake->issue() ?? '' ),
				),
				'event_context'         => $event_context,
			),
			$this->provider,
			$this->logger
		);

		$applied = $intake->merge_updates( $turn['updates'], $this->is_correction_message( $message ) );
		$this->sync_child_facts( $intake );
		$this->apply_supplemental_case_state( $message, $intake, $procedural_node );

		// --- Deterministic post-resolve with the new facts (authoritative) ---
		$missing_payload = $this->workflow_engine->get_missing_facts( $intake, $message );
		$resolved        = $missing_payload['resolved'];
		$missing         = $missing_payload['all'];
		$completion_pct  = (int) $missing_payload['completion'];
		$contradictions = $this->consistency->check( $intake );

		$this->memory->maybe_update_summary( $intake, $conversation, $this->provider, $this->logger );

		$reply        = trim( (string) $turn['reply'] );
		$workflow     = $intake->workflow();
		$has_workflow = null !== $workflow && '' !== $workflow;
		$workflow_state = $this->resolve_workflow_state( $intake, $procedural_node, $case_profile );
		$procedural_node = $this->prefer_pinned_procedural_node(
			$case_profile,
			(string) ( $workflow_state['procedural_node'] ?? $procedural_node )
		);

		if ( is_array( $workflow_state ) ) {
			$workflow_state['procedural_node'] = $procedural_node;
		}

		$stage_ctx    = $this->stage_context( $workflow, $intake, ! empty( $workflow_state['intake_complete'] ), $procedural_node, $case_profile );
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
		$roadmap_snapshot = is_array( $state['case_profile']['roadmap'] ?? null ) ? $state['case_profile']['roadmap'] : array();
		$reply            = $this->reconcile_procedural_guidance_reply( $message, $reply, $stage_ctx, $has_workflow, $roadmap_snapshot, $workflow );
		$reply        = $this->reconcile_reply_after_intake( $reply, $applied, $missing, $workflow );

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
			if ( '' === $reply && $has_workflow && $this->message_requests_guidance( $message ) ) {
				$reply = $this->build_next_step_guidance_reply( $stage_ctx, $roadmap_snapshot, (string) $workflow );
			}
			if ( '' === $reply ) {
				$reply = $this->build_gathering_fallback( $missing, $workflow );
			}
		}

		$conversation_missing = $missing_payload['conversation'];
		$reply                = $this->reconcile_partial_intake_continuity( $reply, $applied, $conversation_missing, $workflow, $stage_ctx );

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
				$missing_payload['resolved'] ?? $resolved
			)
		);

		$roadmap_extra = array(
			'roadmap'             => $roadmap_resolution['roadmap'],
			'roadmap_fingerprint' => (string) ( $roadmap_resolution['fingerprint'] ?? '' ),
		);

		$case_profile_for_summary = is_array( $state['case_profile'] ?? null ) ? $state['case_profile'] : array();
		$roadmap_for_summary      = is_array( $roadmap_resolution['roadmap'] ?? null )
			? $roadmap_resolution['roadmap']
			: ( is_array( $case_profile_for_summary['roadmap'] ?? null ) ? $case_profile_for_summary['roadmap'] : $roadmap_snapshot );

		$canonical_context = $this->case_context->build(
			array(
				'intake'           => $intake,
				'case_profile'     => $case_profile_for_summary,
				'missing_payload'  => $missing_payload,
				'procedural_node'  => $procedural_node,
				'roadmap'          => $roadmap_for_summary,
				'completion'       => $completion_pct,
				'raw_confidence'   => $turn['raw_confidence'],
				'court'            => (string) ( $intake->court() ?? $case_profile_for_summary['court'] ?? '' ),
				'issue'            => (string) ( $intake->issue() ?? $case_profile_for_summary['issue'] ?? '' ),
			)
		);

		$case_memory       = is_array( $canonical_context['case_memory'] ?? null ) ? $canonical_context['case_memory'] : array();
		$case_summary_final = is_array( $canonical_context['case_summary'] ?? null ) ? $canonical_context['case_summary'] : array();
		$workflow_state    = is_array( $canonical_context['workflow_state'] ?? null ) ? $canonical_context['workflow_state'] : array();
		$stage_ctx         = is_array( $canonical_context['stage_context'] ?? null ) ? $canonical_context['stage_context'] : $stage_ctx;

		$reply = $this->case_manager->append_sections(
			$reply,
			array(
				'message'              => $message,
				'case_summary'         => $case_summary_final,
				'case_memory'          => $case_memory,
				'stage_context'        => $stage_ctx,
				'workflow'             => (string) ( $workflow ?? '' ),
				'facts'                => $intake->plain_facts(),
				'raw_confidence'       => $turn['raw_confidence'],
				'workflow_assessment'  => $canonical_context['workflow_assessment'] ?? array(),
				'stage_transition'     => ! empty( $state['stage_guidance_turn'] ),
			)
		);

		$pending_hint = '';

		if ( ! $has_workflow && ! empty( $conversation_missing ) ) {
			if ( count( $conversation_missing ) > 1 ) {
				$pending_hint = '';
				$intake->set_pending_field( '' );
			} else {
				$first        = $conversation_missing[0];
				$pending_hint = (string) ( $first['key'] ?? $first['field'] ?? '' );
			}
		} elseif ( $has_workflow ) {
			$intake->set_pending_field( '' );
		}

		$account_turn = User_Intake_Context::message_asks_about_account( $message ) && '' !== $account_reply;

		if ( $account_turn ) {
			$intent      = 'gathering';
			$next_action = 'ask_question';
		} elseif ( $has_workflow && empty( $conversation_missing ) ) {
			if ( $completion_pct >= 100 ) {
				$intent      = 'intake_complete';
				$next_action = 'complete_intake';
			} else {
				$intent      = 'guidance';
				$next_action = 'guidance';
			}
		} elseif ( $has_workflow && ! empty( $missing ) ) {
			$intent      = 'gathering';
			$next_action = 'ask_question';
		} elseif ( ! empty( $brief_extra['guidance_brief_delivered'] ) ) {
			$intent      = 'guidance';
			$next_action = 'guidance';
		} else {
			$intent      = 'gathering';
			$next_action = 'ask_question';
		}

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
					'procedural_node' => (string) ( $workflow_state['procedural_node'] ?? $procedural_node ),
					'workflow_state'  => $workflow_state,
					'case_memory'     => $case_memory,
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
	private function stage_context( ?string $workflow, Intake_State $intake, bool $complete, string $current_node = '', array $case_profile = array() ): array {
		if ( null === $workflow || '' === $workflow ) {
			return array(
				'forms_visible' => false,
			);
		}

		$resolved = $this->fields_provider->resolve( $intake, '' );

		return $this->workflow_engine->determine_stage(
			$workflow,
			$intake->plain_facts(),
			$current_node,
			$complete,
			(array) ( $resolved['required_field_defs'] ?? array() ),
			Completed_Stage_Document_Store::completed_stage_count( $case_profile )
		);
	}

	/**
	 * Canonical workflow state for this turn.
	 *
	 * @param Intake_State         $intake          Intake state.
	 * @param string               $procedural_node Stored procedural node.
	 * @param array<string, mixed> $case_profile    Case profile snapshot.
	 * @return array<string, mixed>
	 */
	private function resolve_workflow_state( Intake_State $intake, string $procedural_node, array $case_profile = array() ): array {
		$workflow = (string) ( $intake->workflow() ?? '' );

		if ( '' === $workflow ) {
			return array();
		}

		$resolved = $this->fields_provider->resolve( $intake, '' );

		return $this->workflow_engine->resolve_state(
			$workflow,
			$intake->plain_facts(),
			$procedural_node,
			(array) ( $resolved['required_field_defs'] ?? array() ),
			Completed_Stage_Document_Store::completed_stage_count( $case_profile )
		);
	}

	/**
	 * Guidance-only turn after the user confirms a procedural stage in the UI.
	 *
	 * @param Intake_State         $intake       Intake state.
	 * @param array<string, mixed> $case_profile Case profile (must not be regressed).
	 * @param array<string, mixed> $state        Request state.
	 * @return array<string, mixed>
	 */
	private function build_stage_guidance_result( Intake_State $intake, array $case_profile, array $state ): array {
		if ( is_array( $state['case_profile'] ?? null ) && ! empty( $state['case_profile'] ) ) {
			$case_profile = array_merge( $case_profile, $state['case_profile'] );
		}

		$integrity    = new \ProSe\Core\Intake\Case_Stage_Integrity( $this->workflow_engine );
		$case_profile = $integrity->reconcile_case_profile( $case_profile, true );

		$actions = ( new \ProSe\Core\Intake\Case_Actions_Resolver() )->resolve( $case_profile );
		$intake_context = array(
			'conversation_summary' => $intake->conversation_summary(),
		);

		if ( is_array( $state['conversation_tail'] ?? null ) ) {
			$intake_context['conversation_tail'] = $state['conversation_tail'];
		}

		$service = new Stage_Transition_Guidance_Service( null, $this->logger, null, null, null, $this->workflow_engine );
		$result  = $service->generate(
			array(
				'advanced'        => true,
				'completed_stage' => sanitize_key( (string) ( $state['completed_stage'] ?? '' ) ),
				'case_profile'    => $case_profile,
				'stage_context'   => is_array( $actions['stage_context'] ?? null ) ? $actions['stage_context'] : array(),
				'actions'         => $actions,
				'message'         => '',
				'procedural_node' => (string) ( $case_profile['procedural_node'] ?? '' ),
				'ai_event'        => Ai_Event_Context::build(
					Ai_Event_Context::TYPE_COMPLETION_CONFIRMATION,
					array(
						'completed_stage' => sanitize_key( (string) ( $state['completed_stage'] ?? '' ) ),
						'source'          => 'stage_guidance_only',
					)
				),
			),
			$intake_context
		);
		$guidance = trim( (string) ( $result['guidance'] ?? '' ) );

		if ( '' === $guidance ) {
			$guidance = __( 'Use Get Documents in Case Actions for the forms for this step. Ask me if you need help with any form.', 'prose-core' );
		}

		$missing_payload = $this->workflow_engine->get_missing_facts( $intake, '' );
		$missing         = $missing_payload['all'];
		$completion      = (int) ( $missing_payload['completion'] ?? 0 );

		return $this->build_result(
			$intake,
			array(),
			$missing,
			array(),
			array(),
			'guidance',
			'guidance',
			$guidance,
			1.0,
			false,
			$completion,
			'',
			$case_profile
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
		$workflow_state = $this->resolve_workflow_state(
			$intake,
			(string) ( $stage_ctx['procedural_node'] ?? '' ),
			$intake->to_case_profile( $completion )
		);

		return array(
			'issue'                 => $issue,
			'facts'                 => $facts,
			'workflow'              => (string) ( $workflow ?? '' ),
			'missing_fields'        => $missing,
			'completion'            => $completion,
			'stage_context'         => $stage_ctx,
			'procedural_navigator'  => $navigator,
			'workflow_resolved'     => null !== $workflow && '' !== $workflow,
			'intake_complete'       => ! empty( $workflow_state['intake_complete'] ),
			'candidate_workflows'   => $candidates,
			'routing_status'        => (string) ( $resolved['routing_status'] ?? '' ),
			'procedural_node'       => (string) ( $stage_ctx['procedural_node'] ?? $workflow_state['procedural_node'] ?? '' ),
			'workflow_state'        => $workflow_state,
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
	 * Build procedural next-step guidance from stage context and roadmap.
	 *
	 * @param array<string, mixed> $stage_ctx Stage context.
	 * @param array<string, mixed> $roadmap   Procedural roadmap snapshot.
	 * @param string               $workflow  Workflow key.
	 * @return string
	 */
	private function build_next_step_guidance_reply( array $stage_ctx, array $roadmap, string $workflow ): string {
		$parts = array();

		$stage_title = trim( (string) ( $stage_ctx['current_stage']['title'] ?? '' ) );
		$stage_desc  = trim( (string) ( $stage_ctx['current_stage']['description'] ?? '' ) );
		$next_action = trim( (string) ( $stage_ctx['next_action']['message'] ?? '' ) );

		if ( '' !== $stage_title ) {
			/* translators: %s: procedural stage title. */
			$parts[] = sprintf( __( 'You are at the %s stage.', 'prose-core' ), $stage_title );
		}

		if ( '' !== $next_action ) {
			$parts[] = $next_action;
		} elseif ( '' !== $stage_desc ) {
			$parts[] = $stage_desc;
		}

		$next_likely = is_array( $roadmap['next_likely_step'] ?? null ) ? $roadmap['next_likely_step'] : array();
		$next_title  = trim( (string) ( $next_likely['title'] ?? '' ) );
		$next_desc   = trim( (string) ( $next_likely['description'] ?? '' ) );

		if ( '' !== $next_title ) {
			$joined = strtolower( implode( ' ', $parts ) );

			if ( ! str_contains( $joined, strtolower( $next_title ) ) ) {
				/* translators: 1: next stage title, 2: optional next stage description. */
				$parts[] = sprintf(
					__( 'After this, the next step is typically %1$s.%2$s', 'prose-core' ),
					$next_title,
					'' !== $next_desc ? ' ' . $next_desc : ''
				);
			}
		}

		if ( ! empty( $stage_ctx['forms_visible'] ) ) {
			$forms = array_filter(
				(array) ( $stage_ctx['stage_forms'] ?? array() ),
				static function ( $form ): bool {
					return is_array( $form ) && ( ! empty( $form['download_url'] ) || ! empty( $form['applicable'] ) );
				}
			);

			$codes = array_values(
				array_filter(
					array_map(
						static function ( array $form ): string {
							return strtoupper( trim( (string) ( $form['code'] ?? '' ) ) );
						},
						$forms
					)
				)
			);

			if ( ! empty( $codes ) ) {
				$parts[] = sprintf(
					/* translators: %s: comma-separated form codes. */
					__( 'Use Get Documents in Case Actions to download %s.', 'prose-core' ),
					implode( ', ', array_slice( $codes, 0, 4 ) )
				);
			}
		}

		$follow_up = trim( (string) ( $roadmap['suggested_next_question'] ?? '' ) );

		if ( '' !== $follow_up ) {
			$parts[] = __( 'One detail that would help refine your path:', 'prose-core' ) . ' ' . $follow_up;
		}

		if ( empty( $parts ) && '' !== $workflow ) {
			return $this->build_guidance_fallback( $workflow, 100 );
		}

		return implode( "\n\n", $parts );
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

		unset( $workflow );
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
	private function reconcile_reply_after_intake( string $reply, array $applied, array $missing, ?string $workflow ): string {
		$reply = trim( $reply );

		if ( '' === $reply || empty( $applied ) ) {
			return $reply;
		}

		$conversation_missing = $this->conversation_missing( $missing, $workflow );
		$missing_keys         = array_map(
			static function ( array $field ): string {
				return (string) ( $field['key'] ?? $field['field'] ?? '' );
			},
			$conversation_missing
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
			$next = $this->build_gathering_fallback( $conversation_missing, $workflow );

			if ( '' === $next ) {
				return '' !== $ack ? $ack . '.' : $reply;
			}

			return ( '' !== $ack ? rtrim( $ack, '.' ) . '. ' : '' ) . $next;
		}

		return $reply;
	}

	/**
	 * Acknowledge newly confirmed facts and continue partial intake without restarting.
	 *
	 * @param string                                                            $reply                 Model reply.
	 * @param array<string, array{value: mixed, confidence: float, confirmed?: bool}> $applied               Applied updates.
	 * @param array<int, array<string, mixed>>                                  $conversation_missing  Remaining routing gaps.
	 * @param string|null                                                       $workflow              Resolved workflow.
	 * @param array<string, mixed>                                              $stage_ctx             Stage context.
	 * @return string
	 */
	private function reconcile_partial_intake_continuity(
		string $reply,
		array $applied,
		array $conversation_missing,
		?string $workflow,
		array $stage_ctx
	): string {
		if ( empty( $applied ) || ( null !== $workflow && '' !== $workflow ) ) {
			return $reply;
		}

		$acks = array();

		foreach ( $applied as $key => $update ) {
			if ( ! is_array( $update ) ) {
				continue;
			}

			$ack = Routing_Discriminator_Catalog::confirmed_acknowledgment( (string) $key, $update['value'] ?? null );

			if ( '' !== $ack ) {
				$acks[] = "\u{2713} " . $ack;
			}
		}

		if ( empty( $acks ) ) {
			return $reply;
		}

		$acks = array_values( array_unique( $acks ) );
		$lead = __( 'Thank you.', 'prose-core' ) . "\n\n" . implode( "\n", $acks );

		if ( ! empty( $conversation_missing ) ) {
			$lead .= "\n\n" . __( 'I still need to understand:', 'prose-core' );

			foreach ( Routing_Discriminator_Catalog::gathering_bullets( $conversation_missing ) as $bullet ) {
				$lead .= "\n\n• " . $bullet;
			}

			$stage_title = trim( (string) ( $stage_ctx['current_stage']['title'] ?? '' ) );

			if ( '' !== $stage_title ) {
				/* translators: %s: procedural stage title. */
				$lead .= "\n\n" . sprintf(
					__( 'While we clarify those details, here is how the %s stage generally works in New York.', 'prose-core' ),
					$stage_title
				);
			}
		}

		$reply = trim( $reply );

		if ( '' === $reply ) {
			return $lead;
		}

		foreach ( $acks as $ack ) {
			if ( str_contains( $reply, substr( $ack, 2 ) ) ) {
				return $reply;
			}
		}

		return $lead . "\n\n" . $reply;
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
	 * @param array<int, array<string, mixed>> $missing  Missing fields.
	 * @param string|null                      $workflow Resolved workflow key.
	 * @return string
	 */
	private function build_gathering_fallback( array $missing, ?string $workflow = null ): string {
		$conversation = $this->conversation_missing( $missing, $workflow );
		$issue        = null;

		return Routing_Discriminator_Catalog::conversational_gathering_prompt( $conversation, $issue );
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

		$case_profile_extra = $this->preserve_case_profile_progress( $case_profile_extra );

		return array(
			'intent'               => $intent,
			'fact_updates'         => $applied,
			'missing_fields'       => $missing_fields,
			'case_memory'          => (array) ( $case_profile_extra['case_memory'] ?? array() ),
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
			'quick_answers'        => $this->resolve_quick_answers(
				(array) ( $case_profile_extra['case_memory']['missing_information'] ?? array() ),
				$state->pending_field()
			),
			'quick_suggestions'    => $this->resolve_quick_suggestions(
				(array) ( $case_profile_extra['case_memory']['missing_information'] ?? array() ),
				(string) ( $state->issue() ?? '' )
			),
			'quick_answer_groups'  => array(),
		);
	}

	/**
	 * Natural-language quick suggestions — optional helpers, not the conversation.
	 *
	 * @param array<int, array<string, mixed>> $conversation_missing Conversation gaps.
	 * @param string                           $issue                Issue hint.
	 * @return array<int, array{label: string, value: string, field: string}>
	 */
	private function resolve_quick_suggestions( array $conversation_missing, string $issue ): array {
		return Routing_Discriminator_Catalog::quick_suggestions( $conversation_missing, $issue );
	}

	/**
	 * @deprecated Use resolve_quick_suggestions().
	 *
	 * @param array<int, array<string, mixed>> $conversation_missing Conversation gaps.
	 * @return array<int, array<string, mixed>>
	 */
	private function resolve_quick_answer_groups( array $conversation_missing ): array {
		return array();
	}

	/**
	 * Flat quick answers for backward compatibility (single-topic turns only).
	 *
	 * @param array<int, array<string, mixed>> $conversation_missing Conversation gaps.
	 * @param string                            $pending_field        Pending field hint.
	 * @return array<int, array{label: string, value: string}>
	 */
	private function resolve_quick_answers( array $conversation_missing, string $pending_field ): array {
		$suggestions = $this->resolve_quick_suggestions( $conversation_missing, '' );

		if ( ! empty( $suggestions ) ) {
			return array();
		}

		if ( 1 !== count( $conversation_missing ) ) {
			return array();
		}

		$field = $conversation_missing[0];
		$key   = (string) ( $field['key'] ?? $field['field'] ?? $pending_field );

		if ( '' === $key ) {
			return array();
		}

		return \ProSe\Core\Intake\Question_Selector::quick_answers_for_field(
			$key,
			(string) ( $field['type'] ?? null )
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
	 * @param array<int, array<string, mixed>> $missing  Missing fields.
	 * @param string|null                        $workflow Workflow key.
	 * @return array<int, array<string, mixed>>
	 */
	private function conversation_missing( array $missing, ?string $workflow ): array {
		return $this->fields_provider->conversation_missing_fields( $missing, $workflow );
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

		if ( $this->is_conversational_closing_message( $message ) ) {
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

		$groups = is_array( $stage_ctx['form_groups'] ?? null ) ? $stage_ctx['form_groups'] : array();
		$grouped_text = ( new Stage_Form_Group_Presenter() )->format_groups_text( $groups );

		if ( '' !== $grouped_text ) {
			$message = sprintf(
				/* translators: %s: procedural stage title. */
				__( 'For the %s stage:', 'prose-core' ),
				$title
			);

			$message .= "\n\n" . $grouped_text;
			$message .= "\n\n" . __( 'Informational guidance only — not legal advice.', 'prose-core' );

			return $message;
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
		bool $has_workflow,
		array $roadmap = array(),
		?string $workflow = null
	): string {
		if ( $this->message_asks_current_stage_forms( $message ) ) {
			return $reply;
		}

		if ( ! $has_workflow || ! $this->message_requests_guidance( $message ) ) {
			return $reply;
		}

		$should_replace = '' === trim( $reply )
			|| $this->reply_is_generic_gathering_prompt( $reply )
			|| $this->reply_only_asks_optional_fields( $reply );

		if ( ! $should_replace ) {
			return $reply;
		}

		$guidance = $this->build_next_step_guidance_reply( $stage_ctx, $roadmap, (string) $workflow );

		return '' !== trim( $guidance ) ? $guidance : $reply;
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
			'could you tell me a bit more about your legal matter',
			'could you tell me a bit more about your situation',
			'tell me more about your situation',
			'help you find the right path',
			'point you in the right direction',
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
	 * Keep user-confirmed procedural progress across conversational turns.
	 *
	 * @param array<string, mixed> $extra Mutable case profile extras for build_result().
	 * @return array<string, mixed>
	 */
	private function preserve_case_profile_progress( array $extra ): array {
		$pinned = $this->pinned_case_profile;

		if ( empty( $pinned ) ) {
			return $extra;
		}

		if ( ! empty( $pinned['completed_documents'] ) && is_array( $pinned['completed_documents'] ) ) {
			$extra['completed_documents'] = $pinned['completed_documents'];
		}

		foreach ( array( 'roadmap', 'roadmap_fingerprint', 'guidance_brief_delivered' ) as $key ) {
			if ( array_key_exists( $key, $pinned ) && null !== $pinned[ $key ] && '' !== $pinned[ $key ] ) {
				$extra[ $key ] = $pinned[ $key ];
			}
		}

		$pinned_node = trim( (string) ( $pinned['procedural_node'] ?? '' ) );

		if ( '' !== $pinned_node ) {
			$extra['procedural_node'] = $pinned_node;
		}

		if ( is_array( $pinned['workflow_state'] ?? null ) && ! empty( $pinned['workflow_state'] ) ) {
			$workflow_state = $pinned['workflow_state'];

			if ( '' !== $pinned_node ) {
				$workflow_state['procedural_node'] = $pinned_node;
			}

			$extra['workflow_state'] = $workflow_state;
		}

		return $extra;
	}

	/**
	 * Prefer the stored procedural node when the user has confirmed stage completions.
	 *
	 * @param array<string, mixed> $case_profile   Incoming case profile.
	 * @param string               $resolved_node  Node from workflow state resolver.
	 * @return string
	 */
	private function prefer_pinned_procedural_node( array $case_profile, string $resolved_node ): string {
		$pinned_node = trim( (string) ( $case_profile['procedural_node'] ?? '' ) );

		if ( '' === $pinned_node ) {
			return $resolved_node;
		}

		if ( Completed_Stage_Document_Store::completed_stage_count( $case_profile ) > 0 ) {
			return $pinned_node;
		}

		return $resolved_node;
	}

	/**
	 * @param string $message User message.
	 * @return bool
	 */
	private function is_conversational_closing_message( string $message ): bool {
		$text = strtolower( trim( $message ) );

		if ( '' === $text || strlen( $text ) > 80 || str_contains( $text, '?' ) ) {
			return false;
		}

		if ( $this->message_requests_guidance( $message ) ) {
			return false;
		}

		$normalized = trim( preg_replace( '/[^\p{L}\p{N}\s\']/u', '', $text ) ?? $text );

		$patterns = array(
			'/^(okay|ok|okey)( thank(?:s| you)( so much)?)?[!.]*$/',
			'/^thank(?:s| you)( so much)?[!.]*$/',
			'/^(got it|sounds good|perfect|great|awesome|cool|alright|all right)[!.]*$/',
			'/^thank(?:s| you),? (okay|ok|got it|sounds good|perfect)[!.]*$/',
			'/^(okay|ok),? thank(?:s| you)[!.]*$/',
		);

		foreach ( $patterns as $pattern ) {
			if ( preg_match( $pattern, $normalized ) ) {
				return true;
			}
		}

		if ( preg_match( '/\b(thank you|thanks)\b/', $normalized )
			&& ! preg_match( '/\b(what|how|which|when|where|why|can you|do i|need to|should i)\b/', $normalized ) ) {
			return strlen( $normalized ) <= 48;
		}

		return false;
	}

	/**
	 * @return string
	 */
	private function build_closing_acknowledgment_reply(): string {
		return __( 'You\'re welcome! If you have more questions about your case or any form, just ask.', 'prose-core' );
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
			'what is need to do',
			'what i need to do',
			'what to do next',
			'what should i do next',
			'what should i do',
			'what now',
			'need to do now',
			'need to do next',
			"what's next",
			'whats next',
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

		$workflow_state = $this->resolve_workflow_state( $intake, $procedural_node, $case_profile );
		$procedural_node = (string) ( $workflow_state['procedural_node'] ?? $procedural_node );
		$facts     = $intake->plain_facts();
		$resolved  = $this->fields_provider->resolve( $intake, '' );
		$stage_ctx = $this->workflow_engine->determine_stage(
			$workflow,
			$facts,
			$procedural_node,
			! empty( $workflow_state['intake_complete'] ),
			(array) ( $resolved['required_field_defs'] ?? array() ),
			Completed_Stage_Document_Store::completed_stage_count( $case_profile )
		);

		if ( empty( $workflow_state['intake_complete'] ) ) {
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

		$groups = is_array( $stage_ctx['form_groups'] ?? null ) ? $stage_ctx['form_groups'] : array();
		$grouped_text = ( new Stage_Form_Group_Presenter() )->format_groups_text( $groups );

		if ( '' !== $grouped_text ) {
			$lines[] = $grouped_text;
		} else {
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
