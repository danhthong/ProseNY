<?php
/**
 * Session claim REST controller.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Users\Rest;

use ProSe\Core\Loader;
use ProSe\Core\Users\Session_Claim_Service;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Session_Claim_Rest_Controller
 */
final class Session_Claim_Rest_Controller {

	public const NAMESPACE = 'prose/v1';

	public const ROUTE = '/sessions/(?P<id>[a-f0-9-]{8,64})/claim';

	/**
	 * @var Session_Claim_Service
	 */
	private Session_Claim_Service $claim;

	/**
	 * Constructor.
	 *
	 * @param Session_Claim_Service|null $claim Claim service.
	 */
	public function __construct( ?Session_Claim_Service $claim = null ) {
		$this->claim = $claim ?? new Session_Claim_Service();
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
				'callback'            => array( $this, 'handle_claim' ),
				'permission_callback' => array( $this, 'can_access' ),
			)
		);
	}

	/**
	 * @return bool
	 */
	public function can_access(): bool {
		return is_user_logged_in();
	}

	/**
	 * Claim a guest session for the current user.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function handle_claim( \WP_REST_Request $request ): \WP_REST_Response {
		$session_id = sanitize_text_field( (string) $request->get_param( 'id' ) );
		$result     = $this->claim->claim_for_user( get_current_user_id(), $session_id );

		$status = ! empty( $result['success'] ) ? 200 : 400;

		return new \WP_REST_Response( $result, $status );
	}
}
