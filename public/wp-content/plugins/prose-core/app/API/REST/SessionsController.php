<?php
/**
 * Sessions REST controller.
 *
 * @package ProseCore
 */

namespace Prose\Core\API\REST;

use Prose\Core\Intake\RequirementResolver;
use Prose\Core\Intake\SessionService;
use Prose\Core\Plugin;
use Prose\Core\Validation\Validator;
use Prose\Core\Workflows\WorkflowEngine;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

final class SessionsController extends BaseController {

	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/sessions',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create' ),
					'permission_callback' => array( $this, 'can_intake' ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/sessions/(?P<id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get' ),
					'permission_callback' => array( $this, 'can_intake' ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/sessions/(?P<id>\d+)/state',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'state' ),
					'permission_callback' => array( $this, 'can_intake' ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/sessions/(?P<id>\d+)/requirements',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'requirements' ),
					'permission_callback' => array( $this, 'can_intake' ),
				),
			)
		);
	}

	public function create( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$user_id = $this->current_user_id();
		if ( $user_id < 1 ) {
			return new WP_Error( 'not_logged_in', __( 'You must be logged in to start a case.', 'prose-core' ), array( 'status' => 401 ) );
		}

		try {
			$service = Plugin::container()->get( SessionService::class );
			$result  = $service->create( $user_id, $request->get_param( 'case_type' ) ?? 'divorce' );

			return new WP_REST_Response( $result, 201 );
		} catch ( \Throwable $e ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log( '[CourtFlow] Session create failed: ' . $e->getMessage() );
			}

			return new WP_Error(
				'session_create_failed',
				$e->getMessage(),
				array( 'status' => 500 )
			);
		}
	}

	public function get( WP_REST_Request $request ): WP_REST_Response {
		$service = Plugin::container()->get( SessionService::class );
		$data    = $service->get( (int) $request['id'], $this->current_user_id() );

		if ( ! $data ) {
			return new WP_REST_Response( array( 'error' => 'not_found' ), 404 );
		}

		return new WP_REST_Response( $data, 200 );
	}

	public function state( WP_REST_Request $request ): WP_REST_Response {
		$engine = Plugin::container()->get( WorkflowEngine::class );
		$state  = $engine->get_state( (int) $request['id'] );

		if ( empty( $state ) ) {
			return new WP_REST_Response( array( 'error' => 'not_found' ), 404 );
		}

		$validator    = Plugin::container()->get( Validator::class );
		$requirements = Plugin::container()->get( RequirementResolver::class );

		$validation        = $validator->check( $state['facts'], $state )->to_array();
		$state['validation']   = $validation;
		$state['requirements'] = $requirements->resolve( $state['facts'], $state, $validation );

		return new WP_REST_Response( $state, 200 );
	}

	public function requirements( WP_REST_Request $request ): WP_REST_Response {
		$engine = Plugin::container()->get( WorkflowEngine::class );
		$state  = $engine->get_state( (int) $request['id'] );

		if ( empty( $state ) ) {
			return new WP_REST_Response( array( 'error' => 'not_found' ), 404 );
		}

		$validator    = Plugin::container()->get( Validator::class );
		$requirements = Plugin::container()->get( RequirementResolver::class );

		$validation = $validator->check( $state['facts'], $state )->to_array();
		$report     = $requirements->resolve( $state['facts'], $state, $validation );

		return new WP_REST_Response(
			array(
				'session_id'   => (int) $request['id'],
				'validation'   => $validation,
				'requirements' => $report,
			),
			200
		);
	}
}
