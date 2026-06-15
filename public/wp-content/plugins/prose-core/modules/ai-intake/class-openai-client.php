<?php
/**
 * OpenAI API client.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Ai_Intake;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class OpenAI_Client
 */
final class OpenAI_Client implements Ai_Provider_Interface {

	/**
	 * API endpoint.
	 */
	private const API_URL = 'https://api.openai.com/v1/chat/completions';

	/**
	 * API key.
	 *
	 * @var string
	 */
	private string $api_key;

	/**
	 * Usage logger.
	 *
	 * @var Usage_Logger
	 */
	private Usage_Logger $usage;

	/**
	 * Constructor.
	 *
	 * @param string            $api_key OpenAI API key.
	 * @param Usage_Logger|null $usage   Usage logger.
	 */
	public function __construct( string $api_key, ?Usage_Logger $usage = null ) {
		$this->api_key = $api_key;
		$this->usage   = $usage ?? new Usage_Logger();
	}

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'openai';
	}

	/**
	 * {@inheritDoc}
	 */
	public function complete( array $messages, array $options = array() ): array {
		if ( '' === trim( $this->api_key ) ) {
			throw new \RuntimeException( 'OpenAI API key is not configured.' );
		}

		$model       = $this->normalize_model( (string) ( $options['model'] ?? 'gpt-5.5' ) );
		$temperature = (float) ( $options['temperature'] ?? 0.2 );
		$max_tokens  = (int) ( $options['max_tokens'] ?? 1024 );
		$timeout     = (int) ( $options['timeout'] ?? 30 );

		$body = array(
			'model'    => $model,
			'messages' => $messages,
		);

		// The gpt-5 family and o-series reasoning models require
		// max_completion_tokens; older models (gpt-3.5/4/4o/4.1) use max_tokens.
		if ( $this->uses_completion_tokens( $model ) ) {
			$body['max_completion_tokens'] = $max_tokens;
		} else {
			$body['max_tokens'] = $max_tokens;
		}

		// o-series reasoning models only accept the default temperature.
		if ( ! $this->is_reasoning_only( $model ) ) {
			$body['temperature'] = $temperature;
		}

		if ( ! empty( $options['response_format'] ) && 'json_object' === $options['response_format'] ) {
			$body['response_format'] = array( 'type' => 'json_object' );
		}

		$start = microtime( true );

		$response = wp_remote_post(
			self::API_URL,
			array(
				'timeout' => $timeout,
				'headers' => array(
					'Authorization' => 'Bearer ' . $this->api_key,
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode( $body ),
			)
		);

		$latency_ms = (int) round( ( microtime( true ) - $start ) * 1000 );
		$type       = (string) ( $options['mode'] ?? 'request' );

		if ( is_wp_error( $response ) ) {
			$this->log_usage( $type, $model, array(), $latency_ms, 'error', $response->get_error_message() );
			throw new \RuntimeException( $response->get_error_message() );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$raw  = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		if ( ! is_array( $raw ) ) {
			$this->log_usage( $type, $model, array(), $latency_ms, 'error', 'Invalid OpenAI response.' );
			throw new \RuntimeException( 'Invalid OpenAI response.' );
		}

		if ( $code < 200 || $code >= 300 ) {
			$message = (string) ( $raw['error']['message'] ?? 'OpenAI request failed.' );
			$this->log_usage( $type, $model, array(), $latency_ms, 'error', $message );
			throw new \RuntimeException( $message );
		}

		$content = (string) ( $raw['choices'][0]['message']['content'] ?? '' );
		$usage   = is_array( $raw['usage'] ?? null ) ? $raw['usage'] : array();

		$this->log_usage( $type, $model, $usage, $latency_ms, 'ok', '' );

		return array(
			'content'    => $content,
			'latency_ms' => $latency_ms,
			'tokens'     => array(
				'prompt_tokens'     => (int) ( $usage['prompt_tokens'] ?? 0 ),
				'completion_tokens' => (int) ( $usage['completion_tokens'] ?? 0 ),
				'total_tokens'      => (int) ( $usage['total_tokens'] ?? 0 ),
			),
			'raw'        => $raw,
		);
	}

	/**
	 * Record a usage log entry for one API call.
	 *
	 * @param string               $type       Request type/mode.
	 * @param string               $model      Model id.
	 * @param array<string, mixed> $usage      OpenAI usage payload.
	 * @param int                  $latency_ms Latency in milliseconds.
	 * @param string               $status     "ok" or "error".
	 * @param string               $error      Error message.
	 * @return void
	 */
	private function log_usage( string $type, string $model, array $usage, int $latency_ms, string $status, string $error ): void {
		$this->usage->record(
			array(
				'type'              => $type,
				'provider'         => $this->name(),
				'model'            => $model,
				'prompt_tokens'    => (int) ( $usage['prompt_tokens'] ?? 0 ),
				'completion_tokens' => (int) ( $usage['completion_tokens'] ?? 0 ),
				'total_tokens'     => (int) ( $usage['total_tokens'] ?? 0 ),
				'latency_ms'       => $latency_ms,
				'status'           => $status,
				'error'            => $error,
			)
		);
	}

	/**
	 * Normalize a model id (OpenAI ids never contain spaces).
	 *
	 * @param string $model Raw model id.
	 * @return string
	 */
	private function normalize_model( string $model ): string {
		$model = trim( $model );

		return (string) preg_replace( '/\s+/', '-', $model );
	}

	/**
	 * Whether the model uses max_completion_tokens instead of max_tokens.
	 *
	 * @param string $model Model id.
	 * @return bool
	 */
	private function uses_completion_tokens( string $model ): bool {
		return (bool) preg_match( '/^(o\d|gpt-5)/i', $model );
	}

	/**
	 * Whether the model is a reasoning-only model that rejects custom temperature.
	 *
	 * @param string $model Model id.
	 * @return bool
	 */
	private function is_reasoning_only( string $model ): bool {
		return (bool) preg_match( '/^o\d/i', $model );
	}
}
