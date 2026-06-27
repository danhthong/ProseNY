<?php
/**
 * Domain Scope Guard — lightweight deterministic classifier before AI intake.
 *
 * Blocks clearly unrelated topics from invoking OpenAI. Divorce-related and
 * hybrid messages proceed to the AI interpreter.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Ai_Intake;

use ProSe\Core\Intake\Date_Parser;
use ProSe\Core\Routing\Workflow_Catalog;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Domain_Scope_Guard
 */
final class Domain_Scope_Guard {

	/**
	 * Issue catalog.
	 *
	 * @var Supported_Issue_Catalog
	 */
	private Supported_Issue_Catalog $catalog;

	/**
	 * Workflow catalog.
	 *
	 * @var Workflow_Catalog
	 */
	private Workflow_Catalog $workflows;

	/**
	 * Constructor.
	 *
	 * @param Supported_Issue_Catalog|null $catalog    Issue catalog.
	 * @param Workflow_Catalog|null        $workflows  Workflow catalog.
	 */
	public function __construct(
		?Supported_Issue_Catalog $catalog = null,
		?Workflow_Catalog $workflows = null
	) {
		$this->catalog   = $catalog ?? new Supported_Issue_Catalog();
		$this->workflows = $workflows ?? new Workflow_Catalog();
	}

	/**
	 * Assess whether a message is in scope for the AI interpreter.
	 *
	 * @param string                              $message      User message.
	 * @param array<string, mixed>                $state        Intake state.
	 * @param array<int, array<string, string>>   $conversation Conversation history.
	 * @return array{
	 *   supported: bool,
	 *   confidence: float,
	 *   message: string,
	 *   hybrid: bool,
	 *   out_of_scope_topics: string[],
	 *   bypassed: bool
	 * }
	 */
	public function assess( string $message, array $state = array(), array $conversation = array() ): array {
		$text            = $this->normalize( $message );
		$supported_score = 0.0;
		$out_of_scope    = array();

		foreach ( $this->catalog->keywords() as $entry ) {
			$phrase = $this->normalize( (string) ( $entry['phrase'] ?? '' ) );

			if ( '' !== $phrase && str_contains( $text, $phrase ) ) {
				$supported_score = max( $supported_score, (float) ( $entry['weight'] ?? 0.15 ) );
			}
		}

		foreach ( $this->catalog->workflow_triggers( $this->workflows ) as $entry ) {
			$phrase = $this->normalize( (string) ( $entry['phrase'] ?? '' ) );

			if ( '' !== $phrase && str_contains( $text, $phrase ) ) {
				$supported_score = max( $supported_score, (float) ( $entry['weight'] ?? 0.35 ) );
			}
		}

		foreach ( $this->catalog->unsupported_keywords() as $entry ) {
			$phrase = $this->normalize( (string) ( $entry['phrase'] ?? '' ) );
			$label  = trim( (string) ( $entry['label'] ?? $phrase ) );

			if ( '' === $phrase || ! str_contains( $text, $phrase ) || in_array( $label, $out_of_scope, true ) ) {
				continue;
			}

			$out_of_scope[] = $label;
		}

		foreach ( $this->off_topic_pattern_labels( $text ) as $label ) {
			if ( ! in_array( $label, $out_of_scope, true ) ) {
				$out_of_scope[] = $label;
			}
		}

		if ( $this->has_active_intake( $state, $conversation ) && $this->is_procedural_follow_up( $text ) ) {
			$supported_score = max( $supported_score, 0.35 );
		}

		// Mid-intake fact answers (dates, names, residency) rarely repeat "divorce" keywords.
		if ( $this->has_active_intake( $state, $conversation ) && empty( $out_of_scope ) ) {
			$supported_score = max( $supported_score, Supported_Issue_Catalog::CONFIDENCE_THRESHOLD );
		}

		if ( $this->has_active_intake( $state, $conversation ) && $this->looks_like_date_answer( $message ) ) {
			$out_of_scope    = array_values(
				array_filter(
					$out_of_scope,
					static function ( string $label ): bool {
						return 'general knowledge' !== $label;
					}
				)
			);
			$supported_score = max( $supported_score, Supported_Issue_Catalog::CONFIDENCE_THRESHOLD );
		}

		$hybrid    = ! empty( $out_of_scope ) && $supported_score >= Supported_Issue_Catalog::CONFIDENCE_THRESHOLD;
		$supported = $supported_score >= Supported_Issue_Catalog::CONFIDENCE_THRESHOLD;

		if ( ! empty( $out_of_scope ) && ! $supported ) {
			return array(
				'supported'           => false,
				'confidence'          => round( $supported_score, 2 ),
				'message'             => $this->catalog->restriction_message(),
				'hybrid'              => false,
				'out_of_scope_topics' => $out_of_scope,
				'bypassed'            => false,
			);
		}

		if ( $this->should_bypass( $message, $state, $conversation ) ) {
			return array(
				'supported'           => true,
				'confidence'          => 1.0,
				'message'             => '',
				'hybrid'              => false,
				'out_of_scope_topics' => array(),
				'bypassed'            => true,
			);
		}

		return array(
			'supported'           => $supported,
			'confidence'          => round( $supported_score, 2 ),
			'message'             => $supported ? '' : $this->catalog->restriction_message(),
			'hybrid'              => $hybrid,
			'out_of_scope_topics' => $out_of_scope,
			'bypassed'            => false,
		);
	}

