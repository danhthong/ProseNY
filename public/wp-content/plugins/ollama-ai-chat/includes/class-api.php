<?php
/**
 * Ollama API HTTP client.
 *
 * @package Ollama_AI_Chat
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Ollama_AI_Chat_API
 */
class Ollama_AI_Chat_API {

	/**
	 * Send a chat request to Ollama (non-streaming).
	 *
	 * @param array  $messages Messages array.
	 * @param bool   $stream   Whether to stream.
	 * @param string $model    Optional model override.
	 * @return array|WP_Error
	 */
	public static function chat( array $messages, bool $stream = false, string $model = '' ) {
		$payload = self::build_payload( $messages, $stream, $model );

		if ( is_wp_error( $payload ) ) {
			return $payload;
		}

		if ( $stream ) {
			return new WP_Error(
				'ollama_use_stream_endpoint',
				__( 'Use the streaming endpoint for stream requests.', 'ollama-ai-chat' )
			);
		}

		return self::request( $payload );
	}

	/**
	 * Build Ollama API payload.
	 *
	 * @param array  $messages Messages.
	 * @param bool   $stream   Stream flag.
	 * @param string $model    Model override.
	 * @return array|WP_Error
	 */
	public static function build_payload( array $messages, bool $stream = false, string $model = '' ) {
		$sanitized = self::sanitize_messages( $messages );

		if ( is_wp_error( $sanitized ) ) {
			return $sanitized;
		}

		$system_prompt = Ollama_AI_Chat_Plugin::get_option( 'ollama_system_prompt', '' );
		$full_messages = array();

		if ( ! empty( $system_prompt ) ) {
			$has_system = false;
			foreach ( $sanitized as $msg ) {
				if ( 'system' === $msg['role'] ) {
					$has_system = true;
					break;
				}
			}
			if ( ! $has_system ) {
				$full_messages[] = array(
					'role'    => 'system',
					'content' => $system_prompt,
				);
			}
		}

		$full_messages = array_merge( $full_messages, $sanitized );

		$model_name = ! empty( $model ) ? sanitize_text_field( $model ) : Ollama_AI_Chat_Plugin::get_option( 'ollama_model', 'qwen2.5-coder:7b' );
		$model_name  = apply_filters( 'ollama_ai_chat_model', $model_name );

		$temperature = (float) Ollama_AI_Chat_Plugin::get_option( 'ollama_temperature', 0.7 );
		$max_tokens  = (int) Ollama_AI_Chat_Plugin::get_option( 'ollama_max_tokens', 2048 );

		return array(
			'model'    => $model_name,
			'messages' => $full_messages,
			'stream'   => $stream,
			'options'  => array(
				'temperature' => max( 0, min( 2, $temperature ) ),
				'num_predict' => max( 1, min( 32768, $max_tokens ) ),
			),
		);
	}

