<?php
/**
 * AI Intake Interpreter — orchestrates conversational intake.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Ai_Intake;

use ProSe\Core\Intake\Completion_Calculator;

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
		?AI_Logger $logger = null
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

		$memory_ctx = $this->memory->context( $intake, $conversation );

		$resolved_pre = $this->fields_provider->resolve( $intake, $message );
		$required_defs = $resolved_pre['extraction_defs'] ?? $resolved_pre['required_field_defs'];

		$extraction = $this->extractor->extract(
			$message,
			$intake,
			$required_defs,
			$memory_ctx,
			$this->provider,
			$this->logger
		);

		$applied = $intake->merge_updates( $extraction['updates'] );

		$resolved = $this->fields_provider->resolve( $intake, $message );
		$fields   = $resolved['fields'];
		$missing  = $this->fields_provider->missing_prioritized( $fields, $intake );

		$completion_pct = $this->completion->calculate(
			$resolved['required_field_defs'],
			$intake->plain_facts()
		);

		$contradictions = $this->consistency->check( $intake );

		$clarifications = $this->clarification->build(
			$extraction['low_confidence'],
			$contradictions,
			$intake,
			$message,
			$this->provider
		);

		$escalation = $this->escalation->detect(
			$message,
			$intake,
			$extraction['raw_confidence']
		);

		$this->memory->maybe_update_summary( $intake, $conversation, $this->provider, $this->logger );

		if ( $escalation['needs_review'] ) {
			return $this->build_result(
				$intake,
				$applied,
				$missing,
				$contradictions,
				$clarifications,
				'needs_review',
				'needs_review',
				'',
				$extraction['raw_confidence'],
				true,
				$completion_pct
			);
		}

		if ( ! empty( $clarifications ) ) {
			$first = $clarifications[0];

			return $this->build_result(
				$intake,
				$applied,
				$missing,
				$contradictions,
				$clarifications,
				'clarify',
				'ask_question',
				(string) $first['message'],
				$extraction['raw_confidence'],
				false,
				$completion_pct,
				(string) $first['field']
			);
		}

		if ( empty( $missing ) ) {
			$intake->set_pending_field( '' );

			return $this->build_result(
				$intake,
				$applied,
				array(),
				$contradictions,
				array(),
				'intake_complete',
				'complete_intake',
				'',
				1.0,
				false,
				100
			);
		}

		while ( ! empty( $missing ) && isset( $applied[ (string) $missing[0]['field'] ] ) ) {
			$missing = array_slice( $missing, 1 );
		}

		if ( empty( $missing ) ) {
			$intake->set_pending_field( '' );

			return $this->build_result(
				$intake,
				$applied,
				array(),
				$contradictions,
				array(),
				'intake_complete',
				'complete_intake',
				'',
				$extraction['raw_confidence'],
				false,
				100
			);
		}

		$target   = $missing[0];
		$question = $this->extractor->phrase_question( $target, $intake, $this->provider );
		$intake->set_pending_field( (string) $target['field'] );

		return $this->build_result(
			$intake,
			$applied,
			$missing,
			$contradictions,
			array(),
			$extraction['intent'],
			'ask_question',
			$question,
			$extraction['raw_confidence'],
			false,
			$completion_pct,
			(string) $target['field']
		);
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
