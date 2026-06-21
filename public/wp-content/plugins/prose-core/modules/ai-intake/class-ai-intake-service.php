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
	 * Domain scope guard.
	 *
	 * @var Domain_Scope_Guard
	 */
	private Domain_Scope_Guard $scope_guard;

	/**
	 * Language guard.
	 *
	 * @var Supported_Language_Guard
	 */
	private Supported_Language_Guard $language_guard;

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
	 * @param Ai_Provider_Interface|null $provider    Provider override.
	 * @param AI_Settings|null           $settings    Settings.
	 * @param AI_Logger|null             $logger      Logger.
	 * @param AI_Intake_Interpreter|null $interpreter Interpreter.
	 * @param Domain_Scope_Guard|null      $scope_guard Scope guard.
	 * @param Supported_Language_Guard|null $language_guard Language guard.
	 */
	public function __construct(
		?Ai_Provider_Interface $provider = null,
		?AI_Settings $settings = null,
		?AI_Logger $logger = null,
		?AI_Intake_Interpreter $interpreter = null,
		?Domain_Scope_Guard $scope_guard = null,
		?Supported_Language_Guard $language_guard = null
	) {
		$this->settings       = $settings ?? new AI_Settings();
		$this->logger         = $logger ?? new AI_Logger();
		$this->scope_guard    = $scope_guard ?? new Domain_Scope_Guard();
		$this->language_guard = $language_guard ?? new Supported_Language_Guard();
		$this->interpreter    = $interpreter ?? new AI_Intake_Interpreter( $provider, null, null, null, null, null, null, null, $this->settings, $this->logger );
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
			$language = $this->language_guard->assess( $message );

			if ( ! $language['supported'] ) {
				return $this->build_language_restriction_response( $language, $state );
			}

			$scope = $this->scope_guard->assess( $message, $state, $conversation );

			if ( ! $scope['supported'] ) {
				return $this->build_scope_restriction_response( $scope, $state );
			}

			if ( ! empty( $scope['hybrid'] ) && ! empty( $scope['out_of_scope_topics'] ) ) {
				$state['scope_note'] = $this->scope_guard->hybrid_scope_note( $scope['out_of_scope_topics'] );
			}

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
	 * Build the deterministic out-of-scope response (no OpenAI call).
	 *
	 * @param array<string, mixed> $scope Scope guard assessment.
	 * @param array<string, mixed> $state Intake state to preserve.
	 * @return array{success: bool, supported: false, message: string, result: array<string, mixed>}
	 */
	private function build_scope_restriction_response( array $scope, array $state = array() ): array {
		$message = (string) ( $scope['message'] ?? '' );

		if ( '' === $message ) {
			$message = ( new Supported_Issue_Catalog() )->restriction_message();
		}

		$result = array(
			'supported'    => false,
			'intent'       => 'out_of_scope',
			'next_action'  => 'domain_restricted',
			'question'     => $message,
			'confidence'   => (float) ( $scope['confidence'] ?? 0.0 ),
			'needs_review' => false,
			'state'        => $state,
		);

		if ( isset( $state['case_profile'] ) && is_array( $state['case_profile'] ) ) {
			$result['case_profile'] = $state['case_profile'];
		}

		if ( isset( $state['conversation_id'] ) && is_string( $state['conversation_id'] ) ) {
			$result['conversation_id'] = $state['conversation_id'];
		}

		return array(
			'success'   => true,
			'supported' => false,
			'message'   => ( new Supported_Issue_Catalog() )->restriction_summary(),
			'result'    => $result,
		);
	}

	/**
	 * Build the deterministic English-only response (no OpenAI call).
	 *
	 * @param array<string, mixed> $language Language guard assessment.
	 * @param array<string, mixed> $state    Intake state to preserve.
	 * @return array{success: bool, supported: false, message: string, result: array<string, mixed>}
	 */
	private function build_language_restriction_response( array $language, array $state = array() ): array {
		$message = (string) ( $language['message'] ?? '' );

		if ( '' === $message ) {
			$message = $this->language_guard->restriction_message();
		}

		$result = array(
			'supported'    => false,
			'intent'       => 'language_restricted',
			'next_action'  => 'language_restricted',
			'question'     => $message,
			'confidence'   => 1.0,
			'needs_review' => false,
			'state'        => $state,
		);

		if ( isset( $state['case_profile'] ) && is_array( $state['case_profile'] ) ) {
			$result['case_profile'] = $state['case_profile'];
		}

		if ( isset( $state['conversation_id'] ) && is_string( $state['conversation_id'] ) ) {
			$result['conversation_id'] = $state['conversation_id'];
		}

		return array(
			'success'   => true,
			'supported' => false,
			'message'   => $message,
			'result'    => $result,
		);
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
