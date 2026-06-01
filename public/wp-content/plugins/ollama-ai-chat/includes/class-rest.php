<?php
/**
 * REST API endpoints.
 *
 * @package Ollama_AI_Chat
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Ollama_AI_Chat_REST
 */
class Ollama_AI_Chat_REST {

	/**
	 * REST namespace.
	 */
	const REST_NAMESPACE = 'ollama-ai/v1';

	/**
	 * Initialize REST routes.
	 */
	public static function init(): void {
		add_action( 'rest_api_init', array( self::class, 'register_routes' ) );
	}

	/**
	 * Register REST routes.
	 */
	public static function register_routes(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			'/chat',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( self::class, 'handle_chat' ),
				'permission_callback' => array( self::class, 'check_permission' ),
				'args'                => array(
					'messages' => array(
						'required'          => true,
						'type'              => 'array',
						'sanitize_callback' => array( self::class, 'sanitize_messages_arg' ),
					),
					'stream'   => array(
						'type'    => 'boolean',
						'default' => false,
					),
					'model'    => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
						'default'           => '',
					),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/chat/stream',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( self::class, 'handle_chat_stream' ),
				'permission_callback' => array( self::class, 'check_permission' ),
				'args'                => array(
					'messages' => array(
						'required'          => true,
						'type'              => 'array',
						'sanitize_callback' => array( self::class, 'sanitize_messages_arg' ),
					),
					'model'    => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
						'default'           => '',
					),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/history',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( self::class, 'get_history' ),
					'permission_callback' => array( self::class, 'check_permission' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( self::class, 'save_history' ),
					'permission_callback' => array( self::class, 'check_permission' ),
					'args'                => array(
						'messages' => array(
							'required' => true,
							'type'     => 'array',
						),
					),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( self::class, 'delete_history' ),
					'permission_callback' => array( self::class, 'check_permission' ),
				),
			)
		);
	}

	/**
	 * Permission callback.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return bool|WP_Error
	 */
	public static function check_permission( WP_REST_Request $request ) {
		$nonce = $request->get_header( 'X-WP-Nonce' );

		if ( ! $nonce || ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'Invalid security token.', 'ollama-ai-chat' ),
				array( 'status' => 403 )
			);
		}

		if ( ! self::user_has_allowed_role() ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'You do not have permission to use the chat.', 'ollama-ai-chat' ),
				array( 'status' => 403 )
			);
		}

		if ( ! self::check_rate_limit() ) {
			return new WP_Error(
				'ollama_rate_limited',
				__( 'Too many requests. Please wait a moment and try again.', 'ollama-ai-chat' ),
				array( 'status' => 429 )
			);
		}

		return true;
	}

	/**
	 * Check if current user has an allowed role.
	 *
	 * @return bool
	 */
	public static function user_has_allowed_role(): bool {
		if ( ! is_user_logged_in() ) {
			return false;
		}

		$user           = wp_get_current_user();
		$allowed_roles  = Ollama_AI_Chat_Plugin::get_option( 'ollama_allowed_roles', array( 'subscriber' ) );
		$allowed_roles  = is_array( $allowed_roles ) ? $allowed_roles : array( 'subscriber' );
		$user_roles     = (array) $user->roles;

		foreach ( $user_roles as $role ) {
			if ( in_array( $role, $allowed_roles, true ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Rate limiting via transients.
	 *
	 * @return bool True if within limit.
	 */
	public static function check_rate_limit(): bool {
		$limit  = (int) Ollama_AI_Chat_Plugin::get_option( 'ollama_rate_limit_count', 20 );
		$window = (int) Ollama_AI_Chat_Plugin::get_option( 'ollama_rate_limit_window', 60 );

		$limit  = (int) apply_filters( 'ollama_ai_chat_rate_limit_count', $limit );
		$window = (int) apply_filters( 'ollama_ai_chat_rate_limit_window', $window );

		$user_id = get_current_user_id();
		$key     = $user_id > 0
			? 'ollama_rate_user_' . $user_id
			: 'ollama_rate_ip_' . md5( self::get_client_ip() );

		$count = (int) get_transient( $key );

		if ( $count >= $limit ) {
			return false;
		}

		set_transient( $key, $count + 1, $window );
		return true;
	}

	/**
	 * Get client IP address.
	 *
	 * @return string
	 */
	private static function get_client_ip(): string {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '0.0.0.0';
		return $ip;
	}

	/**
	 * Sanitize messages REST arg.
	 *
	 * @param mixed $messages Messages.
	 * @return array
	 */
	public static function sanitize_messages_arg( $messages ): array {
		if ( ! is_array( $messages ) ) {
			return array();
		}
		return $messages;
	}

	/**
	 * Handle non-streaming chat.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function handle_chat( WP_REST_Request $request ) {
		$messages = $request->get_param( 'messages' );
		$model    = $request->get_param( 'model' );

		$result = Ollama_AI_Chat_API::chat( $messages, false, $model );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return new WP_REST_Response(
			array(
				'success' => true,
				'message' => array(
					'role'    => 'assistant',
					'content' => $result['content'],
				),
				'model'   => $result['model'],
			),
			200
		);
	}

	/**
	 * Handle streaming chat via NDJSON.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public static function handle_chat_stream( WP_REST_Request $request ) {
		if ( ! Ollama_AI_Chat_Plugin::get_option( 'ollama_enable_streaming', false ) ) {
			status_header( 400 );
			wp_send_json(
				array(
					'code'    => 'streaming_disabled',
					'message' => __( 'Streaming is disabled in settings.', 'ollama-ai-chat' ),
				)
			);
		}

		$messages = $request->get_param( 'messages' );
		$model    = $request->get_param( 'model' );

		// Disable output buffering for streaming.
		while ( ob_get_level() > 0 ) {
			ob_end_flush();
		}

		header( 'Content-Type: text/event-stream' );
		header( 'Cache-Control: no-cache' );
		header( 'Connection: keep-alive' );
		header( 'X-Accel-Buffering: no' );

		$result = Ollama_AI_Chat_API::stream_chat(
			$messages,
			function ( string $chunk, bool $done ) {
				$data = wp_json_encode(
					array(
						'chunk' => $chunk,
						'done'  => $done,
					)
				);
				echo 'data: ' . $data . "\n\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				if ( function_exists( 'flush' ) ) {
					flush();
				}
			},
			$model
		);

		if ( is_wp_error( $result ) ) {
			echo 'data: ' . wp_json_encode( array( 'error' => $result->get_error_message(), 'done' => true ) ) . "\n\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}

		exit;
	}

	/**
	 * Get chat history.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function get_history( WP_REST_Request $request ) {
		if ( ! Ollama_AI_Chat_History::should_use_db() ) {
			return new WP_REST_Response( array( 'messages' => array() ), 200 );
		}

		$user_id  = get_current_user_id();
		$messages = Ollama_AI_Chat_History::get_messages( $user_id );

		return new WP_REST_Response(
			array(
				'messages' => $messages,
			),
			200
		);
	}

	/**
	 * Save chat history.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function save_history( WP_REST_Request $request ) {
		if ( ! Ollama_AI_Chat_History::should_use_db() ) {
			return new WP_REST_Response( array( 'success' => true ), 200 );
		}

		$user_id  = get_current_user_id();
		$messages = $request->get_param( 'messages' );

		if ( ! is_array( $messages ) ) {
			return new WP_Error( 'invalid_messages', __( 'Invalid messages.', 'ollama-ai-chat' ), array( 'status' => 400 ) );
		}

		foreach ( $messages as $message ) {
			if ( empty( $message['role'] ) || ! isset( $message['content'] ) ) {
				continue;
			}
			Ollama_AI_Chat_History::save_message( $user_id, $message['role'], $message['content'] );
		}

		return new WP_REST_Response( array( 'success' => true ), 200 );
	}

	/**
	 * Delete chat history.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function delete_history( WP_REST_Request $request ) {
		$user_id = get_current_user_id();
		Ollama_AI_Chat_History::clear_messages( $user_id );

		return new WP_REST_Response( array( 'success' => true ), 200 );
	}
}
