<?php
/**
 * Case timeline REST controller — GET/POST /prose/v1/case/timeline.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Intake\Rest;

use ProSe\Core\Intake\Case_Timeline_Presenter;
use ProSe\Core\Loader;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Case_Timeline_Rest_Controller
 */
final class Case_Timeline_Rest_Controller {

	/**
	 * API namespace.
	 */
	public const NAMESPACE = 'prose/v1';

	/**
	 * Route.
	 */
	public const ROUTE = '/case/timeline';

	/**
	 * Session store.
	 *
	 * @var Courtflow_Session_Store
	 */
	private Courtflow_Session_Store $store;

	/**
	 * Timeline presenter.
	 *
	 * @var Case_Timeline_Presenter
	 */
	private Case_Timeline_Presenter $presenter;

	/**
	 * Constructor.
	 *
	 * @param Courtflow_Session_Store|null $store     Session store.
	 * @param Case_Timeline_Presenter|null $presenter Timeline presenter.
	 */
	public function __construct(
		?Courtflow_Session_Store $store = null,
		?Case_Timeline_Presenter $presenter = null
	) {
		$this->store     = $store ?? new Courtflow_Session_Store();
		$this->presenter = $presenter ?? new Case_Timeline_Presenter();
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
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'handle_request' ),
					'permission_callback' => '__return_true',
					'args'                => array(
						'session_id' => array(
							'type'              => 'string',
							'required'          => false,
							'sanitize_callback' => 'sanitize_text_field',
						),
					),
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'handle_request' ),
					'permission_callback' => '__return_true',
					'args'                => array(
						'session_id'   => array(
							'type'              => 'string',
							'required'          => false,
							'sanitize_callback' => 'sanitize_text_field',
						),
						'case_profile' => array(
							'type'     => 'object',
							'required' => false,
						),
						'facts'        => array(
							'type'     => 'object',
							'required' => false,
						),
						'workflow'     => array(
							'type'              => 'string',
							'required'          => false,
							'sanitize_callback' => 'sanitize_key',
						),
						'events'       => array(
							'type'     => 'array',
							'required' => false,
						),
					),
				),
			)
		);
	}

	/**
	 * Handle timeline request from session id or inline case facts.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function handle_request( \WP_REST_Request $request ) {
		$session_id = trim( (string) $request->get_param( 'session_id' ) );

		if ( '' !== $session_id ) {
			$session = $this->store->get( $session_id );

			if ( null === $session ) {
				return new \WP_Error(
					'prose_timeline_session_not_found',
					__( 'Session not found.', 'prose-core' ),
					array( 'status' => 404 )
				);
			}

			return rest_ensure_response(
				array(
					'success'  => true,
					'session_id' => $session_id,
					'timeline' => $this->presenter->from_session( $session ),
				)
			);
		}

		$case_profile = $request->get_param( 'case_profile' );

		if ( ! is_array( $case_profile ) ) {
			$case_profile = array();
		}

		$facts = $request->get_param( 'facts' );

		if ( is_array( $facts ) && ! empty( $facts ) ) {
			$case_profile['facts'] = $facts;
		}

		$workflow = trim( (string) $request->get_param( 'workflow' ) );

		if ( '' !== $workflow ) {
			$case_profile['workflow'] = $workflow;
		}

		if ( empty( $case_profile['workflow'] ) ) {
			return new \WP_Error(
				'prose_timeline_missing_workflow',
				__( 'Provide session_id or case_profile with a resolved workflow.', 'prose-core' ),
				array( 'status' => 400 )
			);
		}

		$events = $request->get_param( 'events' );

		return rest_ensure_response(
			array(
				'success'  => true,
				'timeline' => $this->presenter->from_case_profile(
					$case_profile,
					is_array( $events ) ? $events : array()
				),
			)
		);
	}
}
