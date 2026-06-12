<?php
/**
 * CourtFlow Layout Studio — visual calibration admin tool.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Forms\Documents\Overlay;

use ProSe\Core\Loader;
use ProSe\Core\Forms\Documents\Pdf\Pdf_Storage_Service;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Layout_Studio
 *
 * Provides the "CourtFlow Layout Studio" admin screen plus its REST endpoints
 * for visually calibrating overlay field positions: open a PDF template, drag
 * field markers, resize multiline areas, save coordinates back to the layout
 * JSON, and regenerate a live filled preview.
 *
 * Calibration tooling only — it reads/writes layout JSON and reuses the public
 * rasterizer/renderer. It does not modify the renderer or document generation.
 */
final class Layout_Studio {

	private const PAGE_SLUG   = 'prose-layout-studio';
	private const CAPABILITY  = 'manage_options';
	private const REST_NS     = 'prose/v1';
	private const PREVIEW_DPI = 150;

	/**
	 * Layout repository.
	 *
	 * @var Layout_Repository
	 */
	private Layout_Repository $repository;

	/**
	 * Layout registry.
	 *
	 * @var Form_Layout_Registry
	 */
	private Form_Layout_Registry $registry;

	/**
	 * Constructor.
	 *
	 * @param Layout_Repository|null    $repository Layout repository.
	 * @param Form_Layout_Registry|null $registry   Layout registry.
	 */
	public function __construct( ?Layout_Repository $repository = null, ?Form_Layout_Registry $registry = null ) {
		$this->repository = $repository ?? new Layout_Repository();
		$this->registry   = $registry ?? new Form_Layout_Registry();
	}

	/**
	 * Register admin and REST hooks.
	 *
	 * @param Loader $loader Hook loader.
	 * @return void
	 */
	public function register( Loader $loader ): void {
		$loader->add_action( 'admin_menu', $this, 'register_menu', 11 );
		$loader->add_action( 'admin_enqueue_scripts', $this, 'enqueue_assets' );
		$loader->add_action( 'rest_api_init', $this, 'register_routes' );
	}

