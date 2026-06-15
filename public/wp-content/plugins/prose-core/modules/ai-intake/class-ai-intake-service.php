<?php
/**
 * AI Intake Service — public facade for the interpreter.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Ai_Intake;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class AI_Intake_Service
 */
final class AI_Intake_Service {

	/**
	 * Interpreter.
	 *
	 * @var AI_Intake_Interpreter
	 */
	private AI_Intake_Interpreter $interpreter;

	/**
	 * AI settings.
	 *
	 * @var AI_Settings
	 */
	private AI_Settings $settings;

	/**
	 * AI logger.
	 *
	 * @var AI_Logger
	 */
	private AI_Logger $logger;

	/**
	 * Constructor.
	 *
	 * @param Ai_Provider_Interface|null $provider   Provider override.
	 * @param AI_Settings|null           $settings   Settings.
	 * @param AI_Logger|null             $logger     Logger.
	 * @param AI_Intake_Interpreter|null $interpreter Interpreter.
	 */
	public function __construct(
		?Ai_Provider_Interface $provider = null,
		?AI_Settings $settings = null,
		?AI_Logger $logger = null,
		?AI_Intake_Interpreter $interpreter = null
	) {
		$this->settings    = $settings ?? new AI_Settings();
		$this->logger      = $logger ?? new AI_Logger();
		$this->interpreter = $interpreter ?? new AI_Intake_Interpreter( $provider, null, null, null, null, null, null, null, $this->settings, $this->logger );
	}

	/**
	 * Interpret a user message.
	 *
	 * @param string                              $message      Message.
	 * @param array<string, mixed>                $state        State.
	 * @param array<int, array<string, string>>   $conversation Conversation.
	 * @return array{success: bool, result: array<string, mixed>}
	 */
	public function interpret( string $message, array $state = array(), array $conversation = array() ): array {
		try {
			$result = $this->interpreter->interpret( $message, $state, $conversation );

			return array(
				'success' => true,
				'result'  => $result,
			);
		} catch ( \Throwable $e ) {
			$this->settings->record_error( $e->getMessage() );
			$this->logger->log(
				array(
					'type'  => 'error',
					'error' => $e->getMessage(),
				)
			);

			return array(
				'success' => false,
				'result'  => array(
					'next_action' => 'error',
					'question'    => '',
					'error'       => $e->getMessage(),
				),
			);
		}
	}

	/**
	 * Test provider connectivity.
	 *
	 * @return array{success: bool, message: string, latency_ms?: int}
	 */
	public function test_connection(): array {
		$provider = $this->settings->make_provider();

		try {
			$response = $provider->complete(
				array(
					array(
						'role'    => 'system',
						'content' => $this->settings->system_prompt(),
					),
					array(
						'role'    => 'user',
						'content' => 'Reply with JSON: {"status":"ok"}',
					),
				),
				$this->settings->provider_options()
			);

			$this->settings->record_request(
				array(
					'type'       => 'test',
					'latency_ms' => $response['latency_ms'],
					'provider'   => $provider->name(),
				)
			);

			return array(
				'success'    => true,
				'message'    => 'Connection successful.',
				'latency_ms' => $response['latency_ms'],
			);
		} catch ( \Throwable $e ) {
			$this->settings->record_error( $e->getMessage() );

			return array(
				'success' => false,
				'message' => $e->getMessage(),
			);
		}
	}

	/**
	 * @return AI_Settings
	 */
	public function get_settings(): AI_Settings {
		return $this->settings;
	}

	/**
	 * @return AI_Logger
	 */
	public function get_logger(): AI_Logger {
		return $this->logger;
	}
}
