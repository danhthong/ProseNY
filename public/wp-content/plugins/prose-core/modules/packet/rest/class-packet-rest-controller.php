<?php
/**
 * Packet REST controller — read-only packet status and metadata.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Packet\Rest;

use ProSe\Core\Loader;
use ProSe\Core\Packet\Packet_Service;
use ProSe\Core\Users\Auth_Gate;
use ProSe\Core\Users\Entitlements;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Packet_Rest_Controller
 */
final class Packet_Rest_Controller {

	/**
	 * API namespace.
	 */
	public const NAMESPACE = 'prose/v1';

	/**
	 * Single packet route.
	 */
	public const ROUTE_PACKET = '/packet/(?P<package_id>[A-Za-z0-9._-]+)';

	/**
	 * List route.
	 */
	public const ROUTE_LIST = '/packets';

	/**
	 * Download route.
	 */
	public const ROUTE_DOWNLOAD = '/packet/download/(?P<package_id>[A-Za-z0-9._-]+)';

	/**
	 * Packet service.
	 *
	 * @var Packet_Service
	 */
	private Packet_Service $service;

	/**
	 * Auth gate.
	 *
	 * @var Auth_Gate
	 */
	private Auth_Gate $auth_gate;

	/**
	 * Entitlements.
	 *
	 * @var Entitlements
	 */
	private Entitlements $entitlements;

	/**
	 * Constructor.
	 *
	 * @param Packet_Service|null $service      Packet service.
	 * @param Auth_Gate|null      $auth_gate    Auth gate.
	 * @param Entitlements|null   $entitlements Entitlements.
	 */
	public function __construct(
		?Packet_Service $service = null,
		?Auth_Gate $auth_gate = null,
		?Entitlements $entitlements = null
	) {
		$this->service      = $service ?? new Packet_Service();
		$this->auth_gate    = $auth_gate ?? new Auth_Gate();
		$this->entitlements = $entitlements ?? new Entitlements();
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
			self::ROUTE_PACKET,
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'handle_status' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'package_id' => array(
						'type'     => 'string',
						'required' => true,
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			self::ROUTE_LIST,
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'handle_list' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			self::NAMESPACE,
			self::ROUTE_DOWNLOAD,
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'handle_download' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'package_id' => array(
						'type'     => 'string',
						'required' => true,
					),
				),
			)
		);
	}

	/**
	 * Handle GET /packet/{package_id}.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function handle_status( \WP_REST_Request $request ): \WP_REST_Response {
		$package_id = (string) $request->get_param( 'package_id' );
		$result     = $this->service->status( $package_id );

		return rest_ensure_response( $result );
	}

	/**
	 * Handle GET /packet/download/{package_id}.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function handle_download( \WP_REST_Request $request ) {
		$auth = $this->auth_gate->require_auth( Auth_Gate::ACTION_DOWNLOAD_PDF );

		if ( is_wp_error( $auth ) ) {
			return $this->auth_gate->rest_response( $auth );
		}

		if ( ! $this->entitlements->can_download_pdf( get_current_user_id(), array( 'source' => 'packet_download' ) ) ) {
			return $this->entitlements->subscription_rest_response( $this->entitlements->subscription_required_error() );
		}

		$package_id = (string) $request->get_param( 'package_id' );
		$result     = $this->service->download( $package_id );

		return rest_ensure_response( $result );
	}

	/**
	 * Handle GET /packets.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function handle_list( \WP_REST_Request $request ): \WP_REST_Response { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		$rows = $this->service->list_packages();

		return rest_ensure_response(
			array(
				'success'  => true,
				'packets'  => $rows,
			)
		);
	}
}
