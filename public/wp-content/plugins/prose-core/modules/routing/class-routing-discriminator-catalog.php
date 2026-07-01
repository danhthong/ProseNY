<?php
/**
 * Routing Discriminator Catalog — semantic topics for workflow routing facts.
 *
 * Discriminator keys are engine-owned (not workflow JSON). The catalog supplies
 * human-readable topics for the AI to paraphrase — never scripted question text.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Routing;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Routing_Discriminator_Catalog
 */
final class Routing_Discriminator_Catalog {

	/**
	 * Semantic topic labels keyed by discriminator field.
	 *
	 * @var array<string, string>
	 */
	private const TOPICS = array(
		'children'                  => 'whether you have any children under 21',
		'spouse_agrees'             => 'whether your spouse agrees to the divorce',
		'marital_property_resolved' => 'whether you and your spouse agree on property and finances',
		'spouse_responded'          => 'whether your spouse has responded to the divorce papers',
		'active_divorce'            => 'whether a divorce case has already been started',
		'protection_needed'         => 'whether you need protection from someone who has harmed or threatened you',
		'child_count'               => 'how many children under 21 are involved',
		'has_minor_children'        => 'whether you have any children under 21',
	);

	/**
	 * Natural bullet questions for combined gathering (not JSON question text).
	 *
	 * @var array<string, string>
	 */
	private const GATHERING_BULLETS = array(
		'spouse_agrees'             => 'Do you and your spouse both agree to the divorce?',
		'children'                  => 'Do you have any children under 21 together?',
		'marital_property_resolved' => 'Have you already reached an agreement on property, finances, custody, and support?',
		'spouse_responded'          => 'Has your spouse responded to the divorce papers?',
		'active_divorce'            => 'Have you already started a divorce case in court?',
		'protection_needed'         => 'Do you need protection from someone who has harmed or threatened you?',
	);

	/**
	 * Optional quick-answer phrases users may tap to speed up input.
	 *
	 * @var array<string, string[]>
	 */
	private const QUICK_SUGGESTIONS = array(
		'children'                  => array(
			'We have children under 21',
			'We do not have children under 21',
		),
		'spouse_agrees'             => array(
			'My spouse and I both agree to the divorce',
			'My spouse does not agree to the divorce',
		),
		'marital_property_resolved' => array(
			'We agree on property and finances',
			'We have a settlement or separation agreement',
			'We have not agreed on property yet',
		),
		'spouse_responded'          => array(
			'My spouse responded to the papers',
			'My spouse has not responded yet',
		),
		'active_divorce'            => array(
			'We already filed for divorce',
			'We have not filed yet',
		),
		'protection_needed'         => array(
			'I need an order of protection',
			'I do not need protection',
		),
	);

	/**
	 * Cross-topic shortcuts when several facts are still unknown.
	 *
	 * @var string[]
	 */
	private const COMBINED_SHORTCUTS = array(
		'We agree on everything',
		'We have a signed separation agreement',
	);

	/**
	 * Keys that share one quick-answer group (aliases collapse to canonical key).
	 *
	 * @var array<string, string>
	 */
	private const CANONICAL_KEYS = array(
		'has_minor_children' => 'children',
	);

	/**
	 * Topic for a routing discriminator key.
	 *
	 * @param string $key Field key.
	 * @return string
	 */
	public static function topic_for( string $key ): string {
		$key = trim( $key );

		if ( isset( self::TOPICS[ $key ] ) ) {
			return self::TOPICS[ $key ];
		}

		return str_replace( '_', ' ', $key );
	}

	/**
	 * All routing discriminator keys the engine may need before workflow resolution.
	 *
	 * @return string[]
	 */
	public static function keys(): array {
		return array_keys( self::TOPICS );
	}

