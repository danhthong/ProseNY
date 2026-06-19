<?php
/**
 * Deterministic AI provider for tests and offline development.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Ai_Intake;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Stub_Ai_Provider
 */
final class Stub_Ai_Provider implements Ai_Provider_Interface {

	/**
	 * Canned responses keyed by scenario id.
	 *
	 * @var array<string, string>
	 */
	private array $responses = array();

	/**
	 * Constructor.
	 *
	 * @param array<string, string> $responses Optional canned responses.
	 */
	public function __construct( array $responses = array() ) {
		$this->responses = $responses;
	}

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'stub';
	}

	/**
	 * Set a canned response for a scenario.
	 *
	 * @param string $scenario Scenario key.
	 * @param string $content  JSON response content.
	 * @return void
	 */
	public function set_response( string $scenario, string $content ): void {
		$this->responses[ $scenario ] = $content;
	}

	/**
	 * {@inheritDoc}
	 */
	public function complete( array $messages, array $options = array() ): array {
		$last_user = '';

		foreach ( array_reverse( $messages ) as $message ) {
			if ( 'user' === ( $message['role'] ?? '' ) ) {
				$last_user = (string) ( $message['content'] ?? '' );
				break;
			}
		}

		$scenario = (string) ( $options['scenario'] ?? '' );

		if ( '' !== $scenario && isset( $this->responses[ $scenario ] ) ) {
			return array(
				'content'    => $this->responses[ $scenario ],
				'latency_ms' => 1,
				'raw'        => array(),
			);
		}

		$content = $this->infer_response( $last_user, $messages, $options );

		return array(
			'content'    => $content,
			'latency_ms' => 1,
			'raw'        => array(),
		);
	}

	/**
	 * Infer a deterministic JSON response from the user message.
	 *
	 * @param string                             $message  Latest user message.
	 * @param array<int, array<string, mixed>>   $messages Full message list.
	 * @param array<string, mixed>               $options  Options including context.
	 * @return string
	 */
	private function infer_response( string $message, array $messages, array $options ): string {
		$context = is_array( $options['context'] ?? null ) ? $options['context'] : array();
		$pending = (string) ( $context['pending_field'] ?? '' );
		$mode    = (string) ( $options['mode'] ?? 'extract' );

		list( $message, $pending ) = $this->resolve_message_context( $message, $pending );
		$normalized                = strtolower( trim( $message ) );

		if ( 'summarize' === $mode ) {
			$facts = is_array( $context['facts'] ?? null ) ? $context['facts'] : array();
			return wp_json_encode(
				array(
					'summary' => 'Confirmed facts: ' . wp_json_encode( $facts ),
				)
			);
		}

		if ( 'question' === $mode ) {
			$field = (string) ( $context['target_field'] ?? '' );
			$label = str_replace( '_', ' ', $field );

			return wp_json_encode(
				array(
					'question' => 'Could you please tell me about your ' . $label . '?',
				)
			);
		}

		if ( 'clarify' === $mode ) {
			return wp_json_encode(
				array(
					'clarification' => 'Just to clarify, could you confirm that for me?',
				)
			);
		}

		$updates = array();

		if ( preg_match( '/\bmaybe\b/i', $message ) ) {
			return wp_json_encode(
				array(
					'fact_updates'       => array(
						'county' => array(
							'value'      => 'Queens',
							'confidence' => 0.5,
						),
					),
					'intent'             => 'answer_question',
					'confidence'         => 0.5,
					'conversation_reply' => 'Just to make sure I have it right — which county would that be?',
				)
			);
		}

		if ( preg_match( '/\b(queens|kings|brooklyn|bronx|manhattan|staten island|richmond)\b/i', $message, $m ) ) {
			$updates['county'] = array(
				'value'      => $this->normalize_county( $m[1] ),
				'confidence' => 0.98,
			);
		} elseif ( 'county' === $pending && '' !== $normalized ) {
			$updates['county'] = array(
				'value'      => $this->normalize_county( $normalized ),
				'confidence' => 0.95,
			);
		}

		if ( preg_match( '/\b(?:spouse|wife|husband)\s+agrees?\b|\b(?:we\s+)?both\s+agree\b|\buncontested\b/i', $message ) ) {
			$updates['spouse_agrees'] = array(
				'value'      => true,
				'confidence' => 0.95,
			);
		} elseif ( 'spouse_agrees' === $pending && in_array( $normalized, array( 'yes', 'yeah', 'yep' ), true ) ) {
			$updates['spouse_agrees'] = array(
				'value'      => true,
				'confidence' => 0.95,
			);
		} elseif ( 'spouse_agrees' === $pending && in_array( $normalized, array( 'no', 'nope' ), true ) ) {
			$updates['spouse_agrees'] = array(
				'value'      => false,
				'confidence' => 0.95,
			);
		}

		if ( preg_match( '/\b(two|2)\s+children\b/i', $message ) || preg_match( '/\bhave two children\b/i', $message ) ) {
			$updates['child_count']         = array( 'value' => 2, 'confidence' => 0.95 );
			$updates['has_minor_children']  = array( 'value' => true, 'confidence' => 0.95 );
			$updates['children']            = array( 'value' => true, 'confidence' => 0.95 );
		} elseif ( preg_match( '/\b(one|1)\s+(child|children)\b/i', $message ) ) {
			$updates['child_count']              = array( 'value' => 1, 'confidence' => 0.95 );
			$updates['has_minor_children']         = array( 'value' => true, 'confidence' => 0.95 );
			$updates['children']                   = array( 'value' => true, 'confidence' => 0.95 );
			$updates['minor_children_involved']    = array( 'value' => true, 'confidence' => 0.95 );
		} elseif ( preg_match( '/\bno children\b/i', $message ) ) {
			$updates['child_count']        = array( 'value' => 0, 'confidence' => 0.95 );
			$updates['has_minor_children'] = array( 'value' => false, 'confidence' => 0.95 );
			$updates['children']           = array( 'value' => false, 'confidence' => 0.95 );
		} elseif ( in_array( $normalized, array( 'two', '2' ), true ) && in_array( $pending, array( 'child_count', 'children_count' ), true ) ) {
			$updates['child_count'] = array( 'value' => 2, 'confidence' => 0.95 );
		} elseif ( in_array( $normalized, array( 'no', 'none' ), true ) && in_array( $pending, array( 'child_count', 'children', 'has_minor_children' ), true ) ) {
			$updates['child_count']        = array( 'value' => 0, 'confidence' => 0.95 );
			$updates['has_minor_children'] = array( 'value' => false, 'confidence' => 0.95 );
		}

		if ( preg_match( '/\bdivorce\b/i', $message ) ) {
			$updates['issue'] = array(
				'value'      => 'divorce',
				'confidence' => 0.95,
			);
		}

		if ( preg_match( '/\b(custody|visitation)\b/i', $message ) ) {
			$updates['issue'] = array(
				'value'      => 'custody',
				'confidence' => 0.95,
			);
		}

		if ( preg_match( "/\b(i don'?t know|not sure|it'?s complicated)\b/i", $message ) ) {
			return wp_json_encode(
				array(
					'fact_updates'       => array(),
					'intent'             => 'uncertain',
					'confidence'         => 0.3,
					'conversation_reply' => 'No problem — take your time. Whenever you are ready, just share what you can and we will work through it together.',
				)
			);
		}

		if ( $this->is_strategy_question( $message ) ) {
			return wp_json_encode(
				array(
					'fact_updates'       => array(),
					'intent'             => 'procedural_explain',
					'confidence'         => 0.95,
					'conversation_reply' => $this->strategy_refusal_reply( $message ),
				)
			);
		}

		return wp_json_encode(
			array(
				'fact_updates'       => $updates,
				'intent'             => 'answer_question',
				'confidence'         => empty( $updates ) ? 0.5 : 0.95,
				'conversation_reply' => $this->stub_reply( $updates, $context ),
			)
		);
	}

	/**
	 * Resolve the plain user message from converse/extract JSON payloads.
	 *
	 * @param string $message Raw provider input (plain text or JSON task payload).
	 * @param string $pending Pending field from provider context.
	 * @return array{0: string, 1: string}
	 */
	private function resolve_message_context( string $message, string $pending ): array {
		$trimmed = trim( $message );

		if ( ! str_starts_with( $trimmed, '{' ) ) {
			return array( $message, $pending );
		}

		$decoded = json_decode( $trimmed, true );

		if ( ! is_array( $decoded ) ) {
			return array( $message, $pending );
		}

		if ( isset( $decoded['latest_user_message'] ) && is_string( $decoded['latest_user_message'] ) ) {
			$message = $decoded['latest_user_message'];
		}

		if ( '' === $pending && ! empty( $decoded['pending_field'] ) && is_string( $decoded['pending_field'] ) ) {
			$pending = $decoded['pending_field'];
		}

		return array( $message, $pending );
	}

	/**
	 * Build a deterministic conversational reply for the converse mode.
	 *
	 * @param array<string, mixed> $updates Extracted updates.
	 * @param array<string, mixed> $context Request context.
	 * @return string
	 */
	private function stub_reply( array $updates, array $context ): string {
		if ( ! empty( $updates ) ) {
			return 'Thanks, I have noted that. Could you tell me a bit more about your situation so I can help further?';
		}

		return 'Could you tell me a little more about your legal matter so I can point you in the right direction?';
	}

	/**
	 * Whether the user is asking for legal strategy rather than procedure.
	 *
	 * @param string $message User message.
	 * @return bool
	 */
	private function is_strategy_question( string $message ): bool {
		return (bool) preg_match(
			'/\b(should i|would i|is it better|recommend|advise|sole custody|full custody|fight for|ask for sole|file a motion|move for)\b/i',
			$message
		);
	}

	/**
	 * Neutral procedural reply when strategy is requested.
	 *
	 * @param string $message User message.
	 * @return string
	 */
	private function strategy_refusal_reply( string $message ): string {
		unset( $message );

		return 'I cannot recommend whether you should pursue a particular outcome, but I can explain how custody is addressed in Family Court, what forms are typically involved, and what the general procedure looks like. Tell me which part you would like explained.';
	}

	/**
	 * Normalize borough/county names.
	 *
	 * @param string $input Raw input.
	 * @return string
	 */
	private function normalize_county( string $input ): string {
		$key = strtolower( trim( $input ) );

		$map = array(
			'brooklyn'      => 'Kings',
			'kings'         => 'Kings',
			'queens'        => 'Queens',
			'queen'         => 'Queens',
			'bronx'         => 'Bronx',
			'manhattan'     => 'New York',
			'staten island' => 'Richmond',
			'richmond'      => 'Richmond',
		);

		return $map[ $key ] ?? ucwords( $input );
	}
}
