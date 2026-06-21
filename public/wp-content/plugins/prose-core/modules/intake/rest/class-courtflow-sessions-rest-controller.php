<?php
/**
 * CourtFlow workspace REST adapter — maps courtflow/v1 sessions to AI intake.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Intake\Rest;

use ProSe\Core\Ai_Intake\AI_Intake_Service;
use ProSe\Core\Forms\Database\Repositories\Case_Repository;
use ProSe\Core\Forms\Engine\Case_Catalog;
use ProSe\Core\Forms\Engine\Case_Service;
use ProSe\Core\Forms\Engine\Case_State;
use ProSe\Core\Forms\Engine\Stage_Form_Presenter;
use ProSe\Core\Intake\Case_Actions_Resolver;
use ProSe\Core\Loader;
use ProSe\Core\PackageBuilder\Merged_Blank_Pdf_Service;
use ProSe\Core\Security\Rate_Limiter;
use ProSe\Core\Users\Auth_Gate;
use ProSe\Core\Users\Conversation_Persistence;
use ProSe\Core\Users\Database\Repositories\User_Document_Repository;
use ProSe\Core\Users\Entitlements;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Courtflow_Sessions_Rest_Controller
 */
final class Courtflow_Sessions_Rest_Controller {

	/**
	 * API namespace expected by the workspace theme.
	 */
	public const NAMESPACE = 'courtflow/v1';

	/**
	 * AI intake service.
	 *
	 * @var AI_Intake_Service
	 */
	private AI_Intake_Service $service;

	/**
	 * Session store.
	 *
	 * @var Courtflow_Session_Store
	 */
	private Courtflow_Session_Store $store;

	/**
	 * Response mapper.
	 *
	 * @var Courtflow_Response_Mapper
	 */
	private Courtflow_Response_Mapper $mapper;

	/**
	 * Merged blank PDF service.
	 *
	 * @var Merged_Blank_Pdf_Service
	 */
	private Merged_Blank_Pdf_Service $merged_pdfs;

	/**
	 * Optional case persistence.
	 *
	 * @var Courtflow_Case_Persistence
	 */
	private Courtflow_Case_Persistence $persistence;

	/**
	 * Case actions resolver.
	 *
	 * @var Case_Actions_Resolver
	 */
	private Case_Actions_Resolver $actions;

	/**
	 * Rate limiter.
	 *
	 * @var Rate_Limiter
	 */
	private Rate_Limiter $rate_limiter;

	/**
	 * Auth gate.
	 *
	 * @var Auth_Gate
	 */
	private Auth_Gate $auth_gate;

	/**
	 * Entitlements.
	 *
	 * @var Entitlements
	 */
	private Entitlements $entitlements;

	/**
	 * Conversation persistence.
	 *
	 * @var Conversation_Persistence
	 */
	private Conversation_Persistence $conversation_persistence;

	/**
	 * User document repository.
	 *
	 * @var User_Document_Repository
	 */
	private User_Document_Repository $user_documents;

	/**
	 * Stage form presenter.
	 *
	 * @var Stage_Form_Presenter
	 */
	private Stage_Form_Presenter $stage_presenter;

	/**
	 * Case service.
	 *
	 * @var Case_Service
	 */
	private Case_Service $case_service;