	/**
	 * Whether an active intake session should skip scope checks for this reply.
	 *
	 * Short answers like county names or child counts are not divorce keywords
	 * but are valid mid-intake replies. Clearly off-topic messages never bypass.
	 *
	 * @param string                              $message      User message.
	 * @param array<string, mixed>                $state        Intake state.
	 * @param array<int, array<string, string>>   $conversation Conversation history.
	 * @return bool
	 */
	public function should_bypass( string $message, array $state, array $conversation = array() ): bool {
		if ( ! $this->has_active_intake( $state, $conversation ) ) {
			return false;
		}

		return $this->looks_like_intake_answer( $message, $state );
	}

	/**
	 * Build a scope note for hybrid messages passed to the interpreter.
	 *
	 * @param string[] $topics Out-of-scope topic labels.
	 * @return string
	 */
	public function hybrid_scope_note( array $topics ): string {
		if ( empty( $topics ) ) {
			return '';
		}

		$joined = $this->human_join( $topics );

		return sprintf(
			/* translators: %s: comma-separated list of out-of-scope topic labels. */
			__( 'Note: %s matters are outside ProSeNY\'s current scope. Focus on the in-scope family court portion of the user\'s message and politely explain that limitation.', 'prose-core' ),
			$joined
		);
	}

	/**
	 * Whether intake is already underway.
	 *
	 * @param array<string, mixed>              $state        Intake state.
	 * @param array<int, array<string, string>> $conversation Conversation history.
	 * @return bool
	 */
	private function has_active_intake( array $state, array $conversation ): bool {
		if ( ! empty( $conversation ) ) {
			return true;
		}

		if ( '' !== $this->workflow_from_state( $state ) ) {
			return true;
		}

		if ( '' !== $this->pending_field_from_state( $state ) ) {
			return true;
		}

		return ! empty( $this->facts_from_state( $state ) );
	}

