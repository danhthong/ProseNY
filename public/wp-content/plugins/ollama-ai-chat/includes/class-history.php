<?php
/**
 * Chat history database operations.
 *
 * @package Ollama_AI_Chat
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Ollama_AI_Chat_History
 */
class Ollama_AI_Chat_History {

	/**
	 * Get table name with prefix.
	 *
	 * @return string
	 */
	public static function table_name(): string {
		global $wpdb;
		return $wpdb->prefix . 'ollama_ai_chat_messages';
	}

	/**
	 * Create database table on activation.
	 */
	public static function create_table(): void {
		global $wpdb;

		$table_name      = self::table_name();
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table_name} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			role varchar(20) NOT NULL DEFAULT 'user',
			content longtext NOT NULL,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY user_id (user_id),
			KEY created_at (created_at)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Get messages for a user.
	 *
	 * @param int $user_id User ID.
	 * @param int $limit   Max messages.
	 * @return array<int, array{role: string, content: string, timestamp: string}>
	 */
	public static function get_messages( int $user_id, int $limit = 100 ): array {
		global $wpdb;

		if ( $user_id <= 0 ) {
			return array();
		}

		$table = self::table_name();
		$limit = max( 1, min( 200, $limit ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT role, content, created_at FROM {$table} WHERE user_id = %d ORDER BY created_at ASC, id ASC LIMIT %d",
				$user_id,
				$limit
			),
			ARRAY_A
		);

		if ( ! is_array( $rows ) ) {
			return array();
		}

		$messages = array();
		foreach ( $rows as $row ) {
			$messages[] = array(
				'role'      => sanitize_text_field( $row['role'] ),
				'content'   => $row['content'],
				'timestamp' => $row['created_at'],
			);
		}

		return $messages;
	}

	/**
	 * Save a single message.
	 *
	 * @param int    $user_id User ID.
	 * @param string $role    Message role.
	 * @param string $content Message content.
	 * @return bool
	 */
	public static function save_message( int $user_id, string $role, string $content ): bool {
		global $wpdb;

		if ( $user_id <= 0 ) {
			return false;
		}

		$allowed_roles = array( 'user', 'assistant', 'system' );
		if ( ! in_array( $role, $allowed_roles, true ) ) {
			return false;
		}

		$result = $wpdb->insert(
			self::table_name(),
			array(
				'user_id'    => $user_id,
				'role'       => $role,
				'content'    => wp_kses_post( $content ),
				'created_at' => current_time( 'mysql' ),
			),
			array( '%d', '%s', '%s', '%s' )
		);

		return false !== $result;
	}

	/**
	 * Save multiple messages.
	 *
	 * @param int   $user_id  User ID.
	 * @param array $messages Messages array.
	 * @return bool
	 */
	public static function save_messages( int $user_id, array $messages ): bool {
		$success = true;
		foreach ( $messages as $message ) {
			if ( empty( $message['role'] ) || ! isset( $message['content'] ) ) {
				continue;
			}
			$result = self::save_message( $user_id, $message['role'], $message['content'] );
			if ( ! $result ) {
				$success = false;
			}
		}
		return $success;
	}

	/**
	 * Clear all messages for a user.
	 *
	 * @param int $user_id User ID.
	 * @return bool
	 */
	public static function clear_messages( int $user_id ): bool {
		global $wpdb;

		if ( $user_id <= 0 ) {
			return false;
		}

		$result = $wpdb->delete(
			self::table_name(),
			array( 'user_id' => $user_id ),
			array( '%d' )
		);

		return false !== $result;
	}

	/**
	 * Whether DB history should be used for current user.
	 *
	 * @return bool
	 */
	public static function should_use_db(): bool {
		$mode = Ollama_AI_Chat_Plugin::get_option( 'ollama_history_mode', 'both' );

		if ( 'local' === $mode ) {
			return false;
		}

		if ( 'db' === $mode ) {
			return is_user_logged_in();
		}

		// 'both' mode: DB for logged-in users.
		return is_user_logged_in();
	}
}
