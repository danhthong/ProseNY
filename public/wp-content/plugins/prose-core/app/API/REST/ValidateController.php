<?php
/**
 * Validation REST controller.
 *
 * @package ProseCore
 */

namespace Prose\Core\API\REST;

use Prose\Core\Database\Repositories\FactsRepository;
use Prose\Core\Plugin;
use Prose\Core\Validation\Validator;
use Prose\Core\Workflows\WorkflowEngine;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

final class ValidateController extends BaseController {

	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/sessions/(?P<id>\d+)/validate',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'validate' ),
					'permission_callback' => array( $this, 'can_intake' ),
				),
			)
		);
	}

	public function validate( WP_REST_Request $request ): WP_REST_Response {
		$session_id = (int) $request['id'];
		$facts      = Plugin::container()->get( FactsRepository::class )->get( $session_id );
		$state      = Plugin::container()->get( WorkflowEngine::class )->get_state( $session_id );
		$report     = Plugin::container()->get( Validator::class )->check( $facts, $state );

		return new WP_REST_Response( $report->to_array(), 200 );
	}
}
