<?php
/**
 * Dual-write conversation persistence for logged-in users.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Users;

use ProSe\Core\Users\Database\Repositories\Conversation_Repository;
use ProSe\Core\Users\Database\Repositories\Message_Repository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Conversation_Persistence
 */
final class Conversation_Persistence {

	/**
	 * @var Conversation_Repository
	 */
	private Conversation_Repository $conversations;

	/**
	 * @var Message_Repository
	 */
	private Message_Repository $messages;

	/**
	 * Constructor.
	 *
	 * @param Conversation_Repository|null $conversations Conversation repository.
	 * @param Message_Repository|null      $messages      Message repository.
	 */
	public function __construct(
		?Conversation_Repository $conversations = null,
		?Message_Repository $messages = null
	) {
		$this->conversations = $conversations ?? new Conversation_Repository();
		$this->messages      = $messages ?? new Message_Repository();
	}

	/**
	 * Ensure a DB conversation exists for a session when user is logged in.
	 *
	 * @param array<string, mixed> $session Session payload.
	 * @return int Conversation ID (0 when guest).
	 */
	public function ensure_for_session( array $session ): int {
		$user_id = get_current_user_id();

		if ( $user_id <= 0 ) {
			return 0;
		}

		$session_id = (string) ( $session['session_id'] ?? '' );

		if ( '' === $session_id ) {
			return 0;
		}

		$row = $this->conversations->find_by_session_id( $session_id );

		if ( $row ) {
			if ( (int) $row->user_id !== $user_id && (int) $row->user_id > 0 ) {
				return 0;
			}

			if ( (int) $row->user_id <= 0 ) {
				$this->conversations->assign_user( (int) $row->conversation_id, $user_id );
			}

			return (int) $row->conversation_id;
		}

		$title = $this->derive_title( $session );

		return $this->conversations->create( $user_id, $session_id, $title );
	}

	/**
	 * Persist chat messages for a logged-in user's session.
	 *
	 * @param array<string, mixed> $session Session payload.
	 * @param string               $role    Message role.
	 * @param string               $content Message content.
	 * @return void
	 */
	public function append_message( array $session, string $role, string $content ): void {
		$conversation_id = $this->ensure_for_session( $session );

		if ( $conversation_id <= 0 ) {
			return;
		}

		$seq = (int) ( $session['message_seq'] ?? 0 );

		$this->messages->append( $conversation_id, $role, $content, $seq );
		$this->conversations->touch( $conversation_id );
	}

	/**
	 * Link conversation to a persisted case.
	 *
	 * @param string $session_id Session UUID.
	 * @param int    $case_id    Case ID.
	 * @return void
	 */
	public function link_case( string $session_id, int $case_id ): void {
		$row = $this->conversations->find_by_session_id( $session_id );

		if ( ! $row || $case_id <= 0 ) {
			return;
		}

		$this->conversations->link_case( (int) $row->conversation_id, $case_id );
	}

	/**
	 * Whether the current user owns the session conversation.
	 *
	 * @param string $session_id Session UUID.
	 * @return bool|null True/false when row exists; null when no row yet.
	 */
	public function user_owns_session( string $session_id ): ?bool {
		$row = $this->conversations->find_by_session_id( $session_id );

		if ( ! $row ) {
			return null;
		}

		$owner = (int) $row->user_id;

		if ( $owner <= 0 ) {
			return true;
		}

		return $owner === get_current_user_id();
	}

	/**
	 * Derive a conversation title from session data.
	 *
	 * @param array<string, mixed> $session Session payload.
	 * @return string
	 */
	private function derive_title( array $session ): string {
		$messages = is_array( $session['messages'] ?? null ) ? $session['messages'] : array();

		foreach ( $messages as $message ) {
			if ( ! is_array( $message ) ) {
				continue;
			}

			if ( 'user' === ( $message['role'] ?? '' ) ) {
				$text = wp_strip_all_tags( (string) ( $message['text'] ?? '' ) );

				if ( '' !== $text ) {
					return strlen( $text ) > 80 ? substr( $text, 0, 77 ) . '…' : $text;
				}
			}
		}

		return __( 'New conversation', 'prose-core' );
	}
}