	/**
	 * Register the Layout Studio submenu.
	 *
	 * @return void
	 */
	public function register_menu(): void {
		add_submenu_page(
			'prose',
			__( 'Layout Studio', 'prose-core' ),
			__( 'Layout Studio', 'prose-core' ),
			self::CAPABILITY,
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Enqueue Studio assets on its screen only.
	 *
	 * @param string $hook_suffix Current admin hook suffix.
	 * @return void
	 */
	public function enqueue_assets( string $hook_suffix ): void {
		if ( 'prose_page_' . self::PAGE_SLUG !== $hook_suffix ) {
			return;
		}

		wp_enqueue_style(
			'prose-layout-studio',
			PROSE_CORE_URL . 'assets/css/layout-studio.css',
			array(),
			PROSE_CORE_VERSION
		);

		wp_enqueue_script(
			'prose-layout-studio',
			PROSE_CORE_URL . 'assets/js/layout-studio.js',
			array(),
			PROSE_CORE_VERSION,
			true
		);

		$codes   = $this->repository->codes();
		$initial = isset( $_GET['form'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['form'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( '' === $initial || ! in_array( $initial, $codes, true ) ) {
			$initial = $codes[0] ?? '';
		}

		wp_localize_script(
			'prose-layout-studio',
			'ProseLayoutStudio',
			array(
				'restRoot' => esc_url_raw( rest_url( self::REST_NS . '/layout-studio' ) ),
				'nonce'    => wp_create_nonce( 'wp_rest' ),
				'forms'    => $codes,
				'initial'  => $initial,
			)
		);
	}

	/**
	 * Render the Studio page shell (the app mounts via JS).
	 *
	 * @return void
	 */
	public function render_page(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'prose-core' ) );
		}

		?>
		<div class="wrap prose-layout-studio" id="prose-layout-studio">
			<h1><?php esc_html_e( 'CourtFlow Layout Studio', 'prose-core' ); ?></h1>
			<p class="description"><?php esc_html_e( 'Drag field markers onto the official PDF, resize multiline areas, then save. Coordinates are written straight back to the layout JSON — no manual editing.', 'prose-core' ); ?></p>

			<div class="pls-toolbar">
				<label for="pls-form"><?php esc_html_e( 'Form', 'prose-core' ); ?></label>
				<select id="pls-form"></select>
				<button type="button" class="button" id="pls-toggle-grid"><?php esc_html_e( 'Toggle grid', 'prose-core' ); ?></button>
				<label class="pls-inline"><input type="checkbox" id="pls-snap" checked> <?php esc_html_e( 'Snap 5pt', 'prose-core' ); ?></label>
				<button type="button" class="button button-primary" id="pls-save"><?php esc_html_e( 'Save coordinates', 'prose-core' ); ?></button>
				<button type="button" class="button" id="pls-preview"><?php esc_html_e( 'Regenerate preview', 'prose-core' ); ?></button>
				<span class="pls-status" id="pls-status" role="status" aria-live="polite"></span>
			</div>

			<div class="pls-layout">
				<div class="pls-stage-wrap">
					<div class="pls-stage" id="pls-stage">
						<img class="pls-bg" id="pls-bg" alt="" />
						<div class="pls-grid" id="pls-grid"></div>
						<div class="pls-markers" id="pls-markers"></div>
					</div>
				</div>
				<aside class="pls-side">
					<h2><?php esc_html_e( 'Fields', 'prose-core' ); ?></h2>
					<div class="pls-fields" id="pls-fields"></div>
					<h2><?php esc_html_e( 'Live preview', 'prose-core' ); ?></h2>
					<div class="pls-preview" id="pls-preview-pane">
						<p class="description"><?php esc_html_e( 'Click "Regenerate preview" to render the filled form.', 'prose-core' ); ?></p>
					</div>
				</aside>
			</div>
		</div>
		<?php
	}

	/**
	 * Register REST routes.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		$permission = array( $this, 'can_manage' );

		register_rest_route(
			self::REST_NS,
			'/layout-studio/forms',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'rest_list' ),
				'permission_callback' => $permission,
			)
		);

		register_rest_route(
			self::REST_NS,
			'/layout-studio/forms/(?P<code>[A-Za-z0-9._-]+)',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'rest_get' ),
					'permission_callback' => $permission,
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'rest_save' ),
					'permission_callback' => $permission,
				),
			)
		);

		register_rest_route(
			self::REST_NS,
			'/layout-studio/forms/(?P<code>[A-Za-z0-9._-]+)/preview',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'rest_preview' ),
				'permission_callback' => $permission,
			)
		);
	}

	/**
	 * Permission check.
	 *
	 * @return bool
	 */
	public function can_manage(): bool {
		return current_user_can( self::CAPABILITY );
	}

	/**
	 * GET list of forms with a layout.
	 *
	 * @return \WP_REST_Response
	 */
	public function rest_list(): \WP_REST_Response {
		return rest_ensure_response( array( 'forms' => $this->repository->codes() ) );
	}

	/**
	 * GET a layout plus its rasterized background.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function rest_get( \WP_REST_Request $request ) {
		$code = (string) $request->get_param( 'code' );

		if ( ! $this->registry->has( $code ) ) {
			return new \WP_Error( 'prose_layout_missing', __( 'Layout not found.', 'prose-core' ), array( 'status' => 404 ) );
		}

		$layout     = $this->registry->load( $code );
		$template   = $this->template_path( $code );
		$background = $this->background( $code, $template, $layout );

		return rest_ensure_response(
			array(
				'form_code'  => (string) $layout['form_code'],
				'title'      => (string) $layout['title'],
				'template'   => (string) $layout['template'],
				'page_size'  => $layout['page_size'],
				'pages'      => (int) $layout['pages'],
				'fields'     => $layout['fields'],
				'background' => $background,
			)
		);
	}

	/**
	 * POST saved coordinates back to the layout JSON.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function rest_save( \WP_REST_Request $request ) {
		$code = (string) $request->get_param( 'code' );

		if ( ! $this->repository->has( $code ) ) {
			return new \WP_Error( 'prose_layout_missing', __( 'Layout not found.', 'prose-core' ), array( 'status' => 404 ) );
		}

		$params = (array) $request->get_json_params();
		$fields = $this->sanitize_fields( (array) ( $params['fields'] ?? array() ) );

		if ( empty( $fields ) ) {
			return new \WP_Error( 'prose_layout_no_fields', __( 'No fields supplied.', 'prose-core' ), array( 'status' => 400 ) );
		}

		$raw           = $this->repository->read( $code );
		$raw['fields'] = $fields;

		if ( ! $this->repository->write( $code, $raw ) ) {
			return new \WP_Error( 'prose_layout_write_failed', __( 'Could not write layout file (check permissions).', 'prose-core' ), array( 'status' => 500 ) );
		}

		$normalized = $this->registry->load( $code );
		$validation = ( new Layout_Validation_Service() )->validate( $normalized, array( 'template_path' => $this->template_path( $code ) ) );

		return rest_ensure_response(
			array(
				'saved'      => true,
				'fields'     => $normalized['fields'],
				'validation' => $validation,
			)
		);
	}

	/**
	 * POST regenerate a filled preview for the current layout.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function rest_preview( \WP_REST_Request $request ) {
		$code = (string) $request->get_param( 'code' );

		if ( ! $this->registry->has( $code ) ) {
			return new \WP_Error( 'prose_layout_missing', __( 'Layout not found.', 'prose-core' ), array( 'status' => 404 ) );
		}

		$params   = (array) $request->get_json_params();
		$values   = $this->sanitize_values( (array) ( $params['values'] ?? array() ) );
		$values   = empty( $values ) ? $this->preview_values( $code ) : $values;
		$template = $this->template_path( $code );

		$storage  = $this->storage();
		$renderer = new Overlay_Renderer( $this->registry, $storage, new Pdf_Rasterizer( self::PREVIEW_DPI ) );

		$result = $renderer->render_filled(
			$code,
			$values,
			array(
				'template_path' => $template,
				'filename'      => $code . '-studio-preview.pdf',
				'store'         => true,
				'dpi'           => self::PREVIEW_DPI,
			)
		);

		$image = $this->render_preview_image( $code, $result->file_path() );

		return rest_ensure_response(
			array(
				'mode'           => $result->mode(),
				'fields_total'   => $result->field_count(),
				'fields_drawn'   => $result->rendered_count(),
				'fields_skipped' => $result->skipped_count(),
				'duration_ms'    => $result->render_duration_ms(),
				'pdf_url'        => $result->download_url(),
				'image_url'      => $image,
			)
		);
	}

	/**
	 * Sanitize posted field definitions.
	 *
	 * @param array<int, mixed> $fields Raw fields.
	 * @return array<int, array<string, mixed>>
	 */
	private function sanitize_fields( array $fields ): array {
		$clean = array();

		foreach ( $fields as $field ) {
			$field = (array) $field;
			$key   = sanitize_text_field( (string) ( $field['key'] ?? '' ) );

			if ( '' === $key ) {
				continue;
			}

			$entry = array(
				'key'       => $key,
				'label'     => sanitize_text_field( (string) ( $field['label'] ?? $key ) ),
				'source'    => sanitize_text_field( (string) ( $field['source'] ?? $key ) ),
				'page'      => max( 1, (int) ( $field['page'] ?? 1 ) ),
				'x'         => round( max( 0.0, (float) ( $field['x'] ?? 0 ) ), 2 ),
				'y'         => round( max( 0.0, (float) ( $field['y'] ?? 0 ) ), 2 ),
				'font_size' => round( max( 1.0, (float) ( $field['font_size'] ?? 10 ) ), 2 ),
			);

			if ( ! empty( $field['multiline'] ) ) {
				$entry['multiline'] = true;
			}

			if ( ! empty( $field['checkbox'] ) ) {
				$entry['checkbox'] = true;
			}

			$max_width = round( max( 0.0, (float) ( $field['max_width'] ?? 0 ) ), 2 );

			if ( $max_width > 0 ) {
				$entry['max_width'] = $max_width;
			}

			$clean[] = $entry;
		}

		return $clean;
	}

	/**
	 * Sanitize posted preview values.
	 *
	 * @param array<string, mixed> $values Values.
	 * @return array<string, string>
	 */
	private function sanitize_values( array $values ): array {
		$clean = array();

		foreach ( $values as $key => $value ) {
			$clean[ sanitize_key( (string) $key ) ] = sanitize_textarea_field( (string) $value );
		}

		return $clean;
	}

	/**
	 * Rasterize the official PDF to a stored background image per page.
	 *
	 * @param string               $code     Form code.
	 * @param string               $template Template path.
	 * @param array<string, mixed> $layout   Layout.
	 * @return array<int, array<string, mixed>>
	 */
	private function background( string $code, string $template, array $layout ): array {
		if ( '' === $template ) {
			return array();
		}

		$rasterizer = new Pdf_Rasterizer( self::PREVIEW_DPI );

		if ( ! $rasterizer->available() ) {
			return array();
		}

		$pages   = $rasterizer->to_jpeg_pages( $template );
		$storage = $this->storage();
		$out     = array();

		foreach ( $pages as $index => $jpeg ) {
			$stored = $storage->store( $jpeg, sprintf( '%s-bg-p%d.jpg', $code, $index + 1 ) );

			$out[] = array(
				'page' => $index + 1,
				'url'  => add_query_arg( 'v', (string) time(), (string) $stored['download_url'] ),
			);
		}

		return $out;
	}

	/**
	 * Rasterize the first page of a rendered preview PDF to an image URL.
	 *
	 * @param string $code      Form code.
	 * @param string $pdf_path  Rendered PDF path.
	 * @return string
	 */
	private function render_preview_image( string $code, string $pdf_path ): string {
		if ( '' === $pdf_path || ! is_readable( $pdf_path ) ) {
			return '';
		}

		$rasterizer = new Pdf_Rasterizer( self::PREVIEW_DPI );

		if ( ! $rasterizer->available() ) {
			return '';
		}

		$pages = $rasterizer->to_jpeg_pages( $pdf_path );

		if ( empty( $pages ) ) {
			return '';
		}

		$stored = $this->storage()->store( $pages[0], $code . '-studio-preview.jpg' );

		return add_query_arg( 'v', (string) time(), (string) $stored['download_url'] );
	}

	/**
	 * Storage service rooted at the uploads studio directory.
	 *
	 * @return Pdf_Storage_Service
	 */
	private function storage(): Pdf_Storage_Service {
		$uploads = function_exists( 'wp_upload_dir' ) ? wp_upload_dir() : array();
		$base    = isset( $uploads['basedir'] ) ? trailingslashit( (string) $uploads['basedir'] ) . 'prose-layout-studio' : '';
		$url     = isset( $uploads['baseurl'] ) ? trailingslashit( (string) $uploads['baseurl'] ) . 'prose-layout-studio' : '';

		return new Pdf_Storage_Service( $base, $url );
	}

	/**
	 * Resolve the official PDF path for a form code.
	 *
	 * @param string $code Form code.
	 * @return string
	 */
	private function template_path( string $code ): string {
		if ( ! function_exists( 'wp_upload_dir' ) ) {
			return '';
		}

		$uploads = wp_upload_dir();
		$base    = isset( $uploads['basedir'] ) ? rtrim( (string) $uploads['basedir'], '/\\' ) : '';

		if ( '' === $base ) {
			return '';
		}

		$path = $base . '/prose/forms/' . strtolower( $code ) . '.pdf';

		return is_readable( $path ) ? $path : '';
	}

	/**
	 * Default preview values for a form.
	 *
	 * @param string $code Form code.
	 * @return array<string, string>
	 */
	private function preview_values( string $code ): array {
		if ( 'UD-1' === $code ) {
			return array(
				'petitioner_name'  => 'Jane Doe',
				'respondent_name'  => 'John Doe',
				'county'           => 'New York County',
				'grounds'          => 'DRL ' . "\u{00A7}" . '170(7) - irretrievable breakdown in relationship',
				'relief_requested' => 'Restoration of maiden name and equitable distribution pursuant to agreement',
			);
		}

		$values = array();

		foreach ( $this->registry->load( $code )['fields'] as $field ) {
			$values[ (string) $field['source'] ] = strtoupper( (string) $field['key'] );
		}

		return $values;
	}
}
