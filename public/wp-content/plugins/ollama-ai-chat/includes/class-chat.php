<?php
/**
 * Frontend chat widget, shortcode, and asset loading.
 *
 * @package Ollama_AI_Chat
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Ollama_AI_Chat_Chat
 */
class Ollama_AI_Chat_Chat {

	/**
	 * Whether assets should be enqueued.
	 *
	 * @var bool
	 */
	private static bool $should_enqueue = false;

	/**
	 * Chat instances rendered on page.
	 *
	 * @var array<int, array<string, string>>
	 */
	private static array $instances = array();

	/**
	 * Initialize chat hooks.
	 */
	public static function init(): void {
		add_shortcode( 'ollama_ai_chat', array( self::class, 'render_shortcode' ) );
		add_action( 'wp_enqueue_scripts', array( self::class, 'register_assets' ) );
		add_action( 'wp_enqueue_scripts', array( self::class, 'maybe_enqueue_global_widget' ) );
		add_action( 'wp_footer', array( self::class, 'localize_script' ), 5 );
		add_action( 'wp_footer', array( self::class, 'maybe_render_widget' ), 20 );
		add_action( 'init', array( self::class, 'register_block' ) );
	}

	/**
	 * Register Gutenberg block.
	 */
	public static function register_block(): void {
		$block_json = OLLAMA_AI_CHAT_BUILD_PATH . 'block/block.json';

		if ( ! file_exists( $block_json ) ) {
			$block_json = OLLAMA_AI_CHAT_PATH . 'src/block/block.json';
		}

		if ( file_exists( $block_json ) ) {
			register_block_type(
				$block_json,
				array(
					'render_callback' => array( self::class, 'render_block' ),
				)
			);
		}
	}

	/**
	 * Render Gutenberg block.
	 *
	 * @param array    $attributes Block attributes.
	 * @param string   $content    Block content.
	 * @param WP_Block $block      Block instance.
	 * @return string
	 */
	public static function render_block( array $attributes, string $content, WP_Block $block ): string {
		$atts = array(
			'title'  => $attributes['title'] ?? '',
			'height' => $attributes['height'] ?? '500px',
			'theme'  => $attributes['theme'] ?? 'auto',
			'model'  => $attributes['modelOverride'] ?? '',
			'layout' => $attributes['layout'] ?? 'inline',
		);

		return self::render_chat( $atts );
	}

	/**
	 * Shortcode handler.
	 *
	 * @param array|string $atts Shortcode attributes.
	 * @return string
	 */
	public static function render_shortcode( $atts ): string {
		$atts = shortcode_atts(
			array(
				'title'  => '',
				'height' => '500px',
				'theme'  => 'auto',
				'model'  => '',
				'layout' => 'inline',
			),
			$atts,
			'ollama_ai_chat'
		);

		return self::render_chat( $atts );
	}

	/**
	 * Render chat mount point.
	 *
	 * @param array $atts Attributes.
	 * @return string
	 */
	public static function render_chat( array $atts ): string {
		self::$should_enqueue = true;
		self::enqueue_assets();

		$instance_id = 'ollama-chat-' . ( count( self::$instances ) + 1 );
		$config      = array(
			'id'     => $instance_id,
			'title'  => ! empty( $atts['title'] ) ? sanitize_text_field( $atts['title'] ) : Ollama_AI_Chat_Plugin::get_option( 'ollama_chat_title', 'AI Assistant' ),
			'height' => sanitize_text_field( $atts['height'] ),
			'theme'  => in_array( $atts['theme'], array( 'light', 'dark', 'auto' ), true ) ? $atts['theme'] : 'auto',
			'model'  => sanitize_text_field( $atts['model'] ),
			'layout' => in_array( $atts['layout'], array( 'widget', 'inline' ), true ) ? $atts['layout'] : 'widget',
		);

		self::$instances[] = $config;

		ob_start();
		include OLLAMA_AI_CHAT_TEMPLATES_PATH . 'chat-window.php';
		return ob_get_clean();
	}

	/**
	 * Render global floating widget in footer.
	 */
	public static function maybe_render_widget(): void {
		if ( ! Ollama_AI_Chat_Plugin::get_option( 'ollama_show_widget', true ) ) {
			return;
		}

		// Skip if inline/shortcode instance already on page with widget layout.
		foreach ( self::$instances as $instance ) {
			if ( 'widget' === $instance['layout'] ) {
				return;
			}
		}

		echo self::render_chat(
			array(
				'title'  => '',
				'height' => '520px',
				'theme'  => 'auto',
				'model'  => '',
				'layout' => 'widget',
			)
		);
	}

