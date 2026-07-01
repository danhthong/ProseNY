<?php
/**
 * Event-driven AI invocation context.
 *
 * Every ChatGPT request carries an event type so the model knows why it was
 * invoked and adapts behavior accordingly. The Workflow Engine remains
 * authoritative for routing, stages, and packages.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Ai_Intake;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Ai_Event_Context
 */
final class Ai_Event_Context {

	public const TYPE_USER_MESSAGE            = 'user_message';
	public const TYPE_WORKFLOW_SELECTED       = 'workflow_selected';
	public const TYPE_STAGE_TRANSITION        = 'stage_transition';
	public const TYPE_DOCUMENTS_GENERATED     = 'documents_generated';
	public const TYPE_FORMS_DOWNLOADED        = 'forms_downloaded';
	public const TYPE_PACKAGE_READY           = 'package_ready';
	public const TYPE_WORKFLOW_UPDATED        = 'workflow_updated';
	public const TYPE_CASE_SUMMARY_REQUESTED  = 'case_summary_requested';
	public const TYPE_STAGE_REVIEW            = 'stage_review';
	public const TYPE_DOCUMENT_REVIEW         = 'document_review';
	public const TYPE_COMPLETION_CONFIRMATION = 'completion_confirmation';

	/**
	 * Supported event types.
	 *
	 * @return string[]
	 */
	public static function supported_types(): array {
		return array(
			self::TYPE_USER_MESSAGE,
			self::TYPE_WORKFLOW_SELECTED,
			self::TYPE_STAGE_TRANSITION,
			self::TYPE_DOCUMENTS_GENERATED,
			self::TYPE_FORMS_DOWNLOADED,
			self::TYPE_PACKAGE_READY,
			self::TYPE_WORKFLOW_UPDATED,
			self::TYPE_CASE_SUMMARY_REQUESTED,
			self::TYPE_STAGE_REVIEW,
			self::TYPE_DOCUMENT_REVIEW,
			self::TYPE_COMPLETION_CONFIRMATION,
		);
	}

	/**
	 * Build a normalized event context array.
	 *
	 * @param string               $type Event type.
	 * @param array<string, mixed> $meta Optional metadata.
	 * @return array{type: string, meta: array<string, mixed>}
	 */
	public static function build( string $type, array $meta = array() ): array {
		$type = self::sanitize_type( $type );

		return array(
			'type' => $type,
			'meta' => is_array( $meta ) ? $meta : array(),
		);
	}

	/**
	 * Normalize explicit client or server event payload.
	 *
	 * @param mixed $raw Raw event (string type or array).
	 * @return array{type: string, meta: array<string, mixed>}
	 */
	public static function normalize( $raw ): array {
		if ( is_string( $raw ) && '' !== trim( $raw ) ) {
			return self::build( $raw );
		}

		if ( ! is_array( $raw ) ) {
			return self::build( self::TYPE_USER_MESSAGE );
		}

		$type = self::sanitize_type( (string) ( $raw['type'] ?? '' ) );
		$meta = is_array( $raw['meta'] ?? null ) ? $raw['meta'] : $raw;
		unset( $meta['type'] );

		return self::build( $type, $meta );
	}

	/**
	 * Resolve the invocation event for a conversational turn.
	 *
	 * @param array<string, mixed> $hints {
	 *     @type array<string, mixed> $state           Request state.
	 *     @type string               $message         User message.
	 *     @type string               $workflow_at_entry Workflow before this turn.
	 *     @type string               $workflow_now    Workflow after pre-resolve.
	 * }
	 * @return array{type: string, meta: array<string, mixed>}
	 */
	public static function resolve( array $hints ): array {
		$state   = is_array( $hints['state'] ?? null ) ? $hints['state'] : array();
		$message = trim( (string) ( $hints['message'] ?? '' ) );

		if ( ! empty( $state['stage_guidance_only'] ) ) {
			return self::build(
				self::TYPE_COMPLETION_CONFIRMATION,
				array(
					'completed_stage' => sanitize_key( (string) ( $state['completed_stage'] ?? '' ) ),
					'source'          => 'stage_guidance_only',
				)
			);
		}

		if ( isset( $state['ai_event'] ) ) {
			return self::normalize( $state['ai_event'] );
		}

		$workflow_at_entry = trim( (string) ( $hints['workflow_at_entry'] ?? '' ) );
		$workflow_now        = trim( (string) ( $hints['workflow_now'] ?? '' ) );

		if ( '' === $workflow_at_entry && '' !== $workflow_now ) {
			return self::build(
				self::TYPE_WORKFLOW_SELECTED,
				array(
					'workflow' => $workflow_now,
				)
			);
		}

		if ( self::message_requests_case_summary( $message ) ) {
			return self::build( self::TYPE_CASE_SUMMARY_REQUESTED );
		}

		if ( self::message_requests_stage_review( $message ) ) {
			return self::build( self::TYPE_STAGE_REVIEW );
		}

		if ( self::message_requests_document_review( $message ) ) {
			return self::build( self::TYPE_DOCUMENT_REVIEW );
		}

		return self::build( self::TYPE_USER_MESSAGE );
	}

