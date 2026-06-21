<?php
/**
 * Message repository — wp_prose_messages.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Users\Database\Repositories;

use ProSe\Core\Forms\Database\Repositories\Abstract_Repository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Message_Repository
 */
final class Message_Repository extends Abstract_Repository {

	/**
	 * {@inheritDoc}
	 */
	protected function primary_key_column(): string {
		return 'message_id';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function table_suffix(): string {
		return 'prose_messages';
	}

	/**
	 * Append a message to a conversation.
	 *
	 * @param int    $conversation_id Conversation ID.
	 * @param string $role            Message role.
	 * @param string $content         Message content.
	 * @param int    $sequence        Sequence number.
	 * @return int Message ID.
	 */
	public function append( int $conversation_id, string $role, string $content, int $sequence ): int {
		global $wpdb;

		if ( $conversation_id <= 0 ) {
			return 0;
		}

		$now = $this->now();

		$wpdb->insert(
			$this->table(),
			array(
				'conversation_id' => $conversation_id,
				'role'            => sanitize_key( $role ),
				'content'         => wp_kses_post( $content ),
				'sequence'        => max( 1, $sequence ),
				'created_at'      => $now,
			)
		);

		return (int) $wpdb->insert_id;
	}

	/**
	 * Bulk insert session messages into a conversation.
	 *
	 * @param int                      $conversation_id Conversation ID.
	 * @param array<int, array<string, mixed>> $messages Session messages.
	 * @return int Number of rows inserted.
	 */
	public function bulk_insert_from_session( int $conversation_id, array $messages ): int {
		$count = 0;

		foreach ( $messages as $message ) {
			if ( ! is_array( $message ) ) {
				continue;
			}

			$role    = sanitize_key( (string) ( $message['role'] ?? 'user' ) );
			$content = (string) ( $message['text'] ?? $message['content'] ?? '' );
			$seq     = (int) ( $message['id'] ?? ( $count + 1 ) );

			if ( '' === trim( $content ) ) {
				continue;
			}

			if ( $this->append( $conversation_id, $role, $content, $seq ) > 0 ) {
				++$count;
			}
		}

		return $count;
	}

	/**
	 * Latest message preview for a conversation.
	 *
	 * @param int $conversation_id Conversation ID.
	 * @return string
	 */
	public function latest_preview( int $conversation_id ): string {
		global $wpdb;

		if ( $conversation_id <= 0 ) {
			return '';
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$content = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT content FROM {$this->table()} WHERE conversation_id = %d ORDER BY sequence DESC LIMIT 1",
				$conversation_id
			)
		);

		$text = is_string( $content ) ? wp_strip_all_tags( $content ) : '';

		if ( strlen( $text ) > 120 ) {
			return substr( $text, 0, 117 ) . '…';
		}

		return $text;
	}

	/**
	 * Count messages in a conversation.
	 *
	 * @param int $conversation_id Conversation ID.
	 * @return int
	 */
	public function count_for_conversation( int $conversation_id ): int {
		global $wpdb;

		if ( $conversation_id <= 0 ) {
			return 0;
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$count = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$this->table()} WHERE conversation_id = %d",
				$conversation_id
			)
		);

		return max( 0, (int) $count );
	}

	/**
	 * List messages for a conversation in order.
	 *
	 * @param int $conversation_id Conversation ID.
	 * @param int $limit           Max rows.
	 * @return array<int, array{role: string, content: string, sequence: int, created_at: string}>
	 */
	public function list_for_conversation( int $conversation_id, int $limit = 200 ): array {
		global $wpdb;

		if ( $conversation_id <= 0 ) {
			return array();
		}

		$limit = max( 1, min( 500, $limit ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT role, content, sequence, created_at FROM {$this->table()} WHERE conversation_id = %d ORDER BY sequence ASC LIMIT %d",
				$conversation_id,
				$limit
			)
		);

		if ( ! is_array( $rows ) ) {
			return array();
		}

		$messages = array();

		foreach ( $rows as $row ) {
			if ( ! $row instanceof \stdClass ) {
				continue;
			}

			$messages[] = array(
				'role'       => (string) $row->role,
				'content'    => (string) $row->content,
				'sequence'   => (int) $row->sequence,
				'created_at' => (string) $row->created_at,
			);
		}

		return $messages;
	}

	/**
	 * Next sequence number for a conversation.
	 *
	 * @param int $conversation_id Conversation ID.
	 * @return int
	 */
	public function next_sequence( int $conversation_id ): int {
		global $wpdb;

		if ( $conversation_id <= 0 ) {
			return 1;
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$max = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT MAX(sequence) FROM {$this->table()} WHERE conversation_id = %d",
				$conversation_id
			)
		);

		return max( 1, (int) $max + 1 );
	}
}
