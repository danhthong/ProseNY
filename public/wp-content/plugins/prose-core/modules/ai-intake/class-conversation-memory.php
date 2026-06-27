<?php
/**
 * Conversation memory — rolling summary to reduce token usage.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Ai_Intake;

use ProSe\Core\Intake\Case_Summary_Presenter;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Conversation_Memory
 */
final class Conversation_Memory {

	/**
	 * Turns between summary refreshes.
	 */
	private const SUMMARY_INTERVAL = 3;

	/**
	 * Recent messages to include alongside summary.
	 */
	private const RECENT_MESSAGE_COUNT = 6;

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
	 * Build compact context for LLM requests.
	 *
	 * @param Intake_State                       $state        Intake state.
	 * @param array<int, array<string, string>> $conversation Conversation history.
	 * @return array{summary: string, recent: array<int, array<string, string>>, turn_count: int}
	 */
	public function context( Intake_State $state, array $conversation ): array {
		$recent = array_slice( $conversation, -self::RECENT_MESSAGE_COUNT );
		$notes  = ( new Case_Summary_Presenter() )->extract_conversation_notes( $state->conversation_summary() );

		return array(
			'summary'    => $notes,
			'recent'     => $recent,
			'turn_count' => count( $conversation ),
		);
	}

	/**
	 * Update summary when interval is reached.
	 *
	 * @param Intake_State                        $state        Intake state.
	 * @param array<int, array<string, string>>   $conversation Conversation.
	 * @param Ai_Provider_Interface               $provider     AI provider.
	 * @param AI_Logger|null                      $logger       Optional logger.
	 * @return void
	 */
	public function maybe_update_summary(
		Intake_State $state,
		array $conversation,
		Ai_Provider_Interface $provider,
		?AI_Logger $logger = null
	): void {
		$turn_count = count( $conversation );

		if ( $turn_count < self::SUMMARY_INTERVAL || 0 !== $turn_count % self::SUMMARY_INTERVAL ) {
			return;
		}

		$presenter = new Case_Summary_Presenter();
		$notes     = $presenter->extract_conversation_notes( $state->conversation_summary() );

		$messages = array(
			array(
				'role'    => 'system',
				'content' => $this->settings->system_prompt(),
			),
			array(
				'role'    => 'user',
				'content' => wp_json_encode(
					array(
						'task'             => 'summarize_confirmed_facts',
						'current_summary'  => $notes,
						'confirmed_facts'  => $state->plain_facts(),
						'recent_messages'  => array_slice( $conversation, -self::RECENT_MESSAGE_COUNT ),
					)
				),
			),
		);

		try {
			$response = $provider->complete(
				$messages,
				array_merge(
					$this->settings->provider_options(),
					array(
						'mode'    => 'summarize',
						'context' => array( 'facts' => $state->plain_facts() ),
					)
				)
			);

			$parsed = json_decode( $response['content'], true );

			if ( is_array( $parsed ) && ! empty( $parsed['summary'] ) ) {
				$state->set_conversation_summary(
					$presenter->extract_conversation_notes( (string) $parsed['summary'] )
				);
			}

			if ( null !== $logger ) {
				$logger->log(
					array(
						'type'       => 'summary',
						'latency_ms' => $response['latency_ms'],
						'prompt'     => $messages,
						'response'   => $response['content'],
					)
				);
			}
		} catch ( \Throwable $e ) {
			$this->settings->record_error( $e->getMessage() );
		}
	}

	/**
	 * Build a local fallback summary without LLM.
	 *
	 * @param Intake_State $state Intake state.
	 * @return string
	 */
	public function fallback_summary( Intake_State $state ): string {
		$facts = $state->plain_facts();

		if ( empty( $facts ) ) {
			return '';
		}

		$parts = array();

		foreach ( $facts as $key => $value ) {
			if ( is_bool( $value ) ) {
				$value = $value ? 'yes' : 'no';
			}

			$parts[] = $key . ': ' . ( is_scalar( $value ) ? (string) $value : wp_json_encode( $value ) );
		}

		return implode( '; ', $parts );
	}
}