	/**
	 * Event-specific instructions appended to the system prompt.
	 *
	 * @param string $type Event type.
	 * @return string
	 */
	public static function instructions_for( string $type ): string {
		$type = self::sanitize_type( $type );

		switch ( $type ) {
			case self::TYPE_WORKFLOW_SELECTED:
				return <<<'TXT'
EVENT: workflow_selected
The Workflow Engine has already determined the correct workflow. Do NOT re-evaluate or question it.
Instead: explain why this workflow fits the user's facts; summarize what you know; introduce the procedural roadmap; explain what happens first.
TXT;

			case self::TYPE_STAGE_TRANSITION:
				return <<<'TXT'
EVENT: stage_transition
The Workflow Engine has already advanced the case to a new stage. Never question the new stage. Never re-explain the previous stage in detail.
Instead: congratulate the user briefly; summarize what has been completed; explain ONLY the newly entered stage; what usually happens now; what to prepare; update the roadmap mentally from context (dashboard blocks are appended for you).
TXT;

			case self::TYPE_DOCUMENTS_GENERATED:
				return <<<'TXT'
EVENT: documents_generated
Explain what documents were generated, why each exists, whether signatures are required, and what should happen before filing.
Do NOT mark the stage complete or assume filing has occurred.
TXT;

			case self::TYPE_FORMS_DOWNLOADED:
				return <<<'TXT'
EVENT: forms_downloaded
Downloading documents does NOT complete a procedural stage. Downloaded ≠ filed.
Explain that documents have been prepared and downloaded. Tell the user: once they have completed this procedural step, they should return and let you know so you can continue.
Do NOT assume filing or stage completion.
TXT;

			case self::TYPE_PACKAGE_READY:
				return <<<'TXT'
EVENT: package_ready
Explain what the package contains, how it will be used, whether anything should be reviewed, and what the next procedural action normally is.
TXT;

			case self::TYPE_WORKFLOW_UPDATED:
				return <<<'TXT'
EVENT: workflow_updated
The Workflow Engine has updated the workflow. Do NOT second-guess the change.
Explain what changed at a high level, why the new path may apply given known facts, and what the user should focus on next.
TXT;

			case self::TYPE_CASE_SUMMARY_REQUESTED:
				return <<<'TXT'
EVENT: case_summary_requested
Generate a complete case overview in conversational prose covering: workflow, current stage, progress, completed stages, known facts, outstanding questions, upcoming documents, and recommended next action. Use case_summary and case_memory as authoritative — do not invent facts.
TXT;

			case self::TYPE_STAGE_REVIEW:
				return <<<'TXT'
EVENT: stage_review
Explain only the current stage — purpose, what to do, forms involved, and immediate next steps. Do NOT explain the entire workflow again.
TXT;

			case self::TYPE_DOCUMENT_REVIEW:
				return <<<'TXT'
EVENT: document_review
Explain each document in the current package: purpose, required vs conditional, who signs, when filed. Do not explain unrelated documents from other stages.
TXT;

			case self::TYPE_COMPLETION_CONFIRMATION:
				return <<<'TXT'
EVENT: completion_confirmation
The user confirmed they completed a procedural step. The Workflow Engine has already advanced the stage — verify that from context and do not question the advance.
Congratulate the user; summarize the completed stage briefly; focus guidance on the newly entered stage only. Never repeat a full explanation of the completed stage.
TXT;

			case self::TYPE_USER_MESSAGE:
			default:
				return <<<'TXT'
EVENT: user_message
Answer the user's question, collect missing workflow facts if necessary, explain legal procedures when helpful, and continue the conversation naturally.
TXT;
		}
	}

	/**
	 * Preamble included with every event-specific block.
	 *
	 * @return string
	 */
	public static function preamble(): string {
		return <<<'TXT'
EVENT-DRIVEN AI
Every request includes event_context describing WHY you were invoked. Adapt your response to event_context.type — never treat every turn as a generic chat message.
The Workflow Engine decides workflow, routing, stage, forms, conditions, and package. You explain, educate, guide, summarize, and suggest next steps — never override the engine.
TXT;
	}

	/**
	 * Full event guidance block for the system prompt.
	 *
	 * @param array{type: string, meta?: array<string, mixed>} $event_context Event context.
	 * @return string
	 */
	public static function system_block( array $event_context ): string {
		$type = self::sanitize_type( (string) ( $event_context['type'] ?? self::TYPE_USER_MESSAGE ) );

		return self::preamble() . "\n\n" . self::instructions_for( $type );
	}

	/**
	 * @param string $type Raw type.
	 * @return string
	 */
	private static function sanitize_type( string $type ): string {
		$type = sanitize_key( $type );

		if ( in_array( $type, self::supported_types(), true ) ) {
			return $type;
		}

		return self::TYPE_USER_MESSAGE;
	}

	/**
	 * @param string $message User message.
	 * @return bool
	 */
	private static function message_requests_case_summary( string $message ): bool {
		if ( '' === $message ) {
			return false;
		}

		return (bool) preg_match(
			'/\b(?:summarize\s+my\s+case|case\s+summary|overview\s+of\s+my\s+case|summary\s+of\s+my\s+case)\b/i',
			$message
		);
	}

	/**
	 * @param string $message User message.
	 * @return bool
	 */
	private static function message_requests_stage_review( string $message ): bool {
		if ( '' === $message ) {
			return false;
		}

		return (bool) preg_match(
			'/\b(?:what\s+stage\s+am\s+i|current\s+stage|this\s+stage|where\s+am\s+i\s+in\s+(?:the\s+)?process|what\s+do\s+i\s+do\s+(?:at\s+)?(?:this|the\s+current)\s+stage)\b/i',
			$message
		);
	}

	/**
	 * @param string $message User message.
	 * @return bool
	 */
	private static function message_requests_document_review( string $message ): bool {
		if ( '' === $message ) {
			return false;
		}

		return (bool) preg_match(
			'/\b(?:explain\s+(?:these\s+)?(?:documents|forms)|what\s+(?:forms|documents)\s+do\s+i\s+need|review\s+(?:these\s+)?(?:documents|forms)|what\s+is\s+form\s+[a-z0-9-]+)\b/i',
			$message
		);
	}
}