	/**
	 * Conversational opening when the model returns no reply (deterministic fallback).
	 *
	 * @param array<int, array<string, mixed>> $missing Missing field rows.
	 * @param string|null                      $issue   Resolved or inferred issue.
	 * @return string
	 */
	public static function conversational_gathering_prompt( array $missing, ?string $issue = null ): string {
		$bullets = self::gathering_bullets( $missing );

		if ( empty( $bullets ) ) {
			return __( 'Could you tell me a bit more about your legal matter so I can help you find the right path?', 'prose-core' );
		}

		$issue = sanitize_key( (string) ( $issue ?? 'divorce' ) );

		if ( str_starts_with( $issue, 'divorce' ) ) {
			$intro = __( 'I can help you with that.', 'prose-core' ) . "\n\n"
				. __( 'In New York City, the divorce process depends on whether both spouses agree, whether there are children under 21, and whether issues such as property, custody, and support are already resolved.', 'prose-core' )
				. "\n\n"
				. __( 'To determine the correct path for you, could you tell me:', 'prose-core' );
		} else {
			$intro = __( 'I can help you with that.', 'prose-core' ) . "\n\n"
				. __( 'The process depends on a few details about your situation.', 'prose-core' )
				. "\n\n"
				. __( 'To point you in the right direction, could you tell me:', 'prose-core' );
		}

		foreach ( $bullets as $bullet ) {
			$intro .= "\n\n• " . $bullet;
		}

		return trim( $intro );
	}

	/**
	 * @deprecated Use conversational_gathering_prompt().
	 *
	 * @param array<int, array<string, mixed>> $missing Missing field rows.
	 * @return string
	 */
	public static function combined_gathering_prompt( array $missing ): string {
		return self::conversational_gathering_prompt( $missing );
	}

	/**
	 * Bullet questions for still-missing routing topics.
	 *
	 * @param array<int, array<string, mixed>> $missing Missing rows.
	 * @return string[]
	 */
	public static function gathering_bullets( array $missing ): array {
		$bullets = array();
		$seen    = array();

		foreach ( $missing as $row ) {
			$key = self::canonical_key( (string) ( $row['key'] ?? $row['field'] ?? '' ) );

			if ( '' === $key || isset( $seen[ $key ] ) ) {
				continue;
			}

			$bullet = (string) ( self::GATHERING_BULLETS[ $key ] ?? '' );

			if ( '' === $bullet ) {
				continue;
			}

			$seen[ $key ] = true;
			$bullets[]    = $bullet;
		}

		return $bullets;
	}

	/**
	 * Flat quick-answer suggestions — optional helpers, not the conversation.
	 *
	 * @param array<int, array<string, mixed>> $conversation_missing Conversation gaps.
	 * @param string|null                      $issue                Issue hint.
	 * @return array<int, array{label: string, value: string, field: string}>
	 */
	public static function quick_suggestions( array $conversation_missing, ?string $issue = null ): array {
		unset( $issue );

		$suggestions = array();
		$seen        = array();
		$gap_count   = 0;

		foreach ( $conversation_missing as $row ) {
			$key = self::canonical_key( (string) ( $row['key'] ?? $row['field'] ?? '' ) );

			if ( '' === $key || isset( $seen[ $key ] ) ) {
				continue;
			}

			$seen[ $key ] = true;
			++$gap_count;

			foreach ( (array) ( self::QUICK_SUGGESTIONS[ $key ] ?? array() ) as $phrase ) {
				$suggestions[] = array(
					'label' => (string) $phrase,
					'value' => (string) $phrase,
					'field' => $key,
				);
			}
		}

		if ( $gap_count > 1 ) {
			foreach ( self::COMBINED_SHORTCUTS as $phrase ) {
				$suggestions[] = array(
					'label' => $phrase,
					'value' => $phrase,
					'field' => '',
				);
			}
		}

		return array_slice( $suggestions, 0, 8 );
	}

	/**
	 * @deprecated Use quick_suggestions().
	 *
	 * @param array<int, array<string, mixed>> $conversation_missing Conversation gaps.
	 * @return array<int, array<string, mixed>>
	 */
	public static function quick_answer_groups( array $conversation_missing ): array {
		return array();
	}

	/**
	 * @param string $key Field key.
	 * @return string
	 */
	private static function canonical_key( string $key ): string {
		$key = trim( $key );

		if ( '' === $key ) {
			return '';
		}

		return (string) ( self::CANONICAL_KEYS[ $key ] ?? $key );
	}
}
