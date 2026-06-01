<?php
/**
 * Plugin Name:       Ollama AI Chat
 * Plugin URI:        https://github.com/prose-ai/ollama-ai-chat
 * Description:       Modern AI chat widget for WordPress powered by a local Ollama API.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      8.2
 * Author:            Prose
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       ollama-ai-chat
 *
 * @package Ollama_AI_Chat
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'OLLAMA_AI_CHAT_VERSION', '1.0.0' );
define( 'OLLAMA_AI_CHAT_FILE', __FILE__ );
define( 'OLLAMA_AI_CHAT_PATH', plugin_dir_path( __FILE__ ) );
define( 'OLLAMA_AI_CHAT_URL', plugin_dir_url( __FILE__ ) );
define( 'OLLAMA_AI_CHAT_BASENAME', plugin_basename( __FILE__ ) );
define( 'OLLAMA_AI_CHAT_INCLUDES_PATH', OLLAMA_AI_CHAT_PATH . 'includes/' );
define( 'OLLAMA_AI_CHAT_TEMPLATES_PATH', OLLAMA_AI_CHAT_PATH . 'templates/' );
define( 'OLLAMA_AI_CHAT_ASSETS_URL', OLLAMA_AI_CHAT_URL . 'assets/' );
define( 'OLLAMA_AI_CHAT_BUILD_PATH', OLLAMA_AI_CHAT_PATH . 'build/' );

/**
 * Autoload plugin classes.
 *
 * @param string $class_name Class name.
 */
function ollama_ai_chat_autoload( string $class_name ): void {
	if ( strpos( $class_name, 'Ollama_AI_Chat_' ) !== 0 ) {
		return;
	}

	$file = OLLAMA_AI_CHAT_INCLUDES_PATH . 'class-' . strtolower( str_replace( '_', '-', substr( $class_name, 15 ) ) ) . '.php';

	if ( file_exists( $file ) ) {
		require_once $file;
	}
}

spl_autoload_register( 'ollama_ai_chat_autoload' );

/**
 * Plugin activation.
 */
function ollama_ai_chat_activate(): void {
	Ollama_AI_Chat_Plugin::activate();
}

/**
 * Plugin deactivation.
 */
function ollama_ai_chat_deactivate(): void {
	Ollama_AI_Chat_Plugin::deactivate();
}

register_activation_hook( __FILE__, 'ollama_ai_chat_activate' );
register_deactivation_hook( __FILE__, 'ollama_ai_chat_deactivate' );

add_action( 'plugins_loaded', array( 'Ollama_AI_Chat_Plugin', 'instance' ) );
