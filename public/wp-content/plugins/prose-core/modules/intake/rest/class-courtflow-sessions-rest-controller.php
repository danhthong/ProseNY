<?php
/**
 * CourtFlow workspace REST adapter — maps courtflow/v1 sessions to AI intake.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Intake\Rest;

use ProSe\Core\Ai_Intake\AI_Intake_Service;
use ProSe\Core\Intake\Case_Actions_Resolver;
use ProSe\Core\Loader;
use ProSe\Core\PackageBuilder\Merged_Blank_Pdf_Service;
use ProSe\Core\Security\Rate_Limiter;

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
	 * Constructor.
	 *
	 * @param AI_Intake_Service|null           $service     AI intake service.
	 * @param Courtflow_Session_Store|null     $store       Session store.
	 * @param Courtflow_Response_Mapper|null   $mapper      Response mapper.
	 * @param Merged_Blank_Pdf_Service|null    $merged_pdfs Merged PDF service.
	 * @param Courtflow_Case_Persistence|null  $persistence Case persistence.
	 * @param Case_Actions_Resolver|null       $actions     Case actions resolver.
	 * @param Rate_Limiter|null                $rate_limiter Rate limiter.
	 */
	public function __construct(
		?AI_Intake_Service $service = null,
		?Courtflow_Session_Store $store = null,
		?Courtflow_Response_Mapper $mapper = null,
		?Merged_Blank_Pdf_Service $merged_pdfs = null,
		?Courtflow_Case_Persistence $persistence = null,
		?Case_Actions_Resolver $actions = null,
		?Rate_Limiter $rate_limiter = null
	) {
		$this->service     = $service ?? new AI_Intake_Service();
		$this->store       = $store ?? new Courtflow_Session_Store();
		$this->mapper      = $mapper ?? new Courtflow_Response_Mapper();
		$this->merged_pdfs = $merged_pdfs ?? new Merged_Blank_Pdf_Service();
		$this->persistence = $persistence ?? new Courtflow_Case_Persistence();
		$this->actions     = $actions ?? new Case_Actions_Resolver();
		$this->rate_limiter = $rate_limiter ?? new Rate_Limiter();
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

		$reply = (string) ( $result['question'] ?? '' );

		if ( '' === $reply && isset( $response['message'] ) ) {
			$reply = (string) $response['message'];
		}

		if ( '' !== $reply ) {
			$this->store->append_message( $session, 'assistant', $reply );
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

		return rest_ensure_response(
			$this->mapper->map_message_response( $session, $response, $applied, $text )
		);
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

		$build = $this->merged_pdfs->build( $workflow );

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

		$documents = array(
			array(
				'form_slug'    => $workflow,
				'title'        => __( 'Blank filing package', 'prose-core' ),
				'download_url' => (string) ( $build['download_url'] ?? '' ),
				'status'       => 'ready',
			),
		);

		$session['documents'] = $documents;
		$this->store->save( (string) $session['session_id'], $session );

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
			)
		);
	}

	/**
	 * Same-origin REST access for the account-free MVP workspace.
	 *
	 * @return bool|\WP_Error
	 */
	public function can_access() {
		return $this->rate_limiter->rest_permission(
			$this->rate_limiter->bucket_for_route( 'courtflow_sessions' ),
			90,
			60
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

		$case_id = $this->persistence->persist_intake_complete( $session );

		if ( $case_id <= 0 ) {
			return;
		}

		$session['case_id'] = $case_id;

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
