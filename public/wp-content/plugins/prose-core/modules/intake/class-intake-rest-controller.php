<?php
/**
 * Intake REST controller — POST /prose/v1/intake.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Intake;

use ProSe\Core\Ai_Intake\Stage_Transition_Guidance_Service;
use ProSe\Core\Loader;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Intake_Rest_Controller
 */
final class Intake_Rest_Controller {

	/**
	 * API namespace.
	 */
	public const NAMESPACE = 'prose/v1';

	/**
	 * Route.
	 */
	public const ROUTE = '/intake';

	/**
	 * Case actions route.
	 */
	public const ROUTE_ACTIONS = '/case/actions';

	/**
	 * Complete current procedural stage after form download.
	 */
	public const ROUTE_COMPLETE_STAGE = '/case/complete-stage';

	/**
	 * Intake agent.
	 *
	 * @var Intake_Agent
	 */
	private Intake_Agent $agent;

	/**
	 * Case actions resolver.
	 *
	 * @var Case_Actions_Resolver
	 */
	private Case_Actions_Resolver $actions;

	/**
	 * Procedural stage completer.
	 *
	 * @var Procedural_Stage_Completer
	 */
	private Procedural_Stage_Completer $stage_completer;

	/**
	 * Stage transition AI guidance.
	 *
	 * @var Stage_Transition_Guidance_Service
	 */
	private Stage_Transition_Guidance_Service $stage_guidance;

	/**
	 * Constructor.
	 *
	 * @param Intake_Agent|null                       $agent           Intake agent.
	 * @param Case_Actions_Resolver|null              $actions         Case actions resolver.
	 * @param Procedural_Stage_Completer|null         $stage_completer Stage completer.
	 * @param Stage_Transition_Guidance_Service|null  $stage_guidance  Stage guidance service.
	 */
	public function __construct(
		?Intake_Agent $agent = null,
		?Case_Actions_Resolver $actions = null,
		?Procedural_Stage_Completer $stage_completer = null,
		?Stage_Transition_Guidance_Service $stage_guidance = null
	) {
		$this->agent           = $agent ?? new Intake_Agent();
		$this->actions         = $actions ?? new Case_Actions_Resolver();
		$this->stage_completer = $stage_completer ?? new Procedural_Stage_Completer();
		$this->stage_guidance  = $stage_guidance ?? new Stage_Transition_Guidance_Service();
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
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_intake' ),
				// Public, account-free intake widget (MVP). State lives client-side.
				'permission_callback' => '__return_true',
				'args'                => array(
					'message'      => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => static function ( $value ): string {
							return sanitize_textarea_field( (string) $value );
						},
					),
					'case_profile' => array(
						'type'     => 'object',
						'required' => false,
						'default'  => array(),
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			self::ROUTE_ACTIONS,
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_actions' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'case_profile' => array(
						'type'     => 'object',
						'required' => true,
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			self::ROUTE_COMPLETE_STAGE,
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_complete_stage' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'case_profile' => array(
						'type'     => 'object',
						'required' => true,
					),
					'conversation_id' => array(
						'type'              => 'string',
						'required'          => false,
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);
	}

	/**
	 * Handle POST /intake.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function handle_intake( \WP_REST_Request $request ): \WP_REST_Response {
		$message      = (string) $request->get_param( 'message' );
		$case_profile = $request->get_param( 'case_profile' );

		if ( ! is_array( $case_profile ) ) {
			$case_profile = array();
		}

		$response = $this->agent->process( $message, $case_profile );
		$response['actions'] = $this->actions->resolve(
			is_array( $response['case_profile'] ?? null ) ? $response['case_profile'] : array(),
			$response
		);

		return rest_ensure_response( $response );
	}

	/**
	 * Handle POST /case/actions — refresh action visibility for a stored session.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function handle_actions( \WP_REST_Request $request ): \WP_REST_Response {
		$case_profile = $request->get_param( 'case_profile' );

		if ( ! is_array( $case_profile ) ) {
			$case_profile = array();
		}

		return rest_ensure_response(
			array(
				'success' => true,
				'actions' => $this->actions->resolve( $case_profile ),
			)
		);
	}

	/**
	 * Handle POST /case/complete-stage — advance when a procedural stage is complete.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function handle_complete_stage( \WP_REST_Request $request ): \WP_REST_Response {
		$case_profile = $request->get_param( 'case_profile' );

		if ( ! is_array( $case_profile ) ) {
			$case_profile = array();
		}

		$result = $this->stage_completer->complete_current_stage(
			$case_profile,
			(string) $request->get_param( 'conversation_id' )
		);

		if ( is_wp_error( $result ) ) {
			return rest_ensure_response(
				array(
					'success' => false,
					'message' => $result->get_error_message(),
				)
			)->set_status( (int) ( $result->get_error_data()['status'] ?? 400 ) );
		}

		$intake_context = $request->get_param( 'intake_context' );

		if ( ! is_array( $intake_context ) ) {
			$intake_context = array();
		}

		$guidance_result = $this->stage_guidance->generate( $result, $intake_context );

		if ( '' !== trim( (string) ( $guidance_result['guidance'] ?? '' ) ) ) {
			$result['transition_ack'] = (string) ( $result['message'] ?? '' );
			$result['ai_guidance']    = (string) $guidance_result['guidance'];
			$result['ai_used']        = ! empty( $guidance_result['ai_used'] );
			$result['message']        = (string) $guidance_result['guidance'];

			if ( ! empty( $guidance_result['checklist'] ) ) {
				$result['stage_checklist'] = $guidance_result['checklist'];
			}
		}

		return rest_ensure_response(
			array_merge(
				array( 'success' => true ),
				$result
			)
		);
	}
}
