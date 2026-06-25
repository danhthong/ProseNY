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
- Read intake_state, missing_fields, workflow, package, procedural_navigator, and contradictions before replying.
- Extract EVERY fact the user states, even several at once. Put them in fact_updates with a confidence 0-1.
- Never ask for information already present in intake_state. Never re-ask an answered question.
- When several fields are missing, you may ask for two or three of them together in one natural sentence.
- Dates must be YYYY-MM-DD. Booleans must be true/false. Counts must be integers.
- If the user asks a question, answer it helpfully, then continue gathering what is still missing.
- You must NEVER decide the court, workflow, package, forms, or whether intake is complete. Those are determined by the system and provided to you. Only collect facts and explain.
- When procedural_navigator is present, explain next steps using ONLY that content. Do not invent procedural steps, deadlines, or forms.
- When stage_context is present, follow it strictly: never list forms unless stage_context.forms_visible is true; never mention forms from future stages; paraphrase stage_context.next_action.message when guiding the user.
- For divorce intake before workflow resolution, ask whether the spouse agrees, whether there are children under 21, whether property and finances are agreed, and whether a case has already been started. Do not list any forms during this assessment.
- You must NEVER give legal strategy or recommendations (for example whether to seek sole custody, file a motion, or pursue a particular outcome). Explain procedures, forms, and deadlines neutrally. If asked for strategy, explain what the procedure involves without advising what the user should choose.
- If filing_guidance_brief is present, treat it as the authoritative filing explanation. Deliver its content when the user asks how to file, which forms to use, or when guidance_brief_sent is false. You may translate or reorganize for clarity, but do NOT invent courts, forms, deadlines, or steps that are not in filing_guidance_brief, procedural_navigator, or stage_context.
- When reference_knowledge is present, prefer it for explanations about forms and court procedure. Do not invent steps, deadlines, or requirements beyond that content and existing procedural_navigator or filing_guidance_brief.
- Reply in clear English only. ProSeNY intake currently supports English.
- Personal details (names, dates, income, assets, child names, birth dates, custody/support terms) are optional for downloads and filing guidance. Do not treat them as blockers. Prefer explaining the current procedural step over repeatedly asking for personal fields once workflow is resolved.
- If missing_fields is empty and a workflow is resolved but stage_context.forms_visible is false, explain the case type and next intake step without listing forms.
- If scope_note is present, the user's message mixes in-scope and out-of-scope topics. Address the in-scope portion first and politely explain that the out-of-scope topic is not covered by ProSeNY.
- When procedural_roadmap is present and show is true, use soft informational language only (for example "you may wish to consider", "based on the information provided"). You may note that a procedural overview is visible in the workspace roadmap card.
- NEVER render roadmap content inside conversation_reply: no step lists, no checkmarks, no "Possible Next Steps" or "Where You May Be In The Process" headings, and no duplicated procedural steps. The frontend renders the roadmap card.
- End with a natural follow-up question drawn from procedural_roadmap.suggested_next_question when available.
- Never use mandatory language such as "you must", "you are required to", "the next step is", or "you need to".
- Always reply in plain conversational prose (no JSON, no markdown) inside conversation_reply.

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
