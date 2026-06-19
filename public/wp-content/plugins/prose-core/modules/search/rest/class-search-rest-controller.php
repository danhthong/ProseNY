<?php
/**
 * Unified search REST endpoint.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Search\Rest;

use ProSe\Core\Loader;
use ProSe\Core\Search\Unified_Search_Service;
use ProSe\Core\Security\Rate_Limiter;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Search_Rest_Controller
 */
final class Search_Rest_Controller {

	/**
	 * API namespace.
	 */
	public const NAMESPACE = 'prose/v1';

	/**
	 * Search service.
	 *
	 * @var Unified_Search_Service
	 */
	private Unified_Search_Service $service;

	/**
	 * Rate limiter.
	 *
	 * @var Rate_Limiter
	 */
	private Rate_Limiter $rate_limiter;

	/**
	 * Constructor.
	 *
	 * @param Unified_Search_Service|null $service      Search service.
	 * @param Rate_Limiter|null           $rate_limiter Rate limiter.
	 */
	public function __construct( ?Unified_Search_Service $service = null, ?Rate_Limiter $rate_limiter = null ) {
		$this->service      = $service ?? new Unified_Search_Service();
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
			'/search',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'handle_search' ),
				'permission_callback' => array( $this, 'can_search' ),
				'args'                => array(
					'q' => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					),
					'limit' => array(
						'type'    => 'integer',
						'default' => 10,
						'minimum' => 1,
						'maximum' => 50,
					),
				),
			)
		);
	}

	/**
	 * Handle GET /search.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function handle_search( \WP_REST_Request $request ): \WP_REST_Response {
		$query = trim( (string) $request->get_param( 'q' ) );
		$limit = (int) $request->get_param( 'limit' );

		return rest_ensure_response( $this->service->search( $query, $limit ) );
	}

	/**
	 * Rate-limited public search access.
	 *
	 * @return bool|\WP_Error
	 */
	public function can_search() {
		return $this->rate_limiter->rest_permission(
			$this->rate_limiter->bucket_for_route( 'prose_search' ),
			120,
			60
		);
	}
}
