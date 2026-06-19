<?php
/**
 * AI Intake REST controller.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Ai_Intake\Rest;

use ProSe\Core\Ai_Intake\AI_Intake_Service;
use ProSe\Core\Intake\Case_Actions_Resolver;
use ProSe\Core\Loader;
use ProSe\Core\Security\Rate_Limiter;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class AI_Intake_Rest_Controller
 */
final class AI_Intake_Rest_Controller {

	/**
	 * API namespace.
	 */
	public const NAMESPACE = 'prose/v1';

	/**
	 * Interpret route.
	 */
	public const ROUTE_INTERPRET = '/intake/interpret';

	/**
	 * Test connection route.
	 */
	public const ROUTE_TEST = '/intake/ai/test-connection';

	/**
	 * Service.
	 *
	 * @var AI_Intake_Service
	 */
	private AI_Intake_Service $service;

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
	 * @param AI_Intake_Service|null      $service Service.
	 * @param Case_Actions_Resolver|null  $actions Case actions resolver.
	 * @param Rate_Limiter|null           $rate_limiter Rate limiter.
	 */
	public function __construct( ?AI_Intake_Service $service = null, ?Case_Actions_Resolver $actions = null, ?Rate_Limiter $rate_limiter = null ) {
		$this->service = $service ?? new AI_Intake_Service();
		$this->actions = $actions ?? new Case_Actions_Resolver();
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
			self::ROUTE_INTERPRET,
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_interpret' ),
				'permission_callback' => array( $this, 'can_interpret' ),
				'args'                => array(
					'message'      => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_textarea_field',
					),
					'state'        => array(
						'type'     => 'object',
						'required' => false,
					),
					'conversation' => array(
						'type'     => 'array',
						'required' => false,
					),
					'case_profile' => array(
						'type'     => 'object',
						'required' => false,
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			self::ROUTE_TEST,
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_test_connection' ),
				'permission_callback' => array( $this, 'can_manage' ),
			)
		);
	}

	/**
	 * Handle POST /intake/interpret.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function handle_interpret( \WP_REST_Request $request ): \WP_REST_Response {
		$message      = (string) $request->get_param( 'message' );
		$state        = $request->get_param( 'state' );
		$conversation = $request->get_param( 'conversation' );
		$case_profile = $request->get_param( 'case_profile' );

		$state = is_array( $state ) ? $state : array();

		if ( is_array( $case_profile ) ) {
			$state['case_profile'] = $case_profile;
		}

		$conversation = is_array( $conversation ) ? $conversation : array();

		$response = $this->service->interpret( $message, $state, $conversation );

		if ( isset( $response['supported'] ) && false === $response['supported'] ) {
			$response['next_question'] = $response['result']['question'] ?? ( $response['message'] ?? '' );
			$response['next_action']   = $response['result']['next_action'] ?? 'domain_restricted';

			return rest_ensure_response( $response );
		}

		if ( ! empty( $response['success'] ) && isset( $response['result'] ) && is_array( $response['result'] ) ) {
			$result = $response['result'];

			$response['conversation_id'] = $result['conversation_id'] ?? '';
			$response['case_profile']    = $result['case_profile'] ?? array();
			$response['completion']      = $result['completion'] ?? 0;
			$response['next_question']   = $result['question'] ?? '';
			$response['next_action']     = $result['next_action'] ?? '';
			$response['workflow']        = $result['workflow'] ?? null;
			$case_profile = is_array( $response['case_profile'] ) ? $response['case_profile'] : array();

			if ( empty( $case_profile['workflow'] ) && ! empty( $result['workflow'] ) ) {
				$case_profile['workflow'] = $result['workflow'];
			}

			$state = is_array( $result['state'] ?? null ) ? $result['state'] : array();

			if ( empty( $case_profile['issue'] ) && ! empty( $state['issue'] ) ) {
				$case_profile['issue'] = $state['issue'];
			}

			$response['actions'] = $this->actions->resolve( $case_profile, $result );
		}

		return rest_ensure_response( $response );
	}

	/**
	 * Handle POST /intake/ai/test-connection.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function handle_test_connection( \WP_REST_Request $request ): \WP_REST_Response {
		unset( $request );

		return rest_ensure_response( $this->service->test_connection() );
	}

	/**
	 * Rate-limited public interpret access.
	 *
	 * @return bool|\WP_Error
	 */
	public function can_interpret() {
		return $this->rate_limiter->rest_permission(
			$this->rate_limiter->bucket_for_route( 'prose_intake_interpret' ),
			60,
			60
		);
	}

	/**
	 * Admin capability check.
	 *
	 * @return bool
	 */
	public function can_manage(): bool {
		return current_user_can( 'manage_options' );
	}
}