	/**
	 * Constructor.
	 *
	 * @param AI_Intake_Service|null           $service     AI intake service.
	 * @param Courtflow_Session_Store|null     $store       Session store.
	 * @param Courtflow_Response_Mapper|null   $mapper      Response mapper.
	 * @param Merged_Blank_Pdf_Service|null    $merged_pdfs Merged PDF service.
	 * @param Courtflow_Case_Persistence|null  $persistence Case persistence.
	 * @param Case_Actions_Resolver|null       $actions     Case actions resolver.
	 * @param Rate_Limiter|null                $rate_limiter Rate limiter.
	 * @param Auth_Gate|null                   $auth_gate   Auth gate.
	 * @param Entitlements|null                $entitlements Entitlements.
	 * @param Conversation_Persistence|null    $conversation_persistence Conversation persistence.
	 * @param User_Document_Repository|null    $user_documents User documents.
	 */
	public function __construct(
		?AI_Intake_Service $service = null,
		?Courtflow_Session_Store $store = null,
		?Courtflow_Response_Mapper $mapper = null,
		?Merged_Blank_Pdf_Service $merged_pdfs = null,
		?Courtflow_Case_Persistence $persistence = null,
		?Case_Actions_Resolver $actions = null,
		?Rate_Limiter $rate_limiter = null,
		?Auth_Gate $auth_gate = null,
		?Entitlements $entitlements = null,
		?Conversation_Persistence $conversation_persistence = null,
		?User_Document_Repository $user_documents = null
	) {
		$this->service                  = $service ?? new AI_Intake_Service();
		$this->store                    = $store ?? new Courtflow_Session_Store();
		$this->mapper                   = $mapper ?? new Courtflow_Response_Mapper();
		$this->merged_pdfs              = $merged_pdfs ?? new Merged_Blank_Pdf_Service();
		$this->persistence              = $persistence ?? new Courtflow_Case_Persistence();
		$this->actions                  = $actions ?? new Case_Actions_Resolver();
		$this->rate_limiter             = $rate_limiter ?? new Rate_Limiter();
		$this->auth_gate                = $auth_gate ?? new Auth_Gate();
		$this->entitlements             = $entitlements ?? new Entitlements();
		$this->conversation_persistence = $conversation_persistence ?? new Conversation_Persistence();
		$this->user_documents           = $user_documents ?? new User_Document_Repository();
		$this->stage_presenter          = new Stage_Form_Presenter();
		$this->case_service             = new Case_Service( new Case_Repository() );
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
			'/sessions',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'create_session' ),
				'permission_callback' => array( $this, 'can_access' ),
				'args'                => array(
					'case_type' => array(
						'type'              => 'string',
						'required'          => false,
						'sanitize_callback' => 'sanitize_key',
						'default'           => 'general',
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/sessions/(?P<id>[a-f0-9-]{8,64})',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_session' ),
					'permission_callback' => array( $this, 'can_access' ),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/sessions/(?P<id>[a-f0-9-]{8,64})/state',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_state' ),
				'permission_callback' => array( $this, 'can_access' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/sessions/(?P<id>[a-f0-9-]{8,64})/messages',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_messages' ),
					'permission_callback' => array( $this, 'can_access' ),
					'args'                => array(
						'limit' => array(
							'type'    => 'integer',
							'default' => 100,
							'minimum' => 1,
							'maximum' => 200,
						),
					),
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'post_message' ),
					'permission_callback' => array( $this, 'can_access' ),
					'args'                => array(
						'text' => array(
							'type'              => 'string',
							'required'          => true,
							'sanitize_callback' => 'sanitize_textarea_field',
						),
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/sessions/(?P<id>[a-f0-9-]{8,64})/documents',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_documents' ),
					'permission_callback' => array( $this, 'can_access' ),
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'post_documents' ),
					'permission_callback' => array( $this, 'can_access' ),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/sessions/(?P<id>[a-f0-9-]{8,64})/stages/complete',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'complete_stage' ),
				'permission_callback' => array( $this, 'can_access' ),
				'args'                => array(
					'stage' => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_key',
					),
					'event' => array(
						'type'              => 'string',
						'required'          => false,
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);
	}

	/**
	 * Create a workspace session.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function create_session( \WP_REST_Request $request ): \WP_REST_Response {
		$session = $this->store->create(
			array(
				'case_type' => (string) $request->get_param( 'case_type' ),
			)
		);

		$this->conversation_persistence->ensure_for_session( $session );

		return rest_ensure_response(
			array(
				'session_id' => $session['session_id'],
				'created_at' => $session['created_at'],
			)
		);
	}

	/**
	 * Read session metadata.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_session( \WP_REST_Request $request ) {
		$session = $this->load_session( (string) $request->get_param( 'id' ) );

		if ( is_wp_error( $session ) ) {
			return $session;
		}

		return rest_ensure_response(
			array(
				'session_id'      => $session['session_id'],
				'created_at'      => $session['created_at'],
				'updated_at'      => $session['updated_at'],
				'conversation_id' => $session['conversation_id'] ?? '',
			)
		);
	}

	/**
	 * Read workspace state for the context panel.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_state( \WP_REST_Request $request ) {
		$session = $this->load_session( (string) $request->get_param( 'id' ) );

		if ( is_wp_error( $session ) ) {
			return $session;
		}

		return rest_ensure_response( $this->mapper->map_session_state( $session ) );
	}

	/**
	 * Read stored chat messages.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_messages( \WP_REST_Request $request ) {
		$session = $this->load_session( (string) $request->get_param( 'id' ) );

		if ( is_wp_error( $session ) ) {
			return $session;
		}

		$messages = is_array( $session['messages'] ?? null ) ? $session['messages'] : array();
		$limit    = (int) $request->get_param( 'limit' );

		if ( $limit > 0 && count( $messages ) > $limit ) {
			$messages = array_slice( $messages, -1 * $limit );
		}

		return rest_ensure_response(
			array(
				'messages' => array_values( $messages ),
			)
		);
	}

	/**
	 * Send a chat message through the AI intake interpreter.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function post_message( \WP_REST_Request $request ) {
		$session = $this->load_session( (string) $request->get_param( 'id' ) );

		if ( is_wp_error( $session ) ) {
			return $session;
		}

		$text = trim( (string) $request->get_param( 'text' ) );

		if ( '' === $text ) {
			return new \WP_Error(
				'courtflow_empty_message',
				__( 'Message text is required.', 'prose-core' ),
				array( 'status' => 400 )
			);
		}

		$conversation = is_array( $session['conversation'] ?? null ) ? $session['conversation'] : array();
		$state        = $this->build_interpret_state( $session );

		$response = $this->service->interpret( $text, $state, $conversation );
		$result   = is_array( $response['result'] ?? null ) ? $response['result'] : array();
		$applied  = is_array( $result['fact_updates'] ?? null ) ? $result['fact_updates'] : array();

		$this->store->append_message( $session, 'user', $text );
		$this->conversation_persistence->append_message( $session, 'user', $text );

		$reply = (string) ( $result['question'] ?? '' );

		if ( '' === $reply && isset( $response['message'] ) ) {
			$reply = (string) $response['message'];
		}

		if ( '' !== $reply ) {
			$this->store->append_message( $session, 'assistant', $reply );
			$this->conversation_persistence->append_message( $session, 'assistant', $reply );
		}

		$session['conversation_id'] = (string) ( $result['conversation_id'] ?? $session['conversation_id'] ?? '' );
		$session['case_profile']    = is_array( $result['case_profile'] ?? null ) ? $result['case_profile'] : ( $session['case_profile'] ?? array() );
		$session['intake_state']    = is_array( $result['state'] ?? null ) ? $result['state'] : ( $session['intake_state'] ?? array() );
		$session['conversation'][]  = array(
			'role'    => 'user',
			'content' => $text,
		);

		if ( '' !== $reply ) {
			$session['conversation'][] = array(
				'role'    => 'assistant',
				'content' => $reply,
			);
		}

		$session['last_interpret'] = $result;

		$this->maybe_persist_intake_complete( $session, $result );

		$this->store->save( (string) $session['session_id'], $session );

		$response_payload = $this->mapper->map_message_response( $session, $response, $applied, $text );

		if ( ! empty( $session['intake_complete_pending'] ) ) {
			$response_payload['auth_required'] = true;
			$response_payload['login_url']     = \ProSe\Core\Users\Page_Installer::url( 'login' );
			$response_payload['register_url']  = \ProSe\Core\Users\Page_Installer::url( 'register' );
		}

		return rest_ensure_response( $response_payload );
	}

	/**
	 * List generated / available documents for the session.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_documents( \WP_REST_Request $request ) {
		$session = $this->load_session( (string) $request->get_param( 'id' ) );

		if ( is_wp_error( $session ) ) {
			return $session;
		}

		$context = $this->mapper->map_session_state( $session );

		return rest_ensure_response(
			array(
				'documents'      => is_array( $session['documents'] ?? null ) ? $session['documents'] : array(),
				'required_forms' => $context['required_forms'] ?? array(),
			)
		);
	}

	/**
	 * Generate (or reuse) the blank filing package when intake is complete.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function post_documents( \WP_REST_Request $request ) {
		$auth = $this->auth_gate->require_auth( Auth_Gate::ACTION_GENERATE_PDF );

		if ( is_wp_error( $auth ) ) {
			return $this->auth_gate->rest_response( $auth );
		}

		if ( ! $this->entitlements->can_generate_pdf( get_current_user_id(), array( 'source' => 'courtflow_session' ) ) ) {
			return $this->entitlements->subscription_rest_response( $this->entitlements->subscription_required_error() );
		}

		$session = $this->load_session( (string) $request->get_param( 'id' ) );

		if ( is_wp_error( $session ) ) {
			return $session;
		}

		$context      = $this->mapper->map_session_state( $session );
		$requirements = is_array( $context['requirements'] ?? null ) ? $context['requirements'] : array();

		if ( empty( $requirements['ready_to_generate'] ) ) {
			return new \WP_REST_Response(
				$this->mapper->map_generation_blocked( $session ),
				422
			);
		}

		$workflow = trim( (string) ( $context['facts']['case']['workflow'] ?? $session['case_profile']['workflow'] ?? '' ) );

		if ( '' === $workflow ) {
			return new \WP_REST_Response(
				array(
					'message' => __( 'A resolved workflow is required before generating documents.', 'prose-core' ),
				),
				422
			);
		}

		$stage_context = is_array( $context['stage_context'] ?? null ) ? $context['stage_context'] : array();
		$current_stage = is_array( $stage_context['current_stage'] ?? null )
			? (string) ( $stage_context['current_stage']['id'] ?? '' )
			: null;

		if ( empty( $stage_context['forms_visible'] ) ) {
			return new \WP_REST_Response(
				array_merge(
					$this->mapper->map_generation_blocked( $session ),
					array(
						'message' => __( 'Complete intake and reach the current procedural stage before generating documents.', 'prose-core' ),
					)
				),
				422
			);
		}

		$build = $this->merged_pdfs->build(
			$workflow,
			false,
			$current_stage,
			is_array( $context['facts'] ?? null ) ? (array) $context['facts'] : array()
		);

		if ( empty( $build['success'] ) ) {
			$message = (string) ( $build['error']['message'] ?? __( 'Could not generate the filing package.', 'prose-core' ) );

			return new \WP_REST_Response(
				array_merge(
					$this->mapper->map_generation_blocked( $session ),
					array(
						'message' => $message,
						'missing' => is_array( $build['missing'] ?? null ) ? $build['missing'] : array(),
					)
				),
				422
			);
		}

		$stage_title = is_array( $stage_context['current_stage'] ?? null )
			? trim( (string) ( $stage_context['current_stage']['title'] ?? $current_stage ) )
			: '';
		$doc_title   = '' !== $stage_title
			? sprintf(
				/* translators: %s: procedural stage title */
				__( 'Blank forms — %s', 'prose-core' ),
				$stage_title
			)
			: __( 'Blank filing package', 'prose-core' );

		$documents = array(
			array(
				'form_slug'    => $workflow,
				'title'        => $doc_title,
				'download_url' => (string) ( $build['download_url'] ?? '' ),
				'status'       => 'ready',
				'stage'        => (string) ( $build['stage'] ?? $current_stage ?? '' ),
				'form_codes'   => is_array( $build['form_codes'] ?? null ) ? $build['form_codes'] : array(),
			),
		);

		$session['documents'] = $documents;
		$this->store->save( (string) $session['session_id'], $session );

		$user_id = get_current_user_id();
		$case_id = (int) ( $session['case_id'] ?? 0 );

		foreach ( $documents as $doc ) {
			$this->user_documents->create(
				array(
					'user_id'        => $user_id,
					'case_id'        => $case_id,
					'document_type'  => 'blank_package',
					'form_code'      => (string) ( $doc['form_slug'] ?? '' ),
					'title'          => (string) ( $doc['title'] ?? '' ),
					'download_token' => (string) ( $doc['download_url'] ?? '' ),
					'status'         => (string) ( $doc['status'] ?? 'ready' ),
				)
			);
		}

		do_action(
			'prose_package_downloaded',
			(string) $session['session_id'],
			array(
				'workflow' => $workflow,
				'case_id'  => (int) ( $session['case_id'] ?? 0 ),
			)
		);

		return rest_ensure_response(
			array(
				'message'   => __( 'Your blank filing package is ready to download.', 'prose-core' ),
				'documents' => $documents,
				'forms'     => $context['required_forms'] ?? array(),
				'stage_context' => $context['stage_context'] ?? array(),
			)
		);
	}

	/**
	 * Mark the current procedural stage complete and advance the case.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function complete_stage( \WP_REST_Request $request ) {
		$session_id = sanitize_text_field( (string) $request->get_param( 'id' ) );
		$session    = $this->load_session( $session_id );

		if ( is_wp_error( $session ) ) {
			return $session;
		}

		$stage = sanitize_key( (string) $request->get_param( 'stage' ) );

		if ( '' === $stage ) {
			return new \WP_Error(
				'courtflow_stage_required',
				__( 'A stage identifier is required.', 'prose-core' ),
				array( 'status' => 400 )
			);
		}

		$context = $this->mapper->map_session_state( $session );
		$actions = is_array( $context['actions'] ?? null ) ? $context['actions'] : array();

		if ( empty( $actions['intake_complete'] ) ) {
			return new \WP_REST_Response(
				array(
					'message' => __( 'Complete intake before advancing procedural stages.', 'prose-core' ),
				),
				422
			);
		}

		$workflow = trim( (string) ( $actions['workflow'] ?? $context['facts']['case']['workflow'] ?? '' ) );

		if ( '' === $workflow ) {
			return new \WP_REST_Response(
				array(
					'message' => __( 'A resolved workflow is required.', 'prose-core' ),
				),
				422
			);
		}

		$facts         = is_array( $context['facts']['case'] ?? null ) ? $context['facts']['case'] : array();
		$current_node  = trim( (string) ( $session['procedural_node'] ?? '' ) );
		$case_id       = (int) ( $session['case_id'] ?? 0 );
		$trigger       = $this->stage_presenter->completion_trigger( $workflow, $stage, $facts );
		$event         = sanitize_text_field( (string) $request->get_param( 'event' ) );

		if ( $case_id > 0 ) {
			$case_state = $this->case_service->get_case( $case_id );

			if ( $case_state instanceof Case_State ) {
				$current_node = $case_state->current_node();

				if ( null !== $trigger && Case_Catalog::COND_PACKAGE === $trigger['kind'] ) {
					$case_state = $this->case_service->complete_package( $case_state, $trigger['value'] );
				} elseif ( '' !== $event || ( null !== $trigger && Case_Catalog::COND_EVENT === $trigger['kind'] ) ) {
					$event_type = '' !== $event ? $event : $trigger['value'];
					$case_state = $this->case_service->record_event( $case_state, $event_type );
				}

				$advanced = $this->stage_presenter->advance_after_stage( $workflow, $current_node, $stage, $facts );

				if ( $advanced !== $current_node ) {
					$case_state->set_current_node( $advanced );
					( new Case_Repository() )->save_state( $case_state );
				}

				$current_node                 = $case_state->current_node();
				$session['case_current_node'] = $current_node;
			}
		} else {
			if ( '' === $current_node ) {
				$current_node = trim( (string) ( $context['stage_context']['procedural_node'] ?? '' ) );
			}

			$current_node = $this->stage_presenter->advance_after_stage( $workflow, $current_node, $stage, $facts );
		}

		$session['procedural_node'] = $current_node;
		$this->store->save( $session_id, $session );

		$updated = $this->mapper->map_session_state( $session );

		return rest_ensure_response(
			array(
				'message'       => __( 'Stage marked complete. Here are the forms for your next step.', 'prose-core' ),
				'stage_context' => $updated['stage_context'] ?? array(),
				'current_node'  => $updated['current_node'] ?? array(),
				'next_steps'    => $updated['next_steps'] ?? array(),
			)
		);
	}

	/**
	 * Same-origin REST access for the account-free MVP workspace.
	 *
	 * @return bool|\WP_Error
	 */
	public function can_access( \WP_REST_Request $request ) {
		$allowed = $this->rate_limiter->rest_permission(
			$this->rate_limiter->bucket_for_route( 'courtflow_sessions' ),
			90,
			60
		);

		if ( true !== $allowed ) {
			return $allowed;
		}

		$session_id = sanitize_text_field( (string) $request->get_param( 'id' ) );

		if ( '' === $session_id ) {
			return true;
		}

		$owns = $this->conversation_persistence->user_owns_session( $session_id );

		if ( null === $owns || true === $owns ) {
			return true;
		}

		return new \WP_Error(
			'prose_forbidden',
			__( 'You do not have access to this session.', 'prose-core' ),
			array( 'status' => 403 )
		);
	}

	/**
	 * @param string $session_id Session id.
	 * @return array<string, mixed>|\WP_Error
	 */
	private function load_session( string $session_id ) {
		$session = $this->store->get( $session_id );

		if ( null === $session ) {
			return new \WP_Error(
				'courtflow_session_not_found',
				__( 'Session not found or expired.', 'prose-core' ),
				array( 'status' => 404 )
			);
		}

		return $session;
	}

	/**
	 * Build the state payload expected by AI_Intake_Service::interpret().
	 *
	 * @param array<string, mixed> $session Stored session.
	 * @return array<string, mixed>
	 */
	private function build_interpret_state( array $session ): array {
		$state = is_array( $session['intake_state'] ?? null ) ? $session['intake_state'] : array();

		if ( is_array( $session['case_profile'] ?? null ) ) {
			$state['case_profile'] = $session['case_profile'];
		}

		if ( ! empty( $session['conversation_id'] ) ) {
			$state['conversation_id'] = (string) $session['conversation_id'];
		}

		return $state;
	}

	/**
	 * Persist completed intake to prose_cases when enabled.
	 *
	 * @param array<string, mixed> $session Stored session (mutated).
	 * @param array<string, mixed> $result  Interpreter result.
	 * @return void
	 */
	private function maybe_persist_intake_complete( array &$session, array $result ): void {
		$case_profile = is_array( $session['case_profile'] ?? null ) ? $session['case_profile'] : array();
		$actions      = $this->actions->resolve( $case_profile, $result );

		if ( empty( $actions['intake_complete'] ) ) {
			return;
		}

		if ( ! empty( $session['case_id'] ) ) {
			return;
		}

		$auth = $this->auth_gate->require_auth( Auth_Gate::ACTION_PERSIST_CASE );

		if ( is_wp_error( $auth ) ) {
			$session['intake_complete_pending'] = true;
			return;
		}

		$case_id = $this->persistence->persist_intake_complete( $session );

		if ( $case_id <= 0 ) {
			return;
		}

		$session['case_id'] = $case_id;
		unset( $session['intake_complete_pending'] );

		$this->conversation_persistence->link_case( (string) $session['session_id'], $case_id );

		do_action(
			'prose_intake_complete',
			(string) $session['session_id'],
			array(
				'case_id'  => $case_id,
				'workflow' => (string) ( $result['workflow'] ?? $case_profile['workflow'] ?? '' ),
			)
		);
	}
}
