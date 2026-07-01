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
	 * Plain-language reasons for routing questions.
	 *
	 * @var array<string, string>
	 */
	private const WHY_REASONS = array(
		'children'                  => 'Children under 21 affect which divorce forms and court procedures apply.',
		'spouse_agrees'             => 'Whether your spouse agrees determines whether the case is uncontested or contested.',
		'marital_property_resolved' => 'Unresolved property or support issues can change which workflow and forms you need.',
		'spouse_responded'          => 'Whether your spouse responded affects the next procedural steps after service.',
		'active_divorce'            => 'If papers were already filed, we continue from your current court stage instead of commencement.',
		'protection_needed'         => 'Safety concerns may require a different court and protective-order workflow.',
		'county'                    => 'Divorce cases are filed in the Supreme Court of the county with proper jurisdiction.',
	);

	/**
	 * Short outstanding labels for assessments and dashboards.
	 *
	 * @var array<string, string>
	 */
	private const OUTSTANDING_LABELS = array(
		'children'                  => 'children under 21',
		'spouse_agrees'             => 'spouse agreement',
		'marital_property_resolved' => 'property agreement',
		'spouse_responded'          => 'spouse response',
		'active_divorce'            => 'whether papers were filed',
		'protection_needed'         => 'safety concerns',
	);

	/**
	 * Keys that share one quick-answer group (aliases collapse to canonical key).
	 *
	 * @var array<string, string>
	 */
	private const CANONICAL_KEYS = array(
		'has_minor_children'      => 'children',
		'minor_children_involved' => 'children',
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
	 * Why a routing fact is needed — for explain-why intake prompts.
	 *
	 * @param string $key Field key.
	 * @return string
	 */
	public static function why_for( string $key ): string {
		$key = self::canonical_key( $key );

		if ( isset( self::WHY_REASONS[ $key ] ) ) {
			return self::WHY_REASONS[ $key ];
		}

		return __( 'This detail helps determine the correct court workflow for your situation.', 'prose-core' );
	}

	/**
	 * Short label for an outstanding routing topic.
	 *
	 * @param string $key Field key.
	 * @return string
	 */
	public static function outstanding_label( string $key ): string {
		$key = self::canonical_key( $key );

		if ( isset( self::OUTSTANDING_LABELS[ $key ] ) ) {
			return self::OUTSTANDING_LABELS[ $key ];
		}

		return self::topic_for( $key );
	}

	/**
	 * Acknowledgment line after a routing fact is confirmed.
	 *
	 * @param string $key   Field key.
	 * @param mixed  $value Stored fact value.
	 * @return string Empty when not a routing discriminator.
	 */
	public static function confirmed_acknowledgment( string $key, $value ): string {
		$key = self::canonical_key( $key );

		switch ( $key ) {
			case 'children':
				if ( false === $value || 0 === $value || '0' === $value ) {
					return __( 'No children under 21 confirmed', 'prose-core' );
				}

				if ( true === $value || ( is_numeric( $value ) && (int) $value > 0 ) ) {
					return __( 'Children under 21 confirmed', 'prose-core' );
				}
				break;

			case 'spouse_agrees':
				if ( true === $value ) {
					return __( 'Spouse agrees to the divorce', 'prose-core' );
				}

				if ( false === $value ) {
					return __( 'Spouse does not agree to the divorce', 'prose-core' );
				}
				break;

			case 'marital_property_resolved':
				if ( true === $value ) {
					return __( 'Property and financial issues resolved', 'prose-core' );
				}

				if ( false === $value ) {
					return __( 'Property or financial issues not yet resolved', 'prose-core' );
				}
				break;

			case 'spouse_responded':
				if ( true === $value ) {
					return __( 'Spouse responded to the papers', 'prose-core' );
				}

				if ( false === $value ) {
					return __( 'Spouse has not responded yet', 'prose-core' );
				}
				break;

			case 'active_divorce':
				if ( true === $value ) {
					return __( 'Divorce papers already filed', 'prose-core' );
				}

				if ( false === $value ) {
					return __( 'No divorce case filed yet', 'prose-core' );
				}
				break;

			case 'protection_needed':
				if ( true === $value ) {
					return __( 'Protection concerns noted', 'prose-core' );
				}

				if ( false === $value ) {
					return __( 'No protection order needed', 'prose-core' );
				}
				break;
		}

		return '';
	}

	/**
	 * Canonical routing discriminator key.
	 *
	 * @param string $key Field key.
	 * @return string
	 */
	public static function canonical_key( string $key ): string {
		$key = trim( $key );

		if ( '' === $key ) {
			return '';
		}

		return (string) ( self::CANONICAL_KEYS[ $key ] ?? $key );
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
}
