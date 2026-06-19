<?php
/**
 * Documents REST controller — classify and upload court documents.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Documents\Rest;

use ProSe\Core\Documents\Document_Classifier;
use ProSe\Core\Forms\Pdf\Pdf_Engine_Factory;
use ProSe\Core\Loader;
use ProSe\Core\Security\Rate_Limiter;

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
	 * Classify route (JSON filename + optional text).
	 */
	public const ROUTE_CLASSIFY = '/documents/classify';

	/**
	 * Upload route (multipart PDF).
	 */
	public const ROUTE_UPLOAD = '/documents/upload';

	/**
	 * Maximum upload size in bytes (10 MB).
	 */
	public const MAX_UPLOAD_BYTES = 10485760;

	/**
	 * Classifier.
	 *
	 * @var Document_Classifier
	 */
	private Document_Classifier $classifier;

	/**
	 * Rate limiter.
	 *
	 * @var Rate_Limiter
	 */
	private Rate_Limiter $rate_limiter;

	/**
	 * Constructor.
	 *
	 * @param Document_Classifier|null $classifier   Classifier.
	 * @param Rate_Limiter|null        $rate_limiter Rate limiter.
	 */
	public function __construct( ?Document_Classifier $classifier = null, ?Rate_Limiter $rate_limiter = null ) {
		$this->classifier   = $classifier ?? new Document_Classifier();
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
			self::ROUTE_CLASSIFY,
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_classify' ),
				'permission_callback' => array( $this, 'classify_permission' ),
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

		register_rest_route(
			self::NAMESPACE,
			self::ROUTE_UPLOAD,
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_upload' ),
				'permission_callback' => array( $this, 'upload_permission' ),
			)
		);
	}

	/**
	 * Rate limit for classify requests.
	 *
	 * @return bool|\WP_Error
	 */
	public function classify_permission() {
		return $this->rate_limiter->rest_permission(
			$this->rate_limiter->bucket_for_route( 'prose_documents_classify' ),
			30,
			60
		);
	}

	/**
	 * Rate limit for upload requests.
	 *
	 * @return bool|\WP_Error
	 */
	public function upload_permission() {
		return $this->rate_limiter->rest_permission(
			$this->rate_limiter->bucket_for_route( 'prose_documents_upload' ),
			10,
			60
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
				'success'        => true,
				'classification' => $result,
			)
		);
	}

	/**
	 * Handle POST /documents/upload — extract PDF text, classify, discard file.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function handle_upload( \WP_REST_Request $request ) {
		$files = $request->get_file_params();
		$file  = is_array( $files['document'] ?? null ) ? $files['document'] : null;

		if ( null === $file ) {
			return new \WP_Error(
				'prose_document_file_required',
				__( 'A PDF document is required.', 'prose-core' ),
				array( 'status' => 400 )
			);
		}

		$validation = $this->validate_uploaded_pdf( $file );

		if ( is_wp_error( $validation ) ) {
			return $validation;
		}

		$filename = sanitize_file_name( (string) ( $file['name'] ?? 'document.pdf' ) );
		$tmp_path = (string) $file['tmp_name'];
		$text     = '';

		try {
			$text = Pdf_Engine_Factory::get_engine()->extract_text( $tmp_path, 3 );
		} catch ( \Throwable $e ) {
			$text = '';
		}

		$result = $this->classifier->classify( $filename, $text );

		return rest_ensure_response(
			array(
				'success'        => true,
				'filename'       => $filename,
				'classification' => $result,
				'text_extracted' => '' !== trim( $text ),
			)
		);
	}

	/**
	 * Validate an uploaded PDF before processing.
	 *
	 * @param array<string, mixed> $file $_FILES row.
	 * @return true|\WP_Error
	 */
	private function validate_uploaded_pdf( array $file ) {
		if ( UPLOAD_ERR_OK !== (int) ( $file['error'] ?? UPLOAD_ERR_NO_FILE ) ) {
			return new \WP_Error(
				'prose_document_upload_failed',
				__( 'The upload failed. Please try again.', 'prose-core' ),
				array( 'status' => 400 )
			);
		}

		$size = (int) ( $file['size'] ?? 0 );

		if ( $size <= 0 || $size > self::MAX_UPLOAD_BYTES ) {
			return new \WP_Error(
				'prose_document_too_large',
				__( 'PDF must be 10 MB or smaller.', 'prose-core' ),
				array( 'status' => 400 )
			);
		}

		$filename = sanitize_file_name( (string) ( $file['name'] ?? '' ) );
		$checked  = wp_check_filetype( $filename, array( 'pdf' => 'application/pdf' ) );

		if ( empty( $checked['ext'] ) || 'pdf' !== $checked['ext'] ) {
			return new \WP_Error(
				'prose_document_invalid_type',
				__( 'Only PDF court documents are supported right now.', 'prose-core' ),
				array( 'status' => 400 )
			);
		}

		$tmp_path = (string) ( $file['tmp_name'] ?? '' );

		if ( '' === $tmp_path || ! is_uploaded_file( $tmp_path ) ) {
			return new \WP_Error(
				'prose_document_upload_invalid',
				__( 'Invalid upload.', 'prose-core' ),
				array( 'status' => 400 )
			);
		}

		return true;
	}
}