	/**
	 * Execute HTTP request to Ollama.
	 *
	 * @param array $payload Request payload.
	 * @return array|WP_Error
	 */
	public static function request( array $payload ) {
		$url = Ollama_AI_Chat_Plugin::get_option( 'ollama_base_url', 'http://host.docker.internal:11434/api/chat' );
		$url = esc_url_raw( $url );

		if ( empty( $url ) ) {
			return new WP_Error(
				'ollama_invalid_url',
				__( 'Ollama API URL is not configured.', 'ollama-ai-chat' )
			);
		}

		$response = wp_remote_post(
			$url,
			array(
				'timeout' => 120,
				'headers' => array(
					'Content-Type' => 'application/json',
				),
				'body'    => wp_json_encode( $payload ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'ollama_connection_failed',
				__( 'Could not connect to the Ollama server. Please ensure Ollama is running and the URL is correct.', 'ollama-ai-chat' ),
				array( 'original' => $response->get_error_message() )
			);
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );

		if ( $code < 200 || $code >= 300 ) {
			$error_data = json_decode( $body, true );
			$message    = isset( $error_data['error'] ) ? $error_data['error'] : __( 'Ollama returned an unexpected error.', 'ollama-ai-chat' );

			return new WP_Error(
				'ollama_api_error',
				sprintf(
					/* translators: %d: HTTP status code */
					__( 'Ollama API error (HTTP %d): %s', 'ollama-ai-chat' ),
					$code,
					$message
				)
			);
		}

		$data = json_decode( $body, true );

		if ( null === $data ) {
			return new WP_Error(
				'ollama_invalid_response',
				__( 'Received an invalid response from Ollama.', 'ollama-ai-chat' )
			);
		}

		$content = '';
		if ( isset( $data['message']['content'] ) ) {
			$content = $data['message']['content'];
		} elseif ( isset( $data['response'] ) ) {
			$content = $data['response'];
		}

		return array(
			'content' => $content,
			'model'   => isset( $data['model'] ) ? $data['model'] : $payload['model'],
			'done'    => isset( $data['done'] ) ? (bool) $data['done'] : true,
			'raw'     => $data,
		);
	}

	/**
	 * Stream chat from Ollama, calling callback for each line.
	 *
	 * @param array    $messages Messages.
	 * @param callable $callback Callback( string $chunk, bool $done ).
	 * @param string   $model    Model override.
	 * @return true|WP_Error
	 */
	public static function stream_chat( array $messages, callable $callback, string $model = '' ) {
		$payload = self::build_payload( $messages, true, $model );

		if ( is_wp_error( $payload ) ) {
			return $payload;
		}

		$url = Ollama_AI_Chat_Plugin::get_option( 'ollama_base_url', 'http://host.docker.internal:11434/api/chat' );
		$url = esc_url_raw( $url );

		if ( empty( $url ) ) {
			return new WP_Error(
				'ollama_invalid_url',
				__( 'Ollama API URL is not configured.', 'ollama-ai-chat' )
			);
		}

		$body = wp_json_encode( $payload );

		$ch = curl_init(); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_init

		if ( false === $ch ) {
			return new WP_Error(
				'ollama_curl_unavailable',
				__( 'cURL is required for streaming but is not available.', 'ollama-ai-chat' )
			);
		}

		$buffer = '';

		curl_setopt_array( // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt_array
			$ch,
			array(
				CURLOPT_URL            => $url,
				CURLOPT_POST           => true,
				CURLOPT_POSTFIELDS     => $body,
				CURLOPT_HTTPHEADER     => array( 'Content-Type: application/json' ),
				CURLOPT_RETURNTRANSFER => false,
				CURLOPT_TIMEOUT        => 120,
				CURLOPT_WRITEFUNCTION  => function ( $curl, $data ) use ( &$buffer, $callback ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
					$buffer .= $data;
					$lines   = explode( "\n", $buffer );
					$buffer  = array_pop( $lines );

					foreach ( $lines as $line ) {
						$line = trim( $line );
						if ( '' === $line ) {
							continue;
						}

						$json = json_decode( $line, true );
						if ( ! is_array( $json ) ) {
							continue;
						}

						$chunk = '';
						if ( isset( $json['message']['content'] ) ) {
							$chunk = $json['message']['content'];
						}

						$done = ! empty( $json['done'] );
						$callback( $chunk, $done );

						if ( $done ) {
							return strlen( $data );
						}
					}

					return strlen( $data );
				},
			)
		);

		$result = curl_exec( $ch ); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_exec
		$error  = curl_error( $ch ); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_error
		$code   = curl_getinfo( $ch, CURLINFO_HTTP_CODE ); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_getinfo
		curl_close( $ch ); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_close

		if ( false === $result ) {
			return new WP_Error(
				'ollama_stream_failed',
				sprintf(
					/* translators: %s: error message */
					__( 'Streaming failed: %s', 'ollama-ai-chat' ),
					$error
				)
			);
		}

		if ( $code < 200 || $code >= 300 ) {
			return new WP_Error(
				'ollama_stream_http_error',
				sprintf(
					/* translators: %d: HTTP status code */
					__( 'Ollama streaming error (HTTP %d).', 'ollama-ai-chat' ),
					$code
				)
			);
		}

		return true;
	}

	/**
	 * Sanitize messages array.
	 *
	 * @param array $messages Raw messages.
	 * @return array|WP_Error
	 */
	public static function sanitize_messages( array $messages ) {
		if ( empty( $messages ) ) {
			return new WP_Error(
				'ollama_empty_messages',
				__( 'No messages provided.', 'ollama-ai-chat' )
			);
		}

		$allowed_roles = array( 'user', 'assistant', 'system' );
		$sanitized     = array();
		$max_messages  = (int) apply_filters( 'ollama_ai_chat_max_messages', 50 );
		$max_length    = (int) apply_filters( 'ollama_ai_chat_max_message_length', 10000 );

		$messages = array_slice( $messages, -$max_messages );

		foreach ( $messages as $message ) {
			if ( ! is_array( $message ) || empty( $message['role'] ) || ! isset( $message['content'] ) ) {
				continue;
			}

			$role = sanitize_text_field( $message['role'] );
			if ( ! in_array( $role, $allowed_roles, true ) ) {
				continue;
			}

			$content = wp_strip_all_tags( (string) $message['content'] );
			if ( strlen( $content ) > $max_length ) {
				$content = substr( $content, 0, $max_length );
			}

			if ( '' === $content ) {
				continue;
			}

			$sanitized[] = array(
				'role'    => $role,
				'content' => $content,
			);
		}

		if ( empty( $sanitized ) ) {
			return new WP_Error(
				'ollama_invalid_messages',
				__( 'No valid messages after sanitization.', 'ollama-ai-chat' )
			);
		}

		return $sanitized;
	}
}
