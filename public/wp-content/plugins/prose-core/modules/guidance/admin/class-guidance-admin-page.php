<?php
/**
 * Guidance Admin Page — coverage dashboard and validation tools.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Guidance\Admin;

use ProSe\Core\Guidance\Guidance_Service;
use ProSe\Core\Loader;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Guidance_Admin_Page
 */
final class Guidance_Admin_Page {

	/**
	 * Admin page slug.
	 */
	public const SLUG = 'prose-guidance';

	/**
	 * Nonce action.
	 */
	private const NONCE_ACTION = 'prose_guidance_admin';

	/**
	 * Guidance service.
	 *
	 * @var Guidance_Service
	 */
	private Guidance_Service $service;

	/**
	 * Constructor.
	 *
	 * @param Guidance_Service|null $service Guidance service.
	 */
	public function __construct( ?Guidance_Service $service = null ) {
		$this->service = $service ?? new Guidance_Service();
	}

	/**
	 * Register hooks.
	 *
	 * @param Loader $loader Hook loader.
	 * @return void
	 */
	public function register( Loader $loader ): void {
		$loader->add_action( 'admin_menu', $this, 'register_page' );
		$loader->add_action( 'admin_init', $this, 'handle_actions' );
	}

	/**
	 * Register submenu page.
	 *
	 * @return void
	 */
	public function register_page(): void {
		add_submenu_page(
			'prose',
			__( 'Guidance', 'prose-core' ),
			__( 'Guidance', 'prose-core' ),
			'manage_options',
			self::SLUG,
			array( $this, 'render' )
		);
	}

	/**
	 * Handle admin POST actions.
	 *
	 * @return void
	 */
	public function handle_actions(): void {
		if ( ! isset( $_POST['prose_guidance_action'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		check_admin_referer( self::NONCE_ACTION );

		$action = sanitize_text_field( wp_unslash( (string) $_POST['prose_guidance_action'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing

		switch ( $action ) {
			case 'validate_guidance':
				$result = $this->service->validate_all();
				$this->redirect_with_notice(
					$result['success']
						? __( 'Guidance validation completed.', 'prose-core' )
						: __( 'Guidance validation found errors.', 'prose-core' ),
					$result['success'] ? 'success' : 'warning'
				);
				break;

			case 'rebuild_index':
				$this->service->get_repository()->rebuild_index();
				$this->redirect_with_notice(
					__( 'Guidance index rebuilt.', 'prose-core' ),
					'success'
				);
				break;
		}
	}

	/**
	 * Render admin page.
	 *
	 * @return void
	 */
	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'prose-core' ) );
		}

		$coverage   = $this->service->coverage();
		$validation = $this->service->validate_all();
		$notice     = isset( $_GET['prose_notice'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['prose_notice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$notice_type = isset( $_GET['prose_notice_type'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['prose_notice_type'] ) ) : 'success'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		?>
		<div class="wrap prose-admin-wrap">
			<h1><?php esc_html_e( 'Guidance', 'prose-core' ); ?></h1>

			<?php if ( '' !== $notice ) : ?>
				<div class="notice notice-<?php echo esc_attr( $notice_type ); ?> is-dismissible">
					<p><?php echo esc_html( $notice ); ?></p>
				</div>
			<?php endif; ?>

			<h2><?php esc_html_e( 'Coverage', 'prose-core' ); ?></h2>
			<table class="widefat striped" style="max-width: 640px;">
				<tbody>
					<tr>
						<th scope="row"><?php esc_html_e( 'Workflow count', 'prose-core' ); ?></th>
						<td><?php echo esc_html( (string) $coverage['workflow_count'] ); ?></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Stage count', 'prose-core' ); ?></th>
						<td><?php echo esc_html( (string) $coverage['stage_count'] ); ?></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Guidance coverage', 'prose-core' ); ?></th>
						<td><?php echo esc_html( (string) $coverage['coverage_percent'] ); ?>%</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Missing guidance files', 'prose-core' ); ?></th>
						<td><?php echo esc_html( (string) $coverage['missing_stages'] ); ?></td>
					</tr>
				</tbody>
			</table>

			<?php if ( ! empty( $coverage['missing_stage_ids'] ) ) : ?>
				<h3><?php esc_html_e( 'Missing stage guidance', 'prose-core' ); ?></h3>
				<ul>
					<?php foreach ( $coverage['missing_stage_ids'] as $stage_id ) : ?>
						<li><code><?php echo esc_html( $stage_id ); ?></code></li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>

			<h2><?php esc_html_e( 'Actions', 'prose-core' ); ?></h2>
			<form method="post" style="display:inline-block;margin-right:12px;">
				<?php wp_nonce_field( self::NONCE_ACTION ); ?>
				<input type="hidden" name="prose_guidance_action" value="validate_guidance" />
				<?php submit_button( __( 'Validate Guidance', 'prose-core' ), 'secondary', 'submit', false ); ?>
			</form>
			<form method="post" style="display:inline-block;">
				<?php wp_nonce_field( self::NONCE_ACTION ); ?>
				<input type="hidden" name="prose_guidance_action" value="rebuild_index" />
				<?php submit_button( __( 'Rebuild Guidance Index', 'prose-core' ), 'secondary', 'submit', false ); ?>
			</form>

			<?php if ( ! empty( $validation['warnings'] ) ) : ?>
				<h2><?php esc_html_e( 'Validation warnings', 'prose-core' ); ?></h2>
				<ul>
					<?php foreach ( $validation['warnings'] as $warning ) : ?>
						<li>
							<code><?php echo esc_html( (string) ( $warning['code'] ?? '' ) ); ?></code>
							<?php if ( ! empty( $warning['stage'] ) ) : ?>
								— <?php echo esc_html( (string) $warning['stage'] ); ?>
							<?php endif; ?>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Redirect back to admin page with a notice.
	 *
	 * @param string $message Notice message.
	 * @param string $type    Notice type.
	 * @return void
	 */
	private function redirect_with_notice( string $message, string $type = 'success' ): void {
		$url = add_query_arg(
			array(
				'page'              => self::SLUG,
				'prose_notice'      => rawurlencode( $message ),
				'prose_notice_type' => $type,
			),
			admin_url( 'admin.php' )
		);

		wp_safe_redirect( $url );
		exit;
	}
}
