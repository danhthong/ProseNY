<?php
/**
 * Uninstall handler for Ollama AI Chat.
 *
 * @package Ollama_AI_Chat
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

$table_name = $wpdb->prefix . 'ollama_ai_chat_messages';
// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$wpdb->query( "DROP TABLE IF EXISTS {$table_name}" );

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
	'ollama_ai_chat_version',
);

foreach ( $options as $option ) {
	delete_option( $option );
}
