<?php
/**
 * REST API for CourtFlow JSON output.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Forms;

use ProSe\Core\Loader;
use ProSe\Core\Routing\Workflow_Catalog;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Rest_Controller
 */
final class Rest_Controller {

	/**
	 * API namespace.
	 */
	public const NAMESPACE = 'prose/v1';

	/**
	 * CourtFlow serializer.
	 *
	 * @var Courtflow_Serializer
	 */
	private Courtflow_Serializer $serializer;

	/**
	 * Forms catalog.
	 *
	 * @var Forms_Catalog
	 */
	private Forms_Catalog $catalog;

	/**
	 * Constructor.
	 *
	 * @param Courtflow_Serializer $serializer Serializer.
	 * @param Forms_Catalog|null   $catalog    Forms catalog.
	 */
	public function __construct( Courtflow_Serializer $serializer, ?Forms_Catalog $catalog = null ) {
		$this->serializer = $serializer;
		$this->catalog    = $catalog ?? new Forms_Catalog();
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
			'/forms/search',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'search_forms' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'q'        => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'court'    => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_key',
					),
					'workflow' => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_key',
					),
					'stage'    => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_key',
					),
					'county'   => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_key',
					),
					'issue'    => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_key',
					),
					'limit'    => array(
						'type'              => 'integer',
						'default'           => 25,
						'minimum'           => 1,
						'maximum'           => 100,
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/forms/(?P<id>\d+)/courtflow',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_form_courtflow' ),
				'permission_callback' => array( $this, 'can_read' ),
				'args'                => array(
					'id' => array(
						'validate_callback' => static function ( $param ): bool {
							return is_numeric( $param ) && (int) $param > 0;
						},
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/packages/(?P<id>\d+)',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_package' ),
				'permission_callback' => array( $this, 'can_read' ),
				'args'                => array(
					'id' => array(
						'validate_callback' => static function ( $param ): bool {
							return is_numeric( $param ) && (int) $param > 0;
						},
					),
				),
			)
		);
	}

	/**
	 * GET /forms/search
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function search_forms( \WP_REST_Request $request ): \WP_REST_Response {
		Forms_Catalog::reset_cache();
		Workflow_Catalog::reset_cache();

		$results = $this->catalog->search(
			array(
				'q'        => (string) $request->get_param( 'q' ),
				'court'    => (string) $request->get_param( 'court' ),
				'workflow' => (string) $request->get_param( 'workflow' ),
				'stage'    => (string) $request->get_param( 'stage' ),
				'county'   => (string) $request->get_param( 'county' ),
				'issue'    => (string) $request->get_param( 'issue' ),
			),
			(int) $request->get_param( 'limit' )
		);

		return rest_ensure_response(
			array(
				'query'   => (string) $request->get_param( 'q' ),
				'count'   => count( $results ),
				'results' => $results,
			)
		);
	}

	/**
	 * GET /forms/{id}/courtflow
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_form_courtflow( \WP_REST_Request $request ) {
		$post_id = (int) $request->get_param( 'id' );
		$post    = get_post( $post_id );

		if ( ! $post || Form_CPT::POST_TYPE !== $post->post_type ) {
			return new \WP_Error(
				'prose_form_not_found',
				__( 'Form not found.', 'prose-core' ),
				array( 'status' => 404 )
			);
		}

		$data = $this->serializer->serialize_form( $post_id );

		if ( empty( $data ) ) {
			return new \WP_Error(
				'prose_serialize_failed',
				__( 'Unable to serialize form data.', 'prose-core' ),
				array( 'status' => 500 )
			);
		}

		return rest_ensure_response( $data );
	}

	/**
	 * GET /packages/{id}
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_package( \WP_REST_Request $request ) {
		$post_id = (int) $request->get_param( 'id' );
		$post    = get_post( $post_id );

		if ( ! $post || Package_CPT::POST_TYPE !== $post->post_type ) {
			return new \WP_Error(
				'prose_package_not_found',
				__( 'Package not found.', 'prose-core' ),
				array( 'status' => 404 )
			);
		}

		$data = $this->serializer->serialize_package( $post_id );

		return rest_ensure_response( $data );
	}

	/**
	 * Permission check for read endpoints.
	 *
	 * @return bool
	 */
	public function can_read(): bool {
		return current_user_can( 'edit_posts' );
	}
}
