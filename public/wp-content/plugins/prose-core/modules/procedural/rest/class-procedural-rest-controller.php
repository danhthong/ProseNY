<?php
/**
 * Procedural REST controller — POST /prose/v1/procedural/navigate.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Procedural\Rest;

use ProSe\Core\Loader;
use ProSe\Core\Procedural\Procedural_Service;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Procedural_Rest_Controller
 */
final class Procedural_Rest_Controller {

	/**
	 * API namespace.
	 */
	public const NAMESPACE = 'prose/v1';

	/**
	 * Route.
	 */
	public const ROUTE = '/procedural/navigate';

	/**
	 * Procedural service.
	 *
	 * @var Procedural_Service
	 */
	private Procedural_Service $service;

	/**
	 * Constructor.
	 *
	 * @param Procedural_Service|null $service Procedural service.
	 */
	public function __construct( ?Procedural_Service $service = null ) {
		$this->service = $service ?? new Procedural_Service();
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
				'callback'            => array( $this, 'handle_navigate' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'intake' => array(
						'type'     => 'object',
						'required' => true,
					),
				),
			)
		);
	}

	/**
	 * Handle POST /procedural/navigate.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function handle_navigate( \WP_REST_Request $request ): \WP_REST_Response {
		$intake = $request->get_param( 'intake' );

		if ( ! is_array( $intake ) ) {
			$intake = array();
		}

		$result = $this->service->navigate( $intake );

		return rest_ensure_response( $result );
	}
}
