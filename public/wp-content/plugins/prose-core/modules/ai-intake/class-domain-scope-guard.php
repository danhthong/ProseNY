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
		if ( $this->should_bypass( $state, $conversation ) ) {
			return array(
				'supported'           => true,
				'confidence'          => 1.0,
				'message'             => '',
				'hybrid'              => false,
				'out_of_scope_topics' => array(),
				'bypassed'            => true,
			);
		}

		$text              = $this->normalize( $message );
		$supported_score   = 0.0;
		$out_of_scope      = array();

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

			if ( '' !== $phrase && str_contains( $text, $phrase ) && ! in_array( $label, $out_of_scope, true ) ) {
				$out_of_scope[] = $label;
			}
		}

		$hybrid    = ! empty( $out_of_scope ) && $supported_score >= Supported_Issue_Catalog::CONFIDENCE_THRESHOLD;
		$supported = $supported_score >= Supported_Issue_Catalog::CONFIDENCE_THRESHOLD;

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
	 * Whether an active intake session should skip scope checks.
	 *
	 * Short answers like county names or child counts are not divorce keywords
	 * but are valid mid-intake replies.
	 *
	 * @param array<string, mixed>              $state        Intake state.
	 * @param array<int, array<string, string>> $conversation Conversation history.
	 * @return bool
	 */
	public function should_bypass( array $state, array $conversation ): bool {
		if ( ! empty( $conversation ) ) {
			return true;
		}

		$workflow = '';

		if ( isset( $state['workflow'] ) && is_string( $state['workflow'] ) && '' !== $state['workflow'] ) {
			$workflow = $state['workflow'];
		} elseif ( isset( $state['case_profile']['workflow'] ) && is_string( $state['case_profile']['workflow'] ) ) {
			$workflow = $state['case_profile']['workflow'];
		}

		if ( '' !== $workflow ) {
			return true;
		}

		$pending = '';

		if ( isset( $state['pending_field'] ) && is_string( $state['pending_field'] ) ) {
			$pending = $state['pending_field'];
		} elseif ( isset( $state['case_profile']['pending_field'] ) && is_string( $state['case_profile']['pending_field'] ) ) {
			$pending = $state['case_profile']['pending_field'];
		}

		if ( '' !== $pending ) {
			return true;
		}

		$facts = array();

		if ( isset( $state['facts'] ) && is_array( $state['facts'] ) ) {
			$facts = $state['facts'];
		} elseif ( isset( $state['case_profile']['facts'] ) && is_array( $state['case_profile']['facts'] ) ) {
			$facts = $state['case_profile']['facts'];
		}

		return ! empty( $facts );
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
			__( 'Note: %s matters are outside ProSeNY\'s current scope. Focus on the divorce-related portion of the user\'s message and politely explain that limitation.', 'prose-core' ),
			$joined
		);
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
