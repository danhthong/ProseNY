<?php
/**
 * Package Builder REST controller.
 *
 * Routes (namespace prose/v1):
 *  - POST /package/manifest  -> manifest only (no disk writes)
 *  - POST /package/build     -> manifest + assets + ZIP (when ready)
 *  - POST /package/preview   -> UI-friendly preview DTO for the chat widget
 *
 * @package ProSeCore
 */

namespace ProSe\Core\PackageBuilder\Rest;

use ProSe\Core\Loader;
use ProSe\Core\PackageBuilder\Merged_Blank_Pdf_Service;
use ProSe\Core\PackageBuilder\Package_Builder;
use ProSe\Core\PackageBuilder\Package_Preview_Service;
use ProSe\Core\PackageBuilder\Package_Type;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Package_Builder_Rest_Controller
 */
final class Package_Builder_Rest_Controller {

	/**
	 * API namespace.
	 */
	public const NAMESPACE = 'prose/v1';

	/**
	 * Manifest route.
	 */
	public const ROUTE_MANIFEST = '/package/manifest';

	/**
	 * Build route.
	 */
	public const ROUTE_BUILD = '/package/build';

	/**
	 * Preview route.
	 */
	public const ROUTE_PREVIEW = '/package/preview';

	/**
	 * Merged blank PDF route.
	 */
	public const ROUTE_MERGED_PDF = '/package/merged-pdf';

	/**
	 * Package builder.
	 *
	 * @var Package_Builder
	 */
	private Package_Builder $builder;

	/**
	 * Preview service.
	 *
	 * @var Package_Preview_Service
	 */
	private Package_Preview_Service $preview;

	/**
	 * Merged blank PDF service.
	 *
	 * @var Merged_Blank_Pdf_Service
	 */
	private Merged_Blank_Pdf_Service $merged;

	/**
	 * Constructor.
	 *
	 * @param Package_Builder|null          $builder Package builder.
	 * @param Package_Preview_Service|null  $preview Preview service.
	 * @param Merged_Blank_Pdf_Service|null $merged  Merged blank PDF service.
	 */
	public function __construct(
		?Package_Builder $builder = null,
		?Package_Preview_Service $preview = null,
		?Merged_Blank_Pdf_Service $merged = null
	) {
		$this->builder = $builder ?? new Package_Builder();
		$this->merged  = $merged ?? new Merged_Blank_Pdf_Service();
		$this->preview = $preview ?? new Package_Preview_Service( $this->builder, null, $this->merged );
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
		$args = $this->route_args();

		register_rest_route(
			self::NAMESPACE,
			self::ROUTE_MANIFEST,
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_manifest' ),
				'permission_callback' => '__return_true',
				'args'                => $args,
			)
		);

		register_rest_route(
			self::NAMESPACE,
			self::ROUTE_BUILD,
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_build' ),
				'permission_callback' => '__return_true',
				'args'                => $args,
			)
		);

		register_rest_route(
			self::NAMESPACE,
			self::ROUTE_PREVIEW,
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_preview' ),
				'permission_callback' => '__return_true',
				'args'                => $args,
			)
		);

		register_rest_route(
			self::NAMESPACE,
			self::ROUTE_MERGED_PDF,
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_merged_pdf' ),
				'permission_callback' => '__return_true',
				'args'                => $args,
			)
		);
	}

	/**
	 * Shared route argument schema.
	 *
	 * @return array<string, mixed>
	 */
	private function route_args(): array {
		return array(
			'conversation_id' => array(
				'type'              => 'string',
				'required'          => false,
				'default'           => '',
				'sanitize_callback' => static function ( $value ): string {
					return sanitize_text_field( (string) $value );
				},
			),
			'workflow'        => array(
				'type'              => 'string',
				'required'          => true,
				'sanitize_callback' => static function ( $value ): string {
					return sanitize_key( (string) $value );
				},
			),
			'facts'           => array(
				'type'     => 'object',
				'required' => false,
				'default'  => array(),
			),
			'package_type'    => array(
				'type'              => 'string',
				'required'          => false,
				'default'           => Package_Type::BLANK,
				'sanitize_callback' => static function ( $value ): string {
					return sanitize_key( (string) $value );
				},
			),
		);
	}

	/**
	 * POST /package/manifest.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function handle_manifest( \WP_REST_Request $request ): \WP_REST_Response {
		return rest_ensure_response( $this->builder->build_manifest( $this->input( $request ) ) );
	}

	/**
	 * POST /package/build.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function handle_build( \WP_REST_Request $request ): \WP_REST_Response {
		return rest_ensure_response( $this->builder->build_package( $this->input( $request ) ) );
	}

	/**
	 * POST /package/preview.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function handle_preview( \WP_REST_Request $request ): \WP_REST_Response {
		return rest_ensure_response( $this->preview->preview( $this->input( $request ) ) );
	}

	/**
	 * POST /package/merged-pdf — build/return a single merged blank-forms PDF.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function handle_merged_pdf( \WP_REST_Request $request ): \WP_REST_Response {
		return rest_ensure_response( $this->merged->build( (string) $request->get_param( 'workflow' ) ) );
	}

	/**
	 * Normalize request params into builder input.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array<string, mixed>
	 */
	private function input( \WP_REST_Request $request ): array {
		$facts = $request->get_param( 'facts' );

		return array(
			'conversation_id' => (string) $request->get_param( 'conversation_id' ),
			'workflow'        => (string) $request->get_param( 'workflow' ),
			'facts'           => is_array( $facts ) ? $facts : array(),
			'package_type'    => (string) $request->get_param( 'package_type' ),
		);
	}
}
