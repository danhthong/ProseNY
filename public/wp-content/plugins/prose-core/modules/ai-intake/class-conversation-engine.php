<?php
/**
 * Conversation Engine — single-call conversational intake.
 *
 * Performs one OpenAI call per user turn that simultaneously extracts every
 * fact present in the message and produces a natural conversational reply. The
 * model receives the full deterministic context (known facts, missing fields,
 * workflow, package, contradictions, conversation memory) and decides how to
 * continue the conversation. It never makes legal determinations — ProSe owns
 * court, workflow, package, forms, and completion.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Ai_Intake;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Conversation_Engine
 */
final class Conversation_Engine {

	/**
	 * Conversational role guidance appended to the system prompt.
	 */
	private const ROLE_GUIDANCE = <<<'TXT'
You are a knowledgeable, warm legal intake specialist for a New York self-represented litigant platform. Hold a natural conversation — never behave like a form wizard or read a fixed list of questions.

Rules:
- Read intake_state, missing_fields, workflow, package, and contradictions before replying.
- Extract EVERY fact the user states, even several at once. Put them in fact_updates with a confidence 0-1.
- Never ask for information already present in intake_state. Never re-ask an answered question.
- When several fields are missing, you may ask for two or three of them together in one natural sentence.
- Dates must be YYYY-MM-DD. Booleans must be true/false. Counts must be integers.
- If the user asks a question, answer it helpfully, then continue gathering what is still missing.
- You must NEVER decide the court, workflow, package, forms, or whether intake is complete. Those are determined by the system and provided to you. Only collect facts and explain.
- If missing_fields is empty and a workflow is resolved, do not ask more intake questions. Confirm you have enough information and briefly explain the next steps using the provided workflow and package details.
- Always reply in plain conversational English (no JSON, no markdown) inside conversation_reply.

Return ONLY valid JSON of the form:
{"fact_updates": {"field_key": {"value": <typed value>, "confidence": 0-1}}, "conversation_reply": "<your message to the user>", "intent": "<short label>", "confidence": 0-1}
TXT;

	/**
	 * AI settings.
	 *
	 * @var AI_Settings
	 */
	private AI_Settings $settings;

	/**
	 * Fact extractor (for shared normalization).
	 *
	 * @var Fact_Extractor
	 */
	private Fact_Extractor $extractor;

	/**
	 * Constructor.
	 *
	 * @param AI_Settings|null    $settings  Settings.
	 * @param Fact_Extractor|null $extractor Fact extractor.
	 */
	public function __construct( ?AI_Settings $settings = null, ?Fact_Extractor $extractor = null ) {
		$this->settings  = $settings ?? new AI_Settings();
		$this->extractor = $extractor ?? new Fact_Extractor( $this->settings );
	}

