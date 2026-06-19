<?php
/**
 * Documents REST controller — POST /prose/v1/documents/classify.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Documents\Rest;

use ProSe\Core\Documents\Document_Classifier;
use ProSe\Core\Loader;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Documents_Rest_Controller
 */
final class Documents_Rest_Controller {

	/**
	 * API namespace.
	 */
	public const NAMESPACE = 'prose/v1';

	/**
	 * Classify route.
	 */
	public const ROUTE_CLASSIFY = '/documents/classify';

	/**
	 * Classifier.
	 *
	 * @var Document_Classifier
	 */
	private Document_Classifier $classifier;

	/**
	 * Constructor.
	 *
	 * @param Document_Classifier|null $classifier Classifier.
	 */
	public function __construct( ?Document_Classifier $classifier = null ) {
		$this->classifier = $classifier ?? new Document_Classifier();
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
			self::ROUTE_CLASSIFY,
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_classify' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'filename' => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_file_name',
					),
					'text'     => array(
						'type'              => 'string',
						'required'          => false,
						'sanitize_callback' => 'sanitize_textarea_field',
						'default'           => '',
					),
				),
			)
		);
	}

	/**
	 * Handle POST /documents/classify.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function handle_classify( \WP_REST_Request $request ) {
		$filename = trim( (string) $request->get_param( 'filename' ) );

		if ( '' === $filename ) {
			return new \WP_Error(
				'prose_document_filename_required',
				__( 'Filename is required.', 'prose-core' ),
				array( 'status' => 400 )
			);
		}

		$text   = (string) $request->get_param( 'text' );
		$result = $this->classifier->classify( $filename, $text );

		return rest_ensure_response(
			array(
				'success'      => true,
				'classification' => $result,
			)
		);
	}
}
