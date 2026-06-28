<?php
/**
 * Clarification engine — low confidence and ambiguity handling.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Ai_Intake;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Clarification_Engine
 */
final class Clarification_Engine {

	/**
	 * AI settings.
	 *
	 * @var AI_Settings
	 */
	private AI_Settings $settings;

	/**
	 * Constructor.
	 *
	 * @param AI_Settings|null $settings AI settings.
	 */
	public function __construct( ?AI_Settings $settings = null ) {
		$this->settings = $settings ?? new AI_Settings();
	}

	/**
	 * Build clarifications from low-confidence updates and contradictions.
	 *
	 * @param array<string, array{value: mixed, confidence: float}>         $low_confidence Low-confidence updates.
	 * @param array<int, array{field: string, message: string}>             $contradictions Contradictions.
	 * @param Intake_State                                                    $state          State.
	 * @param string                                                          $message        User message.
	 * @param Ai_Provider_Interface|null                                      $provider       Provider.
	 * @return array<int, array{field: string, message: string}>
	 */
	public function build(
		array $low_confidence,
		array $contradictions,
		Intake_State $state,
		string $message,
		?Ai_Provider_Interface $provider = null
	): array {
		$clarifications = array();

		foreach ( $contradictions as $item ) {
			$clarifications[] = array(
				'field'   => (string) $item['field'],
				'message' => (string) $item['message'] . ' Could you clarify?',
			);
			$state->increment_clarification( (string) $item['field'] );
		}

		foreach ( $low_confidence as $field => $update ) {
			$clarifications[] = array(
				'field'   => $field,
				'message' => $this->clarification_for_field( $field, $state, $message, $provider ),
			);
			$state->increment_clarification( $field );
		}

		if ( $this->is_ambiguous_short_answer( $message, $state->pending_field() ) ) {
			$field = $state->pending_field();
			$clarifications[] = array(
				'field'   => $field,
				'message' => $this->default_clarification( $field ),
			);
			$state->increment_clarification( $field );
		}

		return $this->dedupe( $clarifications );
	}

	/**
	 * Whether a short answer is ambiguous for the pending field.
	 *
	 * @param string $message       User message.
	 * @param string $pending_field Pending field.
	 * @return bool
	 */
	private function is_ambiguous_short_answer( string $message, string $pending_field ): bool {
		if ( '' === $pending_field ) {
			return false;
		}

		$normalized = strtolower( trim( $message ) );

		return in_array( $normalized, array( 'maybe', 'ok' ), true )
			&& in_array( $pending_field, array( 'child_count', 'children', 'has_minor_children' ), true );
	}

	/**
	 * Clarification text for a field.
	 *
	 * @param string                    $field    Field key.
	 * @param Intake_State              $state    State.
	 * @param string                    $message  User message.
	 * @param Ai_Provider_Interface|null $provider Provider.
	 * @return string
	 */
	private function clarification_for_field(
		string $field,
		Intake_State $state,
		string $message,
		?Ai_Provider_Interface $provider
	): string {
		if ( null === $provider ) {
			return $this->default_clarification( $field );
		}

		try {
			$response = $provider->complete(
				array(
					array(
						'role'    => 'system',
						'content' => $this->settings->system_prompt(),
					),
					array(
						'role'    => 'user',
						'content' => wp_json_encode(
							array(
								'task'           => 'clarify',
								'pending_field'  => $field,
								'user_message'   => $message,
								'known_facts'    => $state->plain_facts(),
							)
						),
					),
				),
				array_merge(
					$this->settings->provider_options(),
					array( 'mode' => 'clarify' )
				)
			);

			$parsed = json_decode( $response['content'], true );

			if ( is_array( $parsed ) && ! empty( $parsed['clarification'] ) ) {
				return (string) $parsed['clarification'];
			}
		} catch ( \Throwable $e ) {
			$this->settings->record_error( $e->getMessage() );
		}

		return $this->default_clarification( $field );
	}

	/**
	 * Default clarification message.
	 *
	 * @param string $field Field key.
	 * @return string
	 */
	private function default_clarification( string $field ): string {
		switch ( $field ) {
			case 'child_count':
			case 'children':
			case 'has_minor_children':
				return 'Just to clarify, do you mean you do not have any children together?';
			case 'county':
				return 'Which New York county are you filing in?';
			case 'spouse_agrees':
				return 'Just to clarify, does your spouse agree to the divorce?';
			default:
				return 'Could you clarify that for me?';
		}
	}

	/**
	 * Deduplicate clarifications by field.
	 *
	 * @param array<int, array{field: string, message: string}> $items Items.
	 * @return array<int, array{field: string, message: string}>
	 */
	private function dedupe( array $items ): array {
		$seen   = array();
		$result = array();

		foreach ( $items as $item ) {
			$field = (string) $item['field'];

			if ( isset( $seen[ $field ] ) ) {
				continue;
			}

			$seen[ $field ] = true;
			$result[]       = $item;
		}

		return $result;
	}
}