	/**
	 * Run one conversational turn.
	 *
	 * @param string                $message  User message.
	 * @param Intake_State          $state    Intake state.
	 * @param array<string, mixed>  $context  Deterministic context {extraction_defs, missing, workflow, workflow_info, package, completion, contradictions, summary, recent}.
	 * @param Ai_Provider_Interface $provider AI provider.
	 * @param AI_Logger|null        $logger   Optional logger.
	 * @return array{updates: array<string, array{value: mixed, confidence: float}>, low_confidence: array<string, array{value: mixed, confidence: float}>, reply: string, raw_confidence: float, intent: string}
	 */
	public function converse(
		string $message,
		Intake_State $state,
		array $context,
		Ai_Provider_Interface $provider,
		?AI_Logger $logger = null
	): array {
		$required_defs = is_array( $context['extraction_defs'] ?? null ) ? $context['extraction_defs'] : array();

		$payload = array(
			'task'                 => 'converse',
			'latest_user_message'  => $message,
			'conversation_summary' => (string) ( $context['summary'] ?? '' ),
			'recent_messages'      => is_array( $context['recent'] ?? null ) ? $context['recent'] : array(),
			'intake_state'         => $state->plain_facts(),
			'pending_field'        => $state->pending_field(),
			'missing_fields'       => $this->compact_missing( is_array( $context['missing'] ?? null ) ? $context['missing'] : array() ),
			'extractable_fields'   => $this->compact_schema( $required_defs ),
			'workflow'             => is_array( $context['workflow_info'] ?? null ) ? $context['workflow_info'] : array(),
			'package'              => is_array( $context['package'] ?? null ) ? $context['package'] : array(),
			'completion'           => (int) ( $context['completion'] ?? 0 ),
			'contradictions'       => is_array( $context['contradictions'] ?? null ) ? $context['contradictions'] : array(),
		);

		$messages = array(
			array(
				'role'    => 'system',
				'content' => $this->settings->system_prompt() . "\n\n" . self::ROLE_GUIDANCE,
			),
			array(
				'role'    => 'user',
				'content' => wp_json_encode( $payload ),
			),
		);

		try {
			$response = $provider->complete(
				$messages,
				array_merge(
					$this->settings->provider_options(),
					array(
						'mode'            => 'converse',
						'response_format' => 'json_object',
						'context'         => array(
							'pending_field' => $state->pending_field(),
							'facts'         => $state->plain_facts(),
						),
					)
				)
			);
		} catch ( \Throwable $e ) {
			$this->settings->record_error( $e->getMessage() );

			return array(
				'updates'        => array(),
				'low_confidence' => array(),
				'reply'          => '',
				'raw_confidence' => 0.0,
				'intent'         => 'error',
			);
		}

		if ( null !== $logger ) {
			$logger->log(
				array(
					'type'       => 'converse',
					'latency_ms' => (int) ( $response['latency_ms'] ?? 0 ),
					'prompt'     => $messages,
					'response'   => (string) ( $response['content'] ?? '' ),
				)
			);
		}

		$this->settings->record_request(
			array(
				'type'       => 'converse',
				'latency_ms' => (int) ( $response['latency_ms'] ?? 0 ),
				'provider'   => $provider->name(),
			)
		);

		$decoded = $this->decode( (string) ( $response['content'] ?? '' ) );

		$processed = $this->extractor->process_raw(
			is_array( $decoded['fact_updates'] ?? null ) ? $decoded['fact_updates'] : array(),
			(float) ( $decoded['confidence'] ?? 0.0 ),
			(string) ( $decoded['intent'] ?? 'converse' ),
			$message,
			$required_defs,
			$state
		);

		return array(
			'updates'        => $processed['updates'],
			'low_confidence' => $processed['low_confidence'],
			'reply'          => trim( (string) ( $decoded['conversation_reply'] ?? '' ) ),
			'raw_confidence' => (float) $processed['raw_confidence'],
			'intent'         => (string) $processed['intent'],
		);
	}

	/**
	 * Decode a JSON payload from raw model output.
	 *
	 * @param string $content Raw content.
	 * @return array<string, mixed>
	 */
	private function decode( string $content ): array {
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
	 * Compact missing-field list for the prompt.
	 *
	 * @param array<int, array<string, mixed>> $missing Missing fields.
	 * @return array<int, array<string, mixed>>
	 */
	private function compact_missing( array $missing ): array {
		$out = array();

		foreach ( $missing as $field ) {
			$key = (string) ( $field['field'] ?? '' );

			if ( '' === $key ) {
				continue;
			}

			$out[] = array(
				'field'    => $key,
				'question' => (string) ( $field['question'] ?? '' ),
				'type'     => (string) ( $field['type'] ?? 'string' ),
			);
		}

		return $out;
	}

	/**
	 * Compact extractable-field schema for the prompt.
	 *
	 * @param array<int, array<string, mixed>> $defs Required field defs.
	 * @return array<int, array<string, mixed>>
	 */
	private function compact_schema( array $defs ): array {
		$out = array();

		foreach ( $defs as $def ) {
			$key = (string) ( $def['key'] ?? '' );

			if ( '' === $key ) {
				continue;
			}

			$out[] = array(
				'key'  => $key,
				'type' => (string) ( $def['type'] ?? 'string' ),
			);
		}

		return $out;
	}
}
