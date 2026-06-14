<?php
/**
 * Assembly REST controller — POST /prose/v1/assembly/build.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Assembly\Rest;

use ProSe\Core\Assembly\Assembly_Service;
use ProSe\Core\Loader;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Assembly_Rest_Controller
 */
final class Assembly_Rest_Controller {

	/**
	 * API namespace.
	 */
	public const NAMESPACE = 'prose/v1';

	/**
	 * Route.
	 */
	public const ROUTE = '/assembly/build';

	/**
	 * Assembly service.
	 *
	 * @var Assembly_Service
	 */
	private Assembly_Service $service;

	/**
	 * Constructor.
	 *
	 * @param Assembly_Service|null $service Assembly service.
	 */
	public function __construct( ?Assembly_Service $service = null ) {
		$this->service = $service ?? new Assembly_Service();
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
				'callback'            => array( $this, 'handle_build' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'package_id' => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => static function ( $value ): string {
							return sanitize_text_field( (string) $value );
						},
					),
					'intake'     => array(
						'type'     => 'object',
						'required' => true,
					),
				),
			)
		);
	}

	/**
	 * Handle POST /assembly/build.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function handle_build( \WP_REST_Request $request ): \WP_REST_Response {
		$package_id = (string) $request->get_param( 'package_id' );
		$intake     = $request->get_param( 'intake' );

		if ( ! is_array( $intake ) ) {
			$intake = array();
		}

		$result = $this->service->build( $intake, $package_id );

		return rest_ensure_response( $result );
	}
}
