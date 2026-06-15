<?php
/**
 * Guidance REST controller.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Guidance\Rest;

use ProSe\Core\Guidance\Guidance_Service;
use ProSe\Core\Loader;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Guidance_Rest_Controller
 */
final class Guidance_Rest_Controller {

	/**
	 * API namespace.
	 */
	public const NAMESPACE = 'prose/v1';

	/**
	 * Workflow guidance route.
	 */
	public const ROUTE_WORKFLOW = '/guidance/workflow/(?P<workflow>[a-z0-9_]+)';

	/**
	 * Guidance service.
	 *
	 * @var Guidance_Service
	 */
	private Guidance_Service $service;

	/**
	 * Constructor.
	 *
	 * @param Guidance_Service|null $service Guidance service.
	 */
	public function __construct( ?Guidance_Service $service = null ) {
		$this->service = $service ?? new Guidance_Service();
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
			self::ROUTE_WORKFLOW,
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'handle_workflow' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'workflow' => array(
						'type'     => 'string',
						'required' => true,
					),
					'county'   => array(
						'type'              => 'string',
						'required'          => false,
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);
	}

	/**
	 * Handle GET /guidance/workflow/{workflow}.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function handle_workflow( \WP_REST_Request $request ): \WP_REST_Response {
		$workflow = (string) $request->get_param( 'workflow' );
		$county   = (string) ( $request->get_param( 'county' ) ?? '' );
		$result   = $this->service->get_guidance( $workflow, $county );

		return rest_ensure_response( $result );
	}
}