	/**
	 * Register styles and scripts (early).
	 */
	public static function register_assets(): void {
		if ( is_admin() ) {
			return;
		}

		$css_file = OLLAMA_AI_CHAT_PATH . 'assets/css/chat.css';
		$js_file  = OLLAMA_AI_CHAT_PATH . 'assets/js/chat.js';

		if ( file_exists( $css_file ) ) {
			wp_register_style(
				'ollama-ai-chat',
				OLLAMA_AI_CHAT_ASSETS_URL . 'css/chat.css',
				array(),
				filemtime( $css_file )
			);
		}

		if ( file_exists( $js_file ) ) {
			wp_register_script(
				'ollama-ai-chat',
				OLLAMA_AI_CHAT_ASSETS_URL . 'js/chat.js',
				array(),
				filemtime( $js_file ),
				true
			);
		}
	}

	/**
	 * Enqueue global floating widget assets on all front-end pages when enabled.
	 */
	public static function maybe_enqueue_global_widget(): void {
		if ( is_admin() ) {
			return;
		}

		if ( Ollama_AI_Chat_Plugin::get_option( 'ollama_show_widget', true ) ) {
			self::$should_enqueue = true;
			self::enqueue_assets();
		}
	}

	/**
	 * Enqueue registered assets (safe to call during shortcode render).
	 */
	public static function enqueue_assets(): void {
		if ( ! self::$should_enqueue ) {
			return;
		}

		if ( wp_style_is( 'ollama-ai-chat', 'registered' ) ) {
			wp_enqueue_style( 'ollama-ai-chat' );

			$primary_color = Ollama_AI_Chat_Plugin::get_option( 'ollama_primary_color', '#6366f1' );
			wp_add_inline_style(
				'ollama-ai-chat',
				sprintf( ':root { --ollama-primary: %s; }', esc_attr( $primary_color ) )
			);
		}

		if ( wp_script_is( 'ollama-ai-chat', 'registered' ) ) {
			wp_enqueue_script( 'ollama-ai-chat' );
		}
	}

	/**
	 * Output localized config after shortcodes/blocks have registered instances.
	 */
	public static function localize_script(): void {
		if ( ! self::$should_enqueue || ! wp_script_is( 'ollama-ai-chat', 'enqueued' ) ) {
			return;
		}

		wp_localize_script(
			'ollama-ai-chat',
			'ollamaAiChat',
			array(
				'restUrl'         => esc_url_raw( rest_url( Ollama_AI_Chat_REST::REST_NAMESPACE . '/' ) ),
				'nonce'           => wp_create_nonce( 'wp_rest' ),
				'title'           => Ollama_AI_Chat_Plugin::get_option( 'ollama_chat_title', 'AI Assistant' ),
				'primaryColor'    => Ollama_AI_Chat_Plugin::get_option( 'ollama_primary_color', '#6366f1' ),
				'historyMode'     => Ollama_AI_Chat_Plugin::get_option( 'ollama_history_mode', 'both' ),
				'enableStreaming' => (bool) Ollama_AI_Chat_Plugin::get_option( 'ollama_enable_streaming', false ),
				'userId'          => get_current_user_id(),
				'isLoggedIn'      => is_user_logged_in(),
				'siteId'          => get_current_blog_id(),
				'instances'       => self::$instances,
				'i18n'            => array(
					'placeholder'   => __( 'Type your message...', 'ollama-ai-chat' ),
					'send'          => __( 'Send', 'ollama-ai-chat' ),
					'clear'         => __( 'Clear chat', 'ollama-ai-chat' ),
					'close'         => __( 'Close', 'ollama-ai-chat' ),
					'open'          => __( 'Open chat', 'ollama-ai-chat' ),
					'thinking'      => __( 'Thinking...', 'ollama-ai-chat' ),
					'error'         => __( 'Something went wrong. Please try again.', 'ollama-ai-chat' ),
					'loginRequired' => __( 'Please log in to use the chat.', 'ollama-ai-chat' ),
					'copy'          => __( 'Copy', 'ollama-ai-chat' ),
					'copied'        => __( 'Copied!', 'ollama-ai-chat' ),
				),
			)
		);
	}
}
