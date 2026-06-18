<?php
/**
 * AI Intake Interpreter — orchestrates conversational intake.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Ai_Intake;

use ProSe\Core\Intake\Completion_Calculator;
use ProSe\Core\Intake\Document_Request_Detector;

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
		?Document_Request_Detector $documents = null
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

		if ( '' === $intake->conversation_id() ) {
			$intake->set_conversation_id( $this->generate_conversation_id() );
		}

		if ( '' === $intake->conversation_summary() ) {
			$intake->set_conversation_summary( $this->memory->fallback_summary( $intake ) );
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

		// Direct path: the user wants blank forms and does not want to answer
		// intake questions. Blank forms need no facts — only which packet/forms.
		// The AI never decides forms here; routing and the forms catalog do.
		$direct = $this->detect_direct_request( $message );

		if ( ! empty( $direct['codes'] ) ) {
			$download = $this->merge_forms( $direct['codes'] );

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

			if ( null !== $workflow_direct && '' !== $workflow_direct ) {
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
					'offer_package',
					$this->direct_package_message( $workflow_direct ),
					1.0,
					false,
					$completion_direct
				);
			}
			// Not routable yet: continue to the normal flow so we can ask the
			// single routing question needed to identify the matter.
		}

		// --- Deterministic pre-resolve (ProSe owns routing & required fields) ---
		$memory_ctx   = $this->memory->context( $intake, $conversation );
		$resolved_pre = $this->fields_provider->resolve( $intake, $message );
		$missing_pre  = $this->fields_provider->missing_prioritized( $resolved_pre['fields'], $intake );
		$workflow_pre = $intake->workflow();
		$was_complete = empty( $missing_pre ) && null !== $workflow_pre && '' !== $workflow_pre;

		$completion_pre = $this->completion->calculate(
			$resolved_pre['required_field_defs'],
			$intake->plain_facts()
		);

		// --- Single conversational OpenAI call: extract facts + write reply ---
		$turn = $this->engine->converse(
			$message,
			$intake,
			array(
				'extraction_defs' => $resolved_pre['extraction_defs'] ?? $resolved_pre['required_field_defs'],
				'missing'         => $missing_pre,
				'workflow'        => $workflow_pre,
				'workflow_info'   => $this->workflow_info( $workflow_pre, $completion_pre ),
				'package'         => $this->package_context( $missing_pre, $completion_pre, $workflow_pre ),
				'completion'      => $completion_pre,
				'contradictions'  => $this->consistency->check( $intake ),
				'summary'         => $memory_ctx['summary'],
				'recent'          => $memory_ctx['recent'],
			),
			$this->provider,
			$this->logger
		);

		$applied = $intake->merge_updates( $turn['updates'], $this->is_correction_message( $message ) );
		$this->sync_child_facts( $intake );

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

		// --- Escalation safety net (repeated genuine uncertainty) ---
		$escalation = $this->escalation->detect( $message, $intake, $turn['raw_confidence'] );

		if ( $escalation['needs_review'] ) {
			return $this->build_result(
				$intake,
				$applied,
				$missing,
				$contradictions,
				array(),
				'needs_review',
				'needs_review',
				'' !== $reply ? $reply : __( 'Thanks for sharing. A member of our team can help you from here.', 'prose-core' ),
				$turn['raw_confidence'],
				true,
				$completion_pct
			);
		}

		// --- Intake complete: transition to guidance (only when facts are consistent) ---
		if ( empty( $missing ) && $has_workflow && empty( $contradictions ) ) {
			$intake->set_pending_field( '' );

			if ( '' === $reply ) {
				$reply = $this->build_guidance_fallback( $workflow, $completion_pct );
			}

			return $this->build_result(
				$intake,
				$applied,
				array(),
				$contradictions,
				array(),
				'intake_complete',
				$was_complete ? 'guidance' : 'complete_intake',
				$reply,
				1.0,
				false,
				100
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

		$pending_hint = ! empty( $missing ) ? (string) $missing[0]['field'] : '';

		return $this->build_result(
			$intake,
			$applied,
			$missing,
			$contradictions,
			array(),
			'gathering',
			'ask_question',
			$reply,
			$turn['raw_confidence'],
			false,
			$completion_pct,
			$pending_hint
		);
	}

	/**
	 * Build a compact workflow context object for the conversation engine.
	 *
	 * @param string|null $workflow   Workflow key.
	 * @param int         $completion Completion percentage.
	 * @return array<string, mixed>
	 */
	private function workflow_info( ?string $workflow, int $completion ): array {
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

		$forms = array();

		foreach ( $this->workflows->required_form_codes( $definition ) as $code ) {
			$forms[] = $code;
		}

		return array(
			'resolved'             => true,
			'key'                  => $workflow,
			'title'                => (string) ( $definition['description'] ?? '' ),
			'court'                => (string) ( $definition['court'] ?? '' ),
			'stages'               => is_array( $definition['stages'] ?? null ) ? $definition['stages'] : array(),
			'required_form_codes'  => $forms,
			'supporting_documents' => is_array( $definition['supporting_documents'] ?? null ) ? $definition['supporting_documents'] : array(),
			'completion'           => $completion,
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
			__( 'Good news — I have everything I need for your %s. The next step is preparing your filing package, which you can review and download below. Ask me anything about the forms or what happens after you file.', 'prose-core' ),
			/* translators: %s: matter description. */
			__( 'That covers it for your %s. You can review and download your filing package below whenever you are ready. Want me to walk you through the forms or the next steps?', 'prose-core' ),
			/* translators: %s: matter description. */
			__( 'We are all set on your %s. Your forms are ready to review and download below. I am still here if you have questions about filing, deadlines, or anything on the forms.', 'prose-core' ),
			/* translators: %s: matter description. */
			__( 'I have enough to move forward with your %s. Take a look at the filing package below — and feel free to ask me how to file it or what to expect next.', 'prose-core' ),
			/* translators: %s: matter description. */
			__( 'Your %s intake is complete. The prepared forms are below for review and download. Let me know if you would like help understanding any form or the filing process.', 'prose-core' ),
		);

		$index = function_exists( 'wp_rand' ) ? wp_rand( 0, count( $templates ) - 1 ) : array_rand( $templates );

		return sprintf( $templates[ $index ], $title );
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
		string $pending_field = ''
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
			'case_profile'         => $state->to_case_profile( $completion ),
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
			__( 'No problem — you do not need to answer the questions to get blank forms. Here is the blank packet for %s. Scroll down and click “Download all forms (PDF).”', 'prose-core' ),
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
}
