<?php
/**
 * Admin settings page.
 *
 * @package Ollama_AI_Chat
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Ollama_AI_Chat_Admin
 */
class Ollama_AI_Chat_Admin {

	/**
	 * Settings page slug.
	 */
	const PAGE_SLUG = 'ollama-ai-chat';

	/**
	 * Initialize admin hooks.
	 */
	public static function init(): void {
		add_action( 'admin_menu', array( self::class, 'add_menu_page' ) );
		add_action( 'admin_init', array( self::class, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue_admin_assets' ) );
	}

	/**
	 * Add settings submenu.
	 */
	public static function add_menu_page(): void {
		add_options_page(
			__( 'Ollama AI Chat', 'ollama-ai-chat' ),
			__( 'Ollama AI Chat', 'ollama-ai-chat' ),
			'manage_options',
			self::PAGE_SLUG,
			array( self::class, 'render_settings_page' )
		);
	}

	/**
	 * Register settings with Settings API.
	 */
	public static function register_settings(): void {
		$options = array(
			'ollama_base_url',
			'ollama_model',
			'ollama_temperature',
			'ollama_max_tokens',
			'ollama_system_prompt',
			'ollama_enable_streaming',
			'ollama_chat_title',
			'ollama_primary_color',
			'ollama_allowed_roles',
			'ollama_history_mode',
			'ollama_show_widget',
			'ollama_rate_limit_count',
			'ollama_rate_limit_window',
		);

		foreach ( $options as $option ) {
			register_setting(
				'ollama_ai_chat_settings',
				$option,
				array(
					'sanitize_callback' => function ( $value ) use ( $option ) {
						return self::sanitize_option( $value, $option );
					},
				)
			);
		}

		add_settings_section(
			'ollama_ai_chat_api',
			__( 'Ollama API Settings', 'ollama-ai-chat' ),
			array( self::class, 'render_api_section' ),
			self::PAGE_SLUG
		);

		add_settings_section(
			'ollama_ai_chat_ui',
			__( 'Chat UI Settings', 'ollama-ai-chat' ),
			array( self::class, 'render_ui_section' ),
			self::PAGE_SLUG
		);

		add_settings_section(
			'ollama_ai_chat_access',
			__( 'Access & Security', 'ollama-ai-chat' ),
			array( self::class, 'render_access_section' ),
			self::PAGE_SLUG
		);

		self::add_field( 'ollama_base_url', __( 'Ollama Base URL', 'ollama-ai-chat' ), 'url', 'ollama_ai_chat_api' );
		self::add_field( 'ollama_model', __( 'Model Name', 'ollama-ai-chat' ), 'text', 'ollama_ai_chat_api' );
		self::add_field( 'ollama_temperature', __( 'Temperature', 'ollama-ai-chat' ), 'number', 'ollama_ai_chat_api', array( 'min' => 0, 'max' => 2, 'step' => 0.1 ) );
		self::add_field( 'ollama_max_tokens', __( 'Max Tokens', 'ollama-ai-chat' ), 'number', 'ollama_ai_chat_api', array( 'min' => 1, 'max' => 32768 ) );
		self::add_field( 'ollama_system_prompt', __( 'System Prompt', 'ollama-ai-chat' ), 'textarea', 'ollama_ai_chat_api' );
		self::add_field( 'ollama_enable_streaming', __( 'Enable Streaming', 'ollama-ai-chat' ), 'checkbox', 'ollama_ai_chat_api' );

		self::add_field( 'ollama_chat_title', __( 'Chat Window Title', 'ollama-ai-chat' ), 'text', 'ollama_ai_chat_ui' );
		self::add_field( 'ollama_primary_color', __( 'Primary Color', 'ollama-ai-chat' ), 'color', 'ollama_ai_chat_ui' );
		self::add_field( 'ollama_show_widget', __( 'Show Floating Widget', 'ollama-ai-chat' ), 'checkbox', 'ollama_ai_chat_ui' );
		self::add_field( 'ollama_history_mode', __( 'History Storage Mode', 'ollama-ai-chat' ), 'select_history', 'ollama_ai_chat_ui' );

		self::add_field( 'ollama_allowed_roles', __( 'Allowed Roles', 'ollama-ai-chat' ), 'roles', 'ollama_ai_chat_access' );
		self::add_field( 'ollama_rate_limit_count', __( 'Rate Limit (requests)', 'ollama-ai-chat' ), 'number', 'ollama_ai_chat_access', array( 'min' => 1, 'max' => 1000 ) );
		self::add_field( 'ollama_rate_limit_window', __( 'Rate Limit Window (seconds)', 'ollama-ai-chat' ), 'number', 'ollama_ai_chat_access', array( 'min' => 10, 'max' => 3600 ) );
	}

	/**
	 * Add a settings field.
	 *
	 * @param string $id       Option ID.
	 * @param string $title    Field title.
	 * @param string $type     Field type.
	 * @param string $section  Settings section.
	 * @param array  $attrs    Extra HTML attributes.
	 */
	private static function add_field( string $id, string $title, string $type, string $section, array $attrs = array() ): void {
		add_settings_field(
			$id,
			$title,
			array( self::class, 'render_field' ),
			self::PAGE_SLUG,
			$section,
			array(
				'id'    => $id,
				'type'  => $type,
				'attrs' => $attrs,
			)
		);
	}

	/**
	 * Sanitize option values.
	 *
	 * @param mixed $value Raw value.
	 * @return mixed
	 */
	public static function sanitize_option( $value, $option = '' ) {
		switch ( $option ) {
			case 'ollama_base_url':
				return esc_url_raw( (string) $value );

			case 'ollama_temperature':
				return max( 0, min( 2, (float) $value ) );

			case 'ollama_max_tokens':
			case 'ollama_rate_limit_count':
			case 'ollama_rate_limit_window':
				return max( 1, (int) $value );

			case 'ollama_enable_streaming':
			case 'ollama_show_widget':
				return filter_var( $value, FILTER_VALIDATE_BOOLEAN );

			case 'ollama_primary_color':
				$color = sanitize_hex_color( (string) $value );
				return $color ? $color : '#6366f1';

			case 'ollama_allowed_roles':
				if ( ! is_array( $value ) ) {
					return array( 'subscriber' );
				}
				$all_roles = array_keys( wp_roles()->roles );
				return array_values( array_intersect( array_map( 'sanitize_text_field', $value ), $all_roles ) );

			case 'ollama_history_mode':
				$allowed = array( 'local', 'db', 'both' );
				return in_array( $value, $allowed, true ) ? $value : 'both';

			case 'ollama_system_prompt':
				return sanitize_textarea_field( (string) $value );

			default:
				return sanitize_text_field( (string) $value );
		}
	}

	/**
	 * Render settings field.
	 *
	 * @param array $args Field arguments.
	 */
	public static function render_field( array $args ): void {
		$id    = $args['id'];
		$type  = $args['type'];
		$attrs = $args['attrs'] ?? array();
		$value = get_option( $id );

		switch ( $type ) {
			case 'url':
			case 'text':
				printf(
					'<input type="%1$s" id="%2$s" name="%2$s" value="%3$s" class="regular-text" %4$s />',
					esc_attr( 'url' === $type ? 'url' : 'text' ),
					esc_attr( $id ),
					esc_attr( (string) $value ),
					self::attrs_to_string( $attrs )
				);
				break;

			case 'number':
				printf(
					'<input type="number" id="%1$s" name="%1$s" value="%2$s" class="small-text" %3$s />',
					esc_attr( $id ),
					esc_attr( (string) $value ),
					self::attrs_to_string( $attrs )
				);
				break;

			case 'textarea':
				printf(
					'<textarea id="%1$s" name="%1$s" rows="5" class="large-text">%2$s</textarea>',
					esc_attr( $id ),
					esc_textarea( (string) $value )
				);
				break;

			case 'checkbox':
				printf(
					'<input type="hidden" name="%1$s" value="0" /><input type="checkbox" id="%1$s" name="%1$s" value="1" %2$s />',
					esc_attr( $id ),
					checked( (bool) $value, true, false )
				);
				break;

			case 'color':
				printf(
					'<input type="color" id="%1$s" name="%1$s" value="%2$s" />',
					esc_attr( $id ),
					esc_attr( (string) $value )
				);
				break;

			case 'select_history':
				$modes = array(
					'local' => __( 'Browser localStorage only', 'ollama-ai-chat' ),
					'db'    => __( 'Database only (logged-in users)', 'ollama-ai-chat' ),
					'both'  => __( 'Both (localStorage for guests, DB for logged-in)', 'ollama-ai-chat' ),
				);
				echo '<select id="' . esc_attr( $id ) . '" name="' . esc_attr( $id ) . '">';
				foreach ( $modes as $key => $label ) {
					printf(
						'<option value="%1$s" %2$s>%3$s</option>',
						esc_attr( $key ),
						selected( $value, $key, false ),
						esc_html( $label )
					);
				}
				echo '</select>';
				break;

			case 'roles':
				$all_roles     = wp_roles()->roles;
				$selected      = is_array( $value ) ? $value : array();
				foreach ( $all_roles as $role_key => $role ) {
					printf(
						'<label style="display:block;margin-bottom:4px;"><input type="checkbox" name="%1$s[]" value="%2$s" %3$s /> %4$s</label>',
						esc_attr( $id ),
						esc_attr( $role_key ),
						checked( in_array( $role_key, $selected, true ), true, false ),
						esc_html( translate_user_role( $role['name'] ) )
					);
				}
				break;
		}

		self::render_field_description( $id );
	}

	/**
	 * Render field description.
	 *
	 * @param string $id Field ID.
	 */
	private static function render_field_description( string $id ): void {
		$descriptions = array(
			'ollama_base_url'      => __( 'Full URL to Ollama chat endpoint. Use host.docker.internal when WordPress runs in Docker.', 'ollama-ai-chat' ),
			'ollama_model'           => __( 'Default Ollama model name (e.g. qwen2.5-coder:7b).', 'ollama-ai-chat' ),
			'ollama_enable_streaming' => __( 'Enable streaming responses via Server-Sent Events.', 'ollama-ai-chat' ),
			'ollama_allowed_roles'   => __( 'Only users with these roles can use the chat.', 'ollama-ai-chat' ),
		);

		if ( isset( $descriptions[ $id ] ) ) {
			printf( '<p class="description">%s</p>', esc_html( $descriptions[ $id ] ) );
		}
	}

	/**
	 * Convert attrs array to HTML string.
	 *
	 * @param array $attrs Attributes.
	 * @return string
	 */
	private static function attrs_to_string( array $attrs ): string {
		$parts = array();
		foreach ( $attrs as $key => $val ) {
			$parts[] = sprintf( '%s="%s"', esc_attr( $key ), esc_attr( (string) $val ) );
		}
		return implode( ' ', $parts );
	}

	/**
	 * Render API section description.
	 */
	public static function render_api_section(): void {
		echo '<p>' . esc_html__( 'Configure connection to your local Ollama instance.', 'ollama-ai-chat' ) . '</p>';
	}

	/**
	 * Render UI section description.
	 */
	public static function render_ui_section(): void {
		echo '<p>' . esc_html__( 'Customize the appearance and behavior of the chat widget.', 'ollama-ai-chat' ) . '</p>';
	}

	/**
	 * Render access section description.
	 */
	public static function render_access_section(): void {
		echo '<p>' . esc_html__( 'Control who can access the chat and rate limiting.', 'ollama-ai-chat' ) . '</p>';
	}

	/**
	 * Render settings page.
	 */
	public static function render_settings_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<div class="wrap">
			<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
			<form method="post" action="options.php">
				<?php
				settings_fields( 'ollama_ai_chat_settings' );
				do_settings_sections( self::PAGE_SLUG );
				submit_button();
				?>
			</form>
			<hr />
			<h2><?php esc_html_e( 'Usage', 'ollama-ai-chat' ); ?></h2>
			<p><?php esc_html_e( 'Add the chat to any page using the shortcode:', 'ollama-ai-chat' ); ?></p>
			<code>[ollama_ai_chat]</code>
			<p><?php esc_html_e( 'Or use the "Ollama AI Chat" Gutenberg block.', 'ollama-ai-chat' ); ?></p>
		</div>
		<?php
	}

	/**
	 * Enqueue admin assets on settings page.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public static function enqueue_admin_assets( string $hook ): void {
		if ( 'settings_page_' . self::PAGE_SLUG !== $hook ) {
			return;
		}

		$js_path = OLLAMA_AI_CHAT_PATH . 'assets/js/admin-settings.js';
		if ( file_exists( $js_path ) ) {
			wp_enqueue_script(
				'ollama-ai-chat-admin',
				OLLAMA_AI_CHAT_ASSETS_URL . 'js/admin-settings.js',
				array(),
				OLLAMA_AI_CHAT_VERSION,
				true
			);
		}
	}
}
