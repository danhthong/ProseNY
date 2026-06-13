<?php
/**
 * Intake REST controller — POST /prose/v1/intake.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Intake;

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
	 * Intake agent.
	 *
	 * @var Intake_Agent
	 */
	private Intake_Agent $agent;

	/**
	 * Constructor.
	 *
	 * @param Intake_Agent|null $agent Intake agent.
	 */
	public function __construct( ?Intake_Agent $agent = null ) {
		$this->agent = $agent ?? new Intake_Agent();
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

		return rest_ensure_response( $response );
	}
}
