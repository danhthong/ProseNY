<?php
/**
 * Main plugin orchestrator.
 *
 * @package Ollama_AI_Chat
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Ollama_AI_Chat_Plugin
 */
class Ollama_AI_Chat_Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var Ollama_AI_Chat_Plugin|null
	 */
	private static ?Ollama_AI_Chat_Plugin $instance = null;

	/**
	 * Get singleton instance.
	 *
	 * @return Ollama_AI_Chat_Plugin
	 */
	public static function instance(): Ollama_AI_Chat_Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		add_action( 'init', array( $this, 'init' ), 20 );
	}

	/**
	 * Initialize plugin modules.
	 */
	public function init(): void {
		load_plugin_textdomain( 'ollama-ai-chat', false, dirname( OLLAMA_AI_CHAT_BASENAME ) . '/languages' );

		Ollama_AI_Chat_Admin::init();
		Ollama_AI_Chat_REST::init();
		Ollama_AI_Chat_Chat::init();
	}

	/**
	 * Activation hook handler.
	 */
	public static function activate(): void {
		self::set_default_options();
		Ollama_AI_Chat_History::create_table();
		update_option( 'ollama_ai_chat_version', OLLAMA_AI_CHAT_VERSION );
		flush_rewrite_rules();
	}

	/**
	 * Deactivation hook handler.
	 */
	public static function deactivate(): void {
		flush_rewrite_rules();
	}

	/**
	 * Set default plugin options on activation.
	 */
	private static function set_default_options(): void {
		$defaults = array(
			'ollama_base_url'           => 'http://host.docker.internal:11434/api/chat',
			'ollama_model'              => 'qwen2.5-coder:7b',
			'ollama_temperature'        => 0.7,
			'ollama_max_tokens'         => 2048,
			'ollama_system_prompt'      => __( 'You are a helpful AI assistant integrated into this WordPress site. Be concise, accurate, and friendly.', 'ollama-ai-chat' ),
			'ollama_enable_streaming'   => false,
			'ollama_chat_title'         => __( 'AI Assistant', 'ollama-ai-chat' ),
			'ollama_primary_color'      => '#6366f1',
			'ollama_allowed_roles'      => array( 'administrator', 'editor', 'author', 'subscriber' ),
			'ollama_history_mode'       => 'both',
			'ollama_show_widget'        => true,
			'ollama_rate_limit_count'   => 20,
			'ollama_rate_limit_window'  => 60,
		);

		foreach ( $defaults as $key => $value ) {
			if ( false === get_option( $key ) ) {
				add_option( $key, $value );
			}
		}
	}

	/**
	 * Get a plugin option with optional default.
	 *
	 * @param string $key     Option key.
	 * @param mixed  $default Default value.
	 * @return mixed
	 */
	public static function get_option( string $key, $default = null ) {
		$value = get_option( $key, $default );
		return apply_filters( 'ollama_ai_chat_option_' . $key, $value );
	}
}
