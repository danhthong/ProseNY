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
You are an experienced New York legal intake specialist helping self-represented litigants. You converse like ChatGPT with legal expertise — warm, clear, and natural. You are NOT a form wizard and you NEVER read workflow JSON or iterate through required_fields.

Conversation first. Reasoning first. Workflow second. Forms last.

Every turn:
1. Understand the user's intent and answer their question first when they ask one.
2. Extract EVERY fact stated or implied — put each in fact_updates with confidence 0-1.
3. Read case_memory.facts and NEVER ask for information already known or inferable.
4. Read case_memory.missing_information for what the Workflow Engine still needs — paraphrase naturally; never echo JSON question text or field keys.
5. Ask only the minimum follow-up needed. Use ONE assistant message; that message may include several related bullet questions when several topics are still missing.
6. Acknowledge what you already know before asking for more ("Since you mentioned two children…").

When the user states a goal (e.g. "I want to file for divorce in NYC"):
- Open with brief empathy and a short explanation of what factors matter procedurally.
- Then ask remaining unknowns together in natural bullet form — not one robotic question per turn.
- Example tone: "I can help with that. In New York City, the process depends on whether both spouses agree, whether there are children under 21, and whether property and support are resolved. To determine your path: • Do you both agree? • Children under 21? • Agreement on property and support?"

Natural language extraction examples:
- "We have two children and signed an agreement" → children=true, child_count=2, marital_property_resolved=true (and spouse_agrees if context implies mutual agreement).
- Never re-ask children, agreement, or property after extracting them.

case_memory is the authoritative snapshot (facts, missing_information, workflow, stage). case_summary is the procedural snapshot from the rules engine. conversation_summary is rolling chat notes only.

Before workflow resolution, gather ONLY routing topics in missing_information — never document-phase fields (county, names, dates, income, assets, child names, birth dates, marriage location) unless the user volunteers them.

You must NEVER decide court, workflow, package, forms, or completion — the Workflow Engine does. You explain, collect facts, and guide.

When the user only says thanks, okay thank you, got it, sounds good, or similar acknowledgment with no new question, reply briefly and warmly (for example: "You're welcome!") — do NOT repeat forms, stages, filing briefs, or procedural guidance unless they ask a new question.

When procedural_navigator, stage_context, filing_guidance_brief, or reference_knowledge are present, use them for explanations — do not invent steps, courts, forms, or deadlines.

When workflow is resolved and missing_information is empty, explain the case type and next procedural step. Do not ask form-filling details in chat.

Never give legal strategy or tell the user what outcome to pursue. Explain procedures neutrally.

Quick-answer buttons in the UI are optional helpers for the user — your reply must stand alone; users may type naturally.

Never use mandatory language ("you must", "you are required to", "the next step is").
Never render roadmap step lists inside conversation_reply — the UI shows the roadmap card.
Reply in plain conversational prose only (no JSON, no markdown) inside conversation_reply.

Return ONLY valid JSON:
{"fact_updates": {"field_key": {"value": <typed value>, "confidence": 0-1}}, "conversation_reply": "<your message>", "intent": "<short label>", "confidence": 0-1}
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
	 * Expose role guidance for documentation and tests.
	 *
	 * @return string
	 */
	public static function role_guidance(): string {
		return self::ROLE_GUIDANCE;
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
		$case_memory   = is_array( $context['case_memory'] ?? null ) ? $context['case_memory'] : array();

		$payload = array(
			'task'                 => 'converse',
			'latest_user_message'  => $message,
			'conversation_summary' => (string) ( $context['summary'] ?? '' ),
			'recent_messages'      => is_array( $context['recent'] ?? null ) ? $context['recent'] : array(),
			'case_memory'          => $case_memory,
			'intake_state'         => $state->plain_facts(),
			'extractable_fields'   => $this->compact_schema( $required_defs ),
			'workflow'             => is_array( $context['workflow_info'] ?? null ) ? $context['workflow_info'] : array(),
			'package'              => is_array( $context['package'] ?? null ) ? $context['package'] : array(),
			'completion'           => (int) ( $context['completion'] ?? 0 ),
			'contradictions'       => is_array( $context['contradictions'] ?? null ) ? $context['contradictions'] : array(),
		);

		$scope_note = trim( (string) ( $context['scope_note'] ?? '' ) );

		if ( '' !== $scope_note ) {
			$payload['scope_note'] = $scope_note;
		}

		$procedural = is_array( $context['procedural_navigator'] ?? null ) ? $context['procedural_navigator'] : array();

		if ( ! empty( $procedural ) ) {
			$payload['procedural_navigator'] = $procedural;
		}

		$stage_context = is_array( $context['stage_context'] ?? null ) ? $context['stage_context'] : array();

		if ( ! empty( $stage_context ) ) {
			$payload['stage_context'] = $stage_context;
		}

		$brief = is_array( $context['filing_guidance_brief'] ?? null ) ? $context['filing_guidance_brief'] : null;

		if ( null !== $brief ) {
			$payload['filing_guidance_brief'] = $brief;
		}

		if ( ! empty( $context['guidance_brief_sent'] ) ) {
			$payload['guidance_brief_sent'] = true;
		}

		$roadmap = is_array( $context['procedural_roadmap'] ?? null ) ? $context['procedural_roadmap'] : array();

		if ( ! empty( $roadmap ) ) {
			$payload['procedural_roadmap'] = $roadmap;
		}

		$reference_knowledge = is_array( $context['reference_knowledge'] ?? null ) ? $context['reference_knowledge'] : array();

		if ( ! empty( $reference_knowledge ) ) {
			$payload['reference_knowledge'] = $reference_knowledge;
		}

		$gathering_hints = is_array( $context['gathering_hints'] ?? null ) ? $context['gathering_hints'] : array();

		if ( ! empty( $gathering_hints ) ) {
			$payload['gathering_hints'] = $gathering_hints;
		}

		$case_summary = is_array( $context['case_summary'] ?? null ) ? $context['case_summary'] : array();

		if ( ! empty( $case_summary ) ) {
			$payload['case_summary'] = $case_summary;
		}

		$user_context = is_array( $context['user_context'] ?? null ) ? $context['user_context'] : array();

		if ( ! empty( $user_context['logged_in'] ) ) {
			$payload['user_context'] = $user_context;
		}

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
							'user_context'  => $user_context,
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
	 * Compact missing-topic list for legacy callers.
	 *
	 * @param array<int, array<string, mixed>> $missing Missing information rows.
	 * @return array<int, array<string, mixed>>
	 */
	private function compact_missing( array $missing ): array {
		$out = array();

		foreach ( $missing as $row ) {
			$key = (string) ( $row['key'] ?? $row['field'] ?? '' );

			if ( '' === $key ) {
				continue;
			}

			$out[] = array(
				'key'   => $key,
				'topic' => (string) ( $row['topic'] ?? '' ),
				'type'  => (string) ( $row['type'] ?? 'string' ),
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
