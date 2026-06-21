<?php
/**
 * Migrates guest transient sessions to the logged-in user.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Users;

use ProSe\Core\Forms\Database\Repositories\Case_Repository;
use ProSe\Core\Intake\Rest\Courtflow_Case_Persistence;
use ProSe\Core\Intake\Rest\Courtflow_Session_Store;
use ProSe\Core\Users\Database\Repositories\Conversation_Repository;
use ProSe\Core\Users\Database\Repositories\Message_Repository;
use ProSe\Core\Users\Database\Repositories\User_Document_Repository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Session_Claim_Service
 */
final class Session_Claim_Service {

	/**
	 * @var Courtflow_Session_Store
	 */
	private Courtflow_Session_Store $store;

	/**
	 * @var Conversation_Repository
	 */
	private Conversation_Repository $conversations;

	/**
	 * @var Message_Repository
	 */
	private Message_Repository $messages;

	/**
	 * @var Case_Repository
	 */
	private Case_Repository $cases;

	/**
	 * @var Courtflow_Case_Persistence
	 */
	private Courtflow_Case_Persistence $persistence;

	/**
	 * @var User_Document_Repository
	 */
	private User_Document_Repository $documents;

	/**
	 * Constructor.
	 *
	 * @param Courtflow_Session_Store|null    $store         Session store.
	 * @param Conversation_Repository|null    $conversations Conversation repository.
	 * @param Message_Repository|null         $messages      Message repository.
	 * @param Case_Repository|null            $cases         Case repository.
	 * @param Courtflow_Case_Persistence|null $persistence   Case persistence.
	 * @param User_Document_Repository|null   $documents     Document repository.
	 */
	public function __construct(
		?Courtflow_Session_Store $store = null,
		?Conversation_Repository $conversations = null,
		?Message_Repository $messages = null,
		?Case_Repository $cases = null,
		?Courtflow_Case_Persistence $persistence = null,
		?User_Document_Repository $documents = null
	) {
		$this->store         = $store ?? new Courtflow_Session_Store();
		$this->conversations = $conversations ?? new Conversation_Repository();
		$this->messages      = $messages ?? new Message_Repository();
		$this->cases         = $cases ?? new Case_Repository();
		$this->persistence   = $persistence ?? new Courtflow_Case_Persistence();
		$this->documents     = $documents ?? new User_Document_Repository();
	}

	/**
	 * Claim a guest session for the logged-in user.
	 *
	 * @param int    $user_id    User ID.
	 * @param string $session_id Session UUID.
	 * @return array{success: bool, conversation_id: int, case_id: int, message: string}
	 */
	public function claim_for_user( int $user_id, string $session_id ): array {
		$result = array(
			'success'          => false,
			'conversation_id'  => 0,
			'case_id'          => 0,
			'message'          => '',
		);

		if ( $user_id <= 0 || '' === $session_id ) {
			$result['message'] = __( 'Invalid session.', 'prose-core' );

			return $result;
		}

		$session = $this->store->get( $session_id );

		if ( null === $session ) {
			$result['message'] = __( 'Session not found or expired.', 'prose-core' );

			return $result;
		}

		$row = $this->conversations->find_by_session_id( $session_id );

		if ( $row && (int) $row->user_id > 0 && (int) $row->user_id !== $user_id ) {
			$result['message'] = __( 'This session belongs to another account.', 'prose-core' );

			return $result;
		}

		if ( ! $row ) {
			$title              = $this->title_from_session( $session );
			$conversation_id    = $this->conversations->create( $user_id, $session_id, $title );
		} else {
			$conversation_id = (int) $row->conversation_id;
			$this->conversations->assign_user( $conversation_id, $user_id );
		}

		$messages = is_array( $session['messages'] ?? null ) ? $session['messages'] : array();
		$this->messages->bulk_insert_from_session( $conversation_id, $messages );

		$case_id = (int) ( $session['case_id'] ?? 0 );

		if ( $case_id <= 0 && ! empty( $session['intake_complete_pending'] ) ) {
			$case_id = $this->persistence->persist_intake_complete( $session );

			if ( $case_id > 0 ) {
				$session['case_id'] = $case_id;
				$this->store->save( $session_id, $session );
			}
		}

		if ( $case_id > 0 ) {
			$this->cases->assign_user( $case_id, $user_id );
			$this->conversations->link_case( $conversation_id, $case_id );
		}

		$this->migrate_session_documents( $user_id, $conversation_id, $case_id, $session );

		$result['success']         = true;
		$result['conversation_id'] = $conversation_id;
		$result['case_id']         = $case_id;
		$result['message']         = __( 'Session linked to your account.', 'prose-core' );

		return $result;
	}

	/**
	 * @param array<string, mixed> $session Session payload.
	 * @return string
	 */
	private function title_from_session( array $session ): string {
		$messages = is_array( $session['messages'] ?? null ) ? $session['messages'] : array();

		foreach ( $messages as $message ) {
			if ( ! is_array( $message ) || 'user' !== ( $message['role'] ?? '' ) ) {
				continue;
			}

			$text = wp_strip_all_tags( (string) ( $message['text'] ?? '' ) );

			if ( '' !== $text ) {
				return strlen( $text ) > 80 ? substr( $text, 0, 77 ) . '…' : $text;
			}
		}

		return __( 'New conversation', 'prose-core' );
	}

	/**
	 * @param int                  $user_id         User ID.
	 * @param int                  $conversation_id Conversation ID.
	 * @param int                  $case_id         Case ID.
	 * @param array<string, mixed> $session         Session payload.
	 * @return void
	 */
	private function migrate_session_documents( int $user_id, int $conversation_id, int $case_id, array $session ): void {
		$docs = is_array( $session['documents'] ?? null ) ? $session['documents'] : array();

		foreach ( $docs as $doc ) {
			if ( ! is_array( $doc ) ) {
				continue;
			}

			$this->documents->create(
				array(
					'user_id'         => $user_id,
					'case_id'         => $case_id,
					'conversation_id' => $conversation_id,
					'document_type'   => 'blank_package',
					'form_code'       => (string) ( $doc['form_slug'] ?? '' ),
					'title'           => (string) ( $doc['title'] ?? __( 'Filing package', 'prose-core' ) ),
					'download_token'  => (string) ( $doc['download_url'] ?? '' ),
					'status'          => (string) ( $doc['status'] ?? 'ready' ),
				)
			);
		}
	}
}
