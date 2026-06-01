<?php
/**
 * Admin-only REST endpoints.
 *
 * @package ProseCore
 */

namespace Prose\Core\API\REST;

use Prose\Core\Plugin;
use Prose\Core\Rules\Engine;
use Prose\Core\Rules\Facts;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

final class AdminController extends BaseController {

	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/admin/rules/dry-run',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'dry_run' ),
					'permission_callback' => function () {
						return current_user_can( 'cf_admin_rules' );
					},
				),
			)
		);
	}

	public function dry_run( WP_REST_Request $request ): WP_REST_Response {
		$facts_json = $request->get_json_params()['facts'] ?? array();
		$engine     = Plugin::container()->get( Engine::class );
		$result     = $engine->evaluate( new Facts( $facts_json ) );

		return new WP_REST_Response( $result->to_array(), 200 );
	}
}