	/**
	 * Whether a message looks like a direct intake answer rather than a new topic.
	 *
	 * @param string               $message User message.
	 * @param array<string, mixed> $state   Intake state.
	 * @return bool
	 */
	private function looks_like_intake_answer( string $message, array $state ): bool {
		$text = trim( $message );

		if ( '' === $text ) {
			return false;
		}

		if ( '' !== $this->pending_field_from_state( $state ) ) {
			return strlen( $text ) <= 160;
		}

		if ( \ProSe\Core\Users\User_Intake_Context::message_asks_about_account( $text ) ) {
			return true;
		}

		if ( $this->looks_like_date_answer( $text ) ) {
			return true;
		}

		if ( preg_match( '/\b(?:birthday|birth\s*date|date\s+of\s+birth|dob|born)\b/i', $text ) ) {
			return true;
		}

		if ( preg_match( '/^\d{1,2}$/', $text ) ) {
			return true;
		}

		if ( preg_match( '/^(yes|no|yeah|nope|true|false)\.?$/i', $text ) ) {
			return true;
		}

		if ( strlen( $text ) <= 40 && ! str_contains( $text, '?' ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Procedural follow-ups during an active intake remain in scope.
	 *
	 * @param string $text Normalized message text.
	 * @return bool
	 */
	private function is_procedural_follow_up( string $text ): bool {
		$phrases = array(
			'what happens next',
			'what do i do next',
			'what should i do next',
			'what are the next steps',
			'what forms do i need',
			'which forms do i need',
			'how do i file',
			'where do i file',
			'what court',
			'which court',
			'deadline',
			'blank form',
			'blank pdf',
			'download',
		);

		foreach ( $phrases as $phrase ) {
			if ( str_contains( $text, $phrase ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Detect general-knowledge / chit-chat patterns not covered by keyword lists.
	 *
	 * @param string $text Normalized message text.
	 * @return string[]
	 */
	private function off_topic_pattern_labels( string $text ): array {
		$labels = array();

		if ( ! Date_Parser::contains_slash_date( $text ) && preg_match( '/\b\d+\s*[\+\-\*\/x]\s*\d+\b/', $text ) ) {
			$labels[] = 'general knowledge';
		}

		$chit_chat = array(
			'sing a song'     => 'general knowledge',
			'tell me a joke'  => 'general knowledge',
			'write a poem'    => 'general knowledge',
			'who are you'     => 'general knowledge',
			'what is your name' => 'general knowledge',
		);

		foreach ( $chit_chat as $phrase => $label ) {
			if ( str_contains( $text, $phrase ) && ! in_array( $label, $labels, true ) ) {
				$labels[] = $label;
			}
		}

		return $labels;
	}

	/**
	 * Whether a message is a standalone date answer (common mid-intake).
	 *
	 * @param string $text Raw or normalized message text.
	 * @return bool
	 */
	private function looks_like_date_answer( string $text ): bool {
		$trimmed = trim( $text );

		if ( '' === $trimmed ) {
			return false;
		}

		if ( preg_match( '#^\d{1,2}[/-]\d{1,2}[/-]\d{4}$#', $trimmed ) ) {
			return true;
		}

		if ( preg_match( '/^\d{4}-\d{1,2}-\d{1,2}$/', $trimmed ) ) {
			return true;
		}

		if ( Date_Parser::contains_slash_date( $trimmed ) ) {
			return true;
		}

		return null !== Date_Parser::parse( $trimmed );
	}

	/**
	 * @param array<string, mixed> $state Intake state.
	 * @return string
	 */
	private function workflow_from_state( array $state ): string {
		if ( isset( $state['workflow'] ) && is_string( $state['workflow'] ) && '' !== $state['workflow'] ) {
			return $state['workflow'];
		}

		if ( isset( $state['case_profile']['workflow'] ) && is_string( $state['case_profile']['workflow'] ) && '' !== $state['case_profile']['workflow'] ) {
			return $state['case_profile']['workflow'];
		}

		return '';
	}

	/**
	 * @param array<string, mixed> $state Intake state.
	 * @return string
	 */
	private function pending_field_from_state( array $state ): string {
		if ( isset( $state['pending_field'] ) && is_string( $state['pending_field'] ) && '' !== $state['pending_field'] ) {
			return $state['pending_field'];
		}

		if ( isset( $state['case_profile']['pending_field'] ) && is_string( $state['case_profile']['pending_field'] ) && '' !== $state['case_profile']['pending_field'] ) {
			return $state['case_profile']['pending_field'];
		}

		return '';
	}

	/**
	 * @param array<string, mixed> $state Intake state.
	 * @return array<string, mixed>
	 */
	private function facts_from_state( array $state ): array {
		if ( isset( $state['facts'] ) && is_array( $state['facts'] ) ) {
			return $state['facts'];
		}

		if ( isset( $state['case_profile']['facts'] ) && is_array( $state['case_profile']['facts'] ) ) {
			return $state['case_profile']['facts'];
		}

		return array();
	}

	/**
	 * Normalize text for matching.
	 *
	 * @param string $text Raw text.
	 * @return string
	 */
	private function normalize( string $text ): string {
		$text = strtolower( trim( $text ) );
		$text = preg_replace( '/\s+/', ' ', $text );

		return is_string( $text ) ? $text : '';
	}

	/**
	 * Join topic labels for display.
	 *
	 * @param string[] $items Labels.
	 * @return string
	 */
	private function human_join( array $items ): string {
		$items = array_values( array_filter( array_map( 'strval', $items ) ) );
		$count = count( $items );

		if ( 0 === $count ) {
			return '';
		}

		if ( 1 === $count ) {
			return $items[0];
		}

		if ( 2 === $count ) {
			/* translators: 1: first topic, 2: second topic */
			return sprintf( __( '%1$s and %2$s', 'prose-core' ), $items[0], $items[1] );
		}

		$last = array_pop( $items );

		/* translators: 1: comma-separated topics, 2: final topic */
		return sprintf( __( '%1$s, and %2$s', 'prose-core' ), implode( ', ', $items ), $last );
	}
}
