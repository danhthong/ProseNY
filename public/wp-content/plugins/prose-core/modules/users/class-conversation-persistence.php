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
	 * Persist a homepage / AI intake chat turn for the logged-in user.
	 *
	 * @param string               $conversation_id Public conversation UUID.
	 * @param string               $user_message    User message text.
	 * @param string               $assistant_reply Assistant reply text.
	 * @param array<string, mixed> $case_profile    Latest case profile snapshot.
	 * @param array<string, mixed> $state           Optional AI intake state snapshot.
	 * @param array<string, mixed> $actions         Optional case actions snapshot.
	 * @return void
	 */
	public function persist_intake_turn(
		string $conversation_id,
		string $user_message,
		string $assistant_reply,
		array $case_profile = array(),
		array $state = array(),
		array $actions = array()
	): void {
		if ( get_current_user_id() <= 0 || '' === trim( $conversation_id ) ) {
			return;
		}

		$session = array(
			'session_id' => $conversation_id,
			'messages'   => array(
				array(
					'role' => 'user',
					'text' => $user_message,
				),
			),
		);

		$db_id = $this->ensure_for_session( $session );

		if ( $db_id <= 0 ) {
			return;
		}

		$user_message      = trim( $user_message );
		$assistant_reply   = trim( $assistant_reply );
		$existing_messages = $this->messages->count_for_conversation( $db_id );

		if ( '' !== $user_message ) {
			$this->messages->append( $db_id, 'user', $user_message, $this->messages->next_sequence( $db_id ) );
		}

		if ( '' !== $assistant_reply ) {
			$this->messages->append( $db_id, 'assistant', $assistant_reply, $this->messages->next_sequence( $db_id ) );
		}

		if ( 0 === $existing_messages && '' !== $user_message ) {
			$title = strlen( $user_message ) > 80 ? substr( $user_message, 0, 77 ) . '…' : $user_message;
			$this->conversations->update_title( $db_id, $title );
		}

		$this->conversations->update_context(
			$db_id,
			array(
				'conversation_id' => $conversation_id,
				'case_profile'      => $case_profile,
				'state'             => $state,
				'actions'           => $actions,
			)
		);

		$this->conversations->touch( $db_id );
	}

	/**
	 * Update stored context snapshot for a session (lifecycle, roadmap, etc.).
	 *
	 * @param string               $session_id   Session UUID.
	 * @param array<string, mixed> $case_profile Case profile snapshot.
	 * @param array<string, mixed> $state        Optional intake state.
	 * @param array<string, mixed> $actions      Optional actions.
	 * @return void
	 */
	public function update_session_context(
		string $session_id,
		array $case_profile = array(),
		array $state = array(),
		array $actions = array()
	): void {
		if ( get_current_user_id() <= 0 || '' === trim( $session_id ) ) {
			return;
		}

		$row = $this->conversations->find_by_session_id( $session_id );

		if ( ! $row ) {
			return;
		}

		$this->conversations->update_context(
			(int) $row->conversation_id,
			array(
				'conversation_id' => $session_id,
				'case_profile'      => $case_profile,
				'state'             => $state,
				'actions'           => $actions,
			)
		);

		$this->conversations->touch( (int) $row->conversation_id );
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
