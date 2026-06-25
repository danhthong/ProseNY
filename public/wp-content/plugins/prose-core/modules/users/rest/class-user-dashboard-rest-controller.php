<?php
/**
 * User dashboard REST controller.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Users\Rest;

use ProSe\Core\Forms\Database\Repositories\Case_Repository;
use ProSe\Core\Guidance\Eligibility_Presenter;
use ProSe\Core\Guidance\Procedural_Roadmap_Presenter;
use ProSe\Core\Intake\Case_Lifecycle_Service;
use ProSe\Core\Intake\Rest\Courtflow_Session_Store;
use ProSe\Core\Loader;
use ProSe\Core\Routing\Workflow_Catalog;
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

	public const ROUTE_CONVERSATION_SESSION = '/me/conversations/session/(?P<session_id>[a-f0-9-]+)';

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
	 * @var Workflow_Catalog
	 */
	private Workflow_Catalog $workflows;

	/**
	 * @var Procedural_Roadmap_Presenter
	 */
	private Procedural_Roadmap_Presenter $roadmap_presenter;

	/**
	 * @var Courtflow_Session_Store
	 */
	private Courtflow_Session_Store $session_store;

	/**
	 * @var Case_Lifecycle_Service
	 */
	private Case_Lifecycle_Service $lifecycle_service;

	/**
	 * @var Eligibility_Presenter
	 */
	private Eligibility_Presenter $eligibility;

	/**
	 * Constructor.
	 *
	 * @param Courtflow_Session_Store|null $session_store Optional session store.
	 */
	public function __construct( ?Courtflow_Session_Store $session_store = null ) {
		$this->cases             = new Case_Repository();
		$this->conversations     = new Conversation_Repository();
		$this->messages          = new Message_Repository();
		$this->documents         = new User_Document_Repository();
		$this->subscription      = new Subscription_Status();
		$this->workflows         = new Workflow_Catalog();
		$this->roadmap_presenter = new Procedural_Roadmap_Presenter();
		$this->session_store     = $session_store ?? new Courtflow_Session_Store();
		$this->lifecycle_service = new Case_Lifecycle_Service();
		$this->eligibility       = new Eligibility_Presenter();
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

		register_rest_route(
			self::NAMESPACE,
			self::ROUTE_CONVERSATION_SESSION,
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'handle_conversation_session' ),
				'permission_callback' => array( $this, 'can_access' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			self::ROUTE_CONVERSATION_SESSION,
			array(
				'methods'             => \WP_REST_Server::DELETABLE,
				'callback'            => array( $this, 'handle_delete_conversation_session' ),
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
		$resume_url    = home_url( '/' );

		foreach ( $this->conversations->recent_for_user( $user_id, 20 ) as $row ) {
			$formatted       = $this->format_conversation_row( $row );
			$conversations[] = $formatted;

			if ( ! empty( $formatted['resume_url'] ) && home_url( '/' ) === $resume_url ) {
				$resume_url = (string) $formatted['resume_url'];
			}
		}

		$case_progress = $this->build_case_progress( $conversations, $active_case, $resume_url );
		$case_lifecycle = $this->build_case_lifecycle( $conversations, $case_progress );
		$matter_map     = $case_lifecycle['matter_map'] ?? array( 'show' => false );

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
				'case_progress'        => $case_progress,
				'case_lifecycle'       => $case_lifecycle,
				'matter_map'           => $matter_map,
				'recent_conversations' => $conversations,
				'documents'            => $documents,
				'subscription'         => $this->subscription->for_user( $user_id ),
			)
		);
	}

	/**
	 * Load a saved conversation for resume.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function handle_conversation_session( \WP_REST_Request $request ) {
		$user_id    = get_current_user_id();
		$session_id = sanitize_text_field( (string) $request->get_param( 'session_id' ) );
		$row        = $this->conversations->find_owned_by_session( $user_id, $session_id );

		if ( ! $row ) {
			return new \WP_Error(
				'prose_conversation_not_found',
				__( 'Conversation not found.', 'prose-core' ),
				array( 'status' => 404 )
			);
		}

		$conversation_id = (int) $row->conversation_id;
		$context         = $this->decode_context( (string) ( $row->context_json ?? '' ) );
		$messages        = $this->messages->list_for_conversation( $conversation_id );

		$conversation = array();

		foreach ( $messages as $message ) {
			$conversation[] = array(
				'role'    => 'user' === ( $message['role'] ?? '' ) ? 'user' : 'assistant',
				'content' => (string) ( $message['content'] ?? '' ),
			);
		}

		return rest_ensure_response(
			array(
				'conversation_id' => (string) $row->session_id,
				'title'           => (string) $row->title,
				'case_profile'    => is_array( $context['case_profile'] ?? null ) ? $context['case_profile'] : array(),
				'state'           => is_array( $context['state'] ?? null ) ? $context['state'] : array(),
				'actions'         => is_array( $context['actions'] ?? null ) ? $context['actions'] : array(),
				'conversation'    => $conversation,
				'messages'        => $messages,
			)
		);
	}

	/**
	 * Remove a saved conversation owned by the current user.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function handle_delete_conversation_session( \WP_REST_Request $request ) {
		$user_id    = get_current_user_id();
		$session_id = sanitize_text_field( (string) $request->get_param( 'session_id' ) );
		$row        = $this->conversations->find_owned_by_session( $user_id, $session_id );

		if ( ! $row ) {
			return new \WP_Error(
				'prose_conversation_not_found',
				__( 'Conversation not found.', 'prose-core' ),
				array( 'status' => 404 )
			);
		}

		$conversation_id = (int) $row->conversation_id;

		$this->messages->delete_for_conversation( $conversation_id );

		if ( ! $this->conversations->delete_owned( $user_id, $conversation_id ) ) {
			return new \WP_Error(
				'prose_conversation_delete_failed',
				__( 'Could not remove conversation.', 'prose-core' ),
				array( 'status' => 500 )
			);
		}

		$this->session_store->delete( $session_id );

		return rest_ensure_response(
			array(
				'deleted'    => true,
				'session_id' => $session_id,
			)
		);
	}

	/**
	 * Build compact case progress summary for the dashboard (Option B).
	 *
	 * @param array<int, array<string, mixed>> $conversations Recent conversations.
	 * @param array<string, mixed>|null        $active_case   Active case row.
	 * @param string                           $resume_url    Default resume URL.
	 * @return array<string, mixed>
	 */
	private function build_case_progress( array $conversations, $active_case, string $resume_url ): array {
		$roadmap = null;
		$url     = $resume_url;

		foreach ( $conversations as $conversation ) {
			if ( empty( $conversation['session_id'] ) ) {
				continue;
			}

			$row = $this->conversations->find_owned_by_session(
				get_current_user_id(),
				(string) $conversation['session_id']
			);

			if ( ! $row ) {
				continue;
			}

			$context      = $this->decode_context( (string) ( $row->context_json ?? '' ) );
			$case_profile = is_array( $context['case_profile'] ?? null ) ? $context['case_profile'] : array();
			$candidate    = is_array( $case_profile['roadmap'] ?? null ) ? $case_profile['roadmap'] : null;

			if ( is_array( $candidate ) && ! empty( $candidate['show'] ) ) {
				$roadmap = $candidate;
				$url     = (string) ( $conversation['resume_url'] ?? $resume_url );
				break;
			}
		}

		if ( ! is_array( $roadmap ) ) {
			return array( 'show' => false );
		}

		$summary = $this->roadmap_presenter->to_summary( $roadmap, $url );

		if ( is_array( $active_case ) && isset( $active_case['progress_percentage'] ) ) {
			$summary['progress_percentage'] = (int) $active_case['progress_percentage'];
		}

		return $summary;
	}

	/**
	 * Build lifecycle checklist for dashboard from the most recent divorce conversation.
	 *
	 * @param array<int, array<string, mixed>> $conversations Recent conversations.
	 * @param array<string, mixed>             $case_progress Case progress summary.
	 * @return array<string, mixed>
	 */
	private function build_case_lifecycle( array $conversations, array $case_progress ): array {
		foreach ( $conversations as $conversation ) {
			if ( empty( $conversation['session_id'] ) ) {
				continue;
			}

			$row = $this->conversations->find_owned_by_session(
				get_current_user_id(),
				(string) $conversation['session_id']
			);

			if ( ! $row ) {
				continue;
			}

			$context      = $this->decode_context( (string) ( $row->context_json ?? '' ) );
			$case_profile = is_array( $context['case_profile'] ?? null ) ? $context['case_profile'] : array();
			$facts        = is_array( $case_profile['facts'] ?? null ) ? $case_profile['facts'] : array();
			$actions      = is_array( $context['actions'] ?? null ) ? $context['actions'] : array();
			$lifecycle    = $this->lifecycle_service->build(
				$case_profile,
				array(
					'intake_complete' => ! empty( $actions['intake_complete'] ),
					'completion'      => (int) ( $case_progress['progress_percentage'] ?? 0 ),
				)
			);

			if ( empty( $lifecycle['show'] ) ) {
				continue;
			}

			$eligibility = $this->eligibility->evaluate( $facts );
			$matter_map  = $this->lifecycle_service->build_matter_map( $case_profile );

			return array_merge(
				$lifecycle,
				array(
					'eligibility'       => $eligibility,
					'matter_map'        => $matter_map,
					'continue_case_url' => (string) ( $case_progress['continue_case_url'] ?? $conversation['resume_url'] ?? home_url( '/' ) ),
				)
			);
		}

		return array( 'show' => false );
	}

	/**
	 * @param object $row Conversation row.
	 * @return array<string, mixed>
	 */
	private function format_conversation_row( object $row ): array {
		$conversation_id = (int) $row->conversation_id;
		$session_id      = (string) $row->session_id;
		$context         = $this->decode_context( (string) ( $row->context_json ?? '' ) );
		$case_profile    = is_array( $context['case_profile'] ?? null ) ? $context['case_profile'] : array();
		$workflow_key    = (string) ( $case_profile['workflow'] ?? '' );
		$workflow_label  = $this->workflow_label( $workflow_key );
		$updated_at      = (string) $row->updated_at;

		return array(
			'conversation_id'  => $conversation_id,
			'session_id'       => $session_id,
			'title'            => (string) $row->title,
			'updated_at'       => $updated_at,
			'updated_at_label' => $this->format_datetime( $updated_at ),
			'preview'          => $this->messages->latest_preview( $conversation_id ),
			'message_count'    => $this->messages->count_for_conversation( $conversation_id ),
			'case_id'          => ! empty( $row->case_id ) ? (int) $row->case_id : null,
			'status'           => (string) $row->status,
			'workflow'         => $workflow_key,
			'workflow_label'   => $workflow_label,
			'resume_url'       => $this->resume_url( $session_id ),
		);
	}

	/**
	 * @param string $session_id Session UUID.
	 * @return string
	 */
	private function resume_url( string $session_id ): string {
		if ( '' === $session_id ) {
			return home_url( '/' );
		}

		return add_query_arg( 'conversation_id', rawurlencode( $session_id ), home_url( '/' ) );
	}

	/**
	 * @param string $workflow_key Workflow key.
	 * @return string
	 */
	private function workflow_label( string $workflow_key ): string {
		if ( '' === $workflow_key ) {
			return '';
		}

		$definition = $this->workflows->by_key( $workflow_key );

		if ( ! is_array( $definition ) ) {
			return $workflow_key;
		}

		$description = trim( (string) ( $definition['description'] ?? '' ) );

		return '' !== $description ? $description : $workflow_key;
	}

	/**
	 * @param string $json Stored JSON.
	 * @return array<string, mixed>
	 */
	private function decode_context( string $json ): array {
		if ( '' === $json ) {
			return array();
		}

		$decoded = json_decode( $json, true );

		return is_array( $decoded ) ? $decoded : array();
	}

	/**
	 * @param string $datetime MySQL datetime.
	 * @return string
	 */
	private function format_datetime( string $datetime ): string {
		if ( '' === $datetime || '0000-00-00 00:00:00' === $datetime ) {
			return '';
		}

		$timestamp = strtotime( $datetime );

		if ( false === $timestamp ) {
			return $datetime;
		}

		return (string) wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $timestamp );
	}
}
