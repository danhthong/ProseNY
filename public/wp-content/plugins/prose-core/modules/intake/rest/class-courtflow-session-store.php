<?php
/**
 * Transient-backed session store for the CourtFlow workspace adapter.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Intake\Rest;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Courtflow_Session_Store
 */
final class Courtflow_Session_Store {

	/**
	 * Transient key prefix.
	 */
	private const TRANSIENT_PREFIX = 'prose_courtflow_session_';

	/**
	 * Session lifetime (30 days).
	 */
	private const TTL = 2592000;

	/**
	 * Create a new workspace session.
	 *
	 * @param array<string, mixed> $meta Optional metadata (e.g. case_type).
	 * @return array<string, mixed> Session payload including session_id.
	 */
	public function create( array $meta = array() ): array {
		$session_id = wp_generate_uuid4();
		$now        = gmdate( 'c' );

		$session = array(
			'session_id'      => $session_id,
			'created_at'      => $now,
			'updated_at'      => $now,
			'case_type'       => \sanitize_key( (string) ( $meta['case_type'] ?? 'general' ) ),
			'conversation_id' => '',
			'conversation'    => array(),
			'intake_state'    => array(),
			'case_profile'    => array(
				'facts' => array(),
			),
			'actions'         => array(),
			'messages'        => array(),
			'documents'       => array(),
			'message_seq'     => 0,
		);

		$this->save( $session_id, $session );

		return $session;
	}

	/**
	 * Load a session by id.
	 *
	 * @param string $session_id Session id.
	 * @return array<string, mixed>|null
	 */
	public function get( string $session_id ): ?array {
		$session_id = $this->sanitize_id( $session_id );

		if ( '' === $session_id ) {
			return null;
		}

		$stored = get_transient( self::TRANSIENT_PREFIX . $session_id );

		return is_array( $stored ) ? $stored : null;
	}

	/**
	 * Persist a session.
	 *
	 * @param string               $session_id Session id.
	 * @param array<string, mixed> $session    Session payload.
	 * @return bool
	 */
	public function save( string $session_id, array $session ): bool {
		$session_id = $this->sanitize_id( $session_id );

		if ( '' === $session_id ) {
			return false;
		}

		$session['session_id'] = $session_id;
		$session['updated_at'] = gmdate( 'c' );

		return set_transient( self::TRANSIENT_PREFIX . $session_id, $session, self::TTL );
	}

	/**
	 * Append a chat message to the session log.
	 *
	 * @param array<string, mixed> $session Session (mutated in place).
	 * @param string               $role    user|assistant|system.
	 * @param string               $text    Message text.
	 * @return array<string, mixed> Stored message row.
	 */
	public function append_message( array &$session, string $role, string $text ): array {
		$seq = (int) ( $session['message_seq'] ?? 0 ) + 1;

		$row = array(
			'id'         => $seq,
			'role'       => $role,
			'text'       => $text,
			'created_at' => gmdate( 'c' ),
		);

		if ( ! isset( $session['messages'] ) || ! is_array( $session['messages'] ) ) {
			$session['messages'] = array();
		}

		$session['messages'][] = $row;
		$session['message_seq']  = $seq;

		return $row;
	}

	/**
	 * Sanitize a session id from route params.
	 *
	 * @param string $session_id Raw id.
	 * @return string
	 */
	public function sanitize_id( string $session_id ): string {
		$session_id = strtolower( trim( $session_id ) );

		if ( ! preg_match( '/^[a-f0-9-]{8,64}$/', $session_id ) ) {
			return '';
		}

		return $session_id;
	}
}
