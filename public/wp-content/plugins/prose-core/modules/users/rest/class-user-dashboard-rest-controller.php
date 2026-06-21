<?php
/**
 * User dashboard REST controller.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Users\Rest;

use ProSe\Core\Forms\Database\Repositories\Case_Repository;
use ProSe\Core\Loader;
use ProSe\Core\Users\Database\Repositories\Conversation_Repository;
use ProSe\Core\Users\Database\Repositories\Message_Repository;
use ProSe\Core\Users\Database\Repositories\User_Document_Repository;
use ProSe\Core\Users\Role_Registrar;
use ProSe\Core\Users\Subscription_Status;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class User_Dashboard_Rest_Controller
 */
final class User_Dashboard_Rest_Controller {

	public const NAMESPACE = 'prose/v1';

	public const ROUTE = '/me/dashboard';

	/**
	 * @var Case_Repository
	 */
	private Case_Repository $cases;

	/**
	 * @var Conversation_Repository
	 */
	private Conversation_Repository $conversations;

	/**
	 * @var Message_Repository
	 */
	private Message_Repository $messages;

	/**
	 * @var User_Document_Repository
	 */
	private User_Document_Repository $documents;

	/**
	 * @var Subscription_Status
	 */
	private Subscription_Status $subscription;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->cases          = new Case_Repository();
		$this->conversations  = new Conversation_Repository();
		$this->messages       = new Message_Repository();
		$this->documents      = new User_Document_Repository();
		$this->subscription   = new Subscription_Status();
	}

	/**
	 * Register hooks.
	 *
	 * @param Loader $loader Hook loader.
	 * @return void
	 */
	public function register( Loader $loader ): void {
		$loader->add_action( 'rest_api_init', $this, 'register_routes' );
	}

	/**
	 * Register REST routes.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			self::ROUTE,
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'handle_dashboard' ),
				'permission_callback' => array( $this, 'can_access' ),
			)
		);
	}

	/**
	 * Permission callback.
	 *
	 * @return bool
	 */
	public function can_access(): bool {
		return is_user_logged_in() && Role_Registrar::can_access_dashboard();
	}

	/**
	 * Build dashboard payload.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function handle_dashboard( \WP_REST_Request $request ): \WP_REST_Response { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		$user    = wp_get_current_user();
		$user_id = (int) $user->ID;

		$active_case = $this->cases->find_active_for_user( $user_id );

		$conversations = array();

		foreach ( $this->conversations->recent_for_user( $user_id, 5 ) as $row ) {
			$conversations[] = array(
				'conversation_id' => (int) $row->conversation_id,
				'title'           => (string) $row->title,
				'updated_at'      => (string) $row->updated_at,
				'preview'         => $this->messages->latest_preview( (int) $row->conversation_id ),
				'case_id'         => ! empty( $row->case_id ) ? (int) $row->case_id : null,
			);
		}

		$documents = array();

		foreach ( $this->documents->recent_for_user( $user_id, 10 ) as $row ) {
			$documents[] = array(
				'document_id'   => (int) $row->document_id,
				'title'         => (string) $row->title,
				'status'        => (string) $row->status,
				'document_type' => (string) $row->document_type,
				'download_url'  => $this->documents->download_url_for_row( $row ),
				'created_at'    => (string) $row->created_at,
			);
		}

		return rest_ensure_response(
			array(
				'user'                 => array(
					'display_name' => (string) $user->display_name,
					'email'        => (string) $user->user_email,
				),
				'active_case'          => $active_case,
				'recent_conversations' => $conversations,
				'documents'            => $documents,
				'subscription'         => $this->subscription->for_user( $user_id ),
			)
		);
	}
}
