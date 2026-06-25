<?php
/**
 * Conversation repository — wp_prose_conversations.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Users\Database\Repositories;

use ProSe\Core\Forms\Database\Repositories\Abstract_Repository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Conversation_Repository
 */
final class Conversation_Repository extends Abstract_Repository {

	/**
	 * {@inheritDoc}
	 */
	protected function primary_key_column(): string {
		return 'conversation_id';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function table_suffix(): string {
		return 'prose_conversations';
	}

	/**
	 * Create a conversation row.
	 *
	 * @param int    $user_id    Owner user ID (0 for guest/unclaimed).
	 * @param string $session_id Session UUID.
	 * @param string $title      Optional title.
	 * @return int Conversation ID.
	 */
	public function create( int $user_id, string $session_id, string $title = '' ): int {
		global $wpdb;

		$now = $this->now();

		$wpdb->insert(
			$this->table(),
			array(
				'user_id'    => max( 0, $user_id ),
				'case_id'    => null,
				'session_id' => sanitize_text_field( $session_id ),
				'title'      => sanitize_text_field( $title ),
				'status'     => 'active',
				'created_at' => $now,
				'updated_at' => $now,
			)
		);

		return (int) $wpdb->insert_id;
	}

	/**
	 * Find conversation by session id.
	 *
	 * @param string $session_id Session UUID.
	 * @return object|null
	 */
	public function find_by_session_id( string $session_id ): ?object {
		global $wpdb;

		if ( '' === $session_id ) {
			return null;
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$this->table()} WHERE session_id = %s LIMIT 1",
				$session_id
			)
		);

		return $row instanceof \stdClass ? $row : null;
	}

	/**
	 * Assign a user to a conversation.
	 *
	 * @param int $conversation_id Conversation ID.
	 * @param int $user_id         User ID.
	 * @return bool
	 */
	public function assign_user( int $conversation_id, int $user_id ): bool {
		global $wpdb;

		if ( $conversation_id <= 0 || $user_id <= 0 ) {
			return false;
		}

		return false !== $wpdb->update(
			$this->table(),
			array(
				'user_id'    => $user_id,
				'updated_at' => $this->now(),
			),
			array( 'conversation_id' => $conversation_id )
		);
	}

	/**
	 * Link a case to a conversation.
	 *
	 * @param int $conversation_id Conversation ID.
	 * @param int $case_id         Case ID.
	 * @return bool
	 */
	public function link_case( int $conversation_id, int $case_id ): bool {
		global $wpdb;

		if ( $conversation_id <= 0 || $case_id <= 0 ) {
			return false;
		}

		return false !== $wpdb->update(
			$this->table(),
			array(
				'case_id'    => $case_id,
				'updated_at' => $this->now(),
			),
			array( 'conversation_id' => $conversation_id )
		);
	}

	/**
	 * Touch updated_at timestamp.
	 *
	 * @param int $conversation_id Conversation ID.
	 * @return void
	 */
	public function touch( int $conversation_id ): void {
		global $wpdb;

		if ( $conversation_id <= 0 ) {
			return;
		}

		$wpdb->update(
			$this->table(),
			array( 'updated_at' => $this->now() ),
			array( 'conversation_id' => $conversation_id )
		);
	}

	/**
	 * Update conversation title.
	 *
	 * @param int    $conversation_id Conversation ID.
	 * @param string $title         Title.
	 * @return bool
	 */
	public function update_title( int $conversation_id, string $title ): bool {
		global $wpdb;

		if ( $conversation_id <= 0 ) {
			return false;
		}

		return false !== $wpdb->update(
			$this->table(),
			array(
				'title'      => sanitize_text_field( $title ),
				'updated_at' => $this->now(),
			),
			array( 'conversation_id' => $conversation_id )
		);
	}

	/**
	 * Recent conversations for a user.
	 *
	 * @param int $user_id User ID.
	 * @param int $limit   Max rows.
	 * @return object[]
	 */
	public function recent_for_user( int $user_id, int $limit = 5 ): array {
		global $wpdb;

		if ( $user_id <= 0 ) {
			return array();
		}

		$limit = max( 1, min( 50, $limit ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$sql  = "SELECT * FROM {$this->table()} WHERE user_id = %d ORDER BY updated_at DESC LIMIT %d";
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $user_id, $limit ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Find a conversation owned by a user.
	 *
	 * @param int $user_id         User ID.
	 * @param int $conversation_id Conversation ID.
	 * @return object|null
	 */
	public function find_owned( int $user_id, int $conversation_id ): ?object {
		global $wpdb;

		if ( $user_id <= 0 || $conversation_id <= 0 ) {
			return null;
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$this->table()} WHERE conversation_id = %d AND user_id = %d LIMIT 1",
				$conversation_id,
				$user_id
			)
		);

		return $row instanceof \stdClass ? $row : null;
	}

	/**
	 * Find a conversation owned by a user via session UUID.
	 *
	 * @param int    $user_id    User ID.
	 * @param string $session_id Session UUID.
	 * @return object|null
	 */
	public function find_owned_by_session( int $user_id, string $session_id ): ?object {
		global $wpdb;

		if ( $user_id <= 0 || '' === $session_id ) {
			return null;
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$this->table()} WHERE session_id = %s AND user_id = %d LIMIT 1",
				$session_id,
				$user_id
			)
		);

		return $row instanceof \stdClass ? $row : null;
	}

	/**
	 * Persist intake snapshot JSON for resume.
	 *
	 * @param int                  $conversation_id Conversation ID.
	 * @param array<string, mixed> $context         Snapshot payload.
	 * @return bool
	 */
	public function update_context( int $conversation_id, array $context ): bool {
		global $wpdb;

		if ( $conversation_id <= 0 ) {
			return false;
		}

		$json = wp_json_encode( $context );

		if ( ! is_string( $json ) ) {
			return false;
		}

		return false !== $wpdb->update(
			$this->table(),
			array(
				'context_json' => $json,
				'updated_at'   => $this->now(),
			),
			array( 'conversation_id' => $conversation_id )
		);
	}

	/**
	 * Delete a conversation owned by the given user.
	 *
	 * @param int $user_id         User ID.
	 * @param int $conversation_id Conversation ID.
	 * @return bool
	 */
	public function delete_owned( int $user_id, int $conversation_id ): bool {
		global $wpdb;

		if ( $user_id <= 0 || $conversation_id <= 0 ) {
			return false;
		}

		$deleted = $wpdb->delete(
			$this->table(),
			array(
				'conversation_id' => $conversation_id,
				'user_id'         => $user_id,
			),
			array( '%d', '%d' )
		);

		return false !== $deleted && $deleted > 0;
	}
}
