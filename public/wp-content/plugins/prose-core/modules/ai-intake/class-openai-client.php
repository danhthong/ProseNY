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
	 * Constructor.
	 *
	 * @param string $api_key OpenAI API key.
	 */
	public function __construct( string $api_key ) {
		$this->api_key = $api_key;
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

		if ( is_wp_error( $response ) ) {
			throw new \RuntimeException( $response->get_error_message() );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$raw  = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		if ( ! is_array( $raw ) ) {
			throw new \RuntimeException( 'Invalid OpenAI response.' );
		}

		if ( $code < 200 || $code >= 300 ) {
			$message = (string) ( $raw['error']['message'] ?? 'OpenAI request failed.' );
			throw new \RuntimeException( $message );
		}

		$content = (string) ( $raw['choices'][0]['message']['content'] ?? '' );

		return array(
			'content'    => $content,
			'latency_ms' => $latency_ms,
			'raw'        => $raw,
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
