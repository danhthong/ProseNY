<?php
/**
 * AI Intake REST controller.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Ai_Intake\Rest;

use ProSe\Core\Ai_Intake\AI_Intake_Service;
use ProSe\Core\Loader;

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
	 * Constructor.
	 *
	 * @param AI_Intake_Service|null $service Service.
	 */
	public function __construct( ?AI_Intake_Service $service = null ) {
		$this->service = $service ?? new AI_Intake_Service();
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
				'permission_callback' => '__return_true',
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

		if ( ! empty( $response['success'] ) && isset( $response['result'] ) && is_array( $response['result'] ) ) {
			$result = $response['result'];

			$response['conversation_id'] = $result['conversation_id'] ?? '';
			$response['case_profile']    = $result['case_profile'] ?? array();
			$response['completion']      = $result['completion'] ?? 0;
			$response['next_question']   = $result['question'] ?? '';
			$response['next_action']     = $result['next_action'] ?? '';
			$response['workflow']        = $result['workflow'] ?? null;
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
	 * Admin capability check.
	 *
	 * @return bool
	 */
	public function can_manage(): bool {
		return current_user_can( 'manage_options' );
	}
}
