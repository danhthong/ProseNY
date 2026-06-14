<?php
/**
 * Packet Admin Page — build-time packet publishing dashboard.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Packet\Admin;

use ProSe\Core\Loader;
use ProSe\Core\Packet\Packet_Service;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Packet_Admin_Page
 */
final class Packet_Admin_Page {

	/**
	 * Admin page slug.
	 */
	public const SLUG = 'prose-pdf-packets';

	/**
	 * Nonce action.
	 */
	private const NONCE_ACTION = 'prose_packet_admin';

	/**
	 * Packet service.
	 *
	 * @var Packet_Service
	 */
	private Packet_Service $service;

	/**
	 * Constructor.
	 *
	 * @param Packet_Service|null $service Packet service.
	 */
	public function __construct( ?Packet_Service $service = null ) {
		$this->service = $service ?? new Packet_Service();
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
			__( 'PDF Packets', 'prose-core' ),
			__( 'PDF Packets', 'prose-core' ),
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
		if ( ! isset( $_POST['prose_packet_action'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		check_admin_referer( self::NONCE_ACTION );

		$action     = sanitize_text_field( wp_unslash( (string) $_POST['prose_packet_action'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$package_id = isset( $_POST['package_id'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['package_id'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing

		switch ( $action ) {
			case 'generate_packet':
				$result = $this->service->build(
					$package_id,
					array(
						'build_pdf' => true,
						'build_zip' => false,
					)
				);
				$this->redirect_with_notice( $result );
				break;

			case 'generate_zip':
				$result = $this->service->build(
					$package_id,
					array(
						'build_pdf' => false,
						'build_zip' => true,
					)
				);
				$this->redirect_with_notice( $result );
				break;

			case 'regenerate_packet':
				$result = $this->service->rebuild( $package_id );
				$this->redirect_with_notice( $result );
				break;

			case 'rebuild_all':
				$result = $this->service->build_all( array( 'force' => true ) );
				$this->redirect_with_notice(
					array(
						'success' => true,
						'message' => __( 'All packets rebuilt.', 'prose-core' ),
					)
				);
				unset( $result );
				break;

			case 'rebuild_changed':
				$result = $this->service->rebuild_changed();
				$this->redirect_with_notice(
					array(
						'success' => true,
						'message' => __( 'Changed packets rebuilt.', 'prose-core' ),
					)
				);
				unset( $result );
				break;

			case 'validate_packet':
				$result = $this->service->validate( $package_id );
				$this->redirect_with_notice( $result );
				break;

			case 'validate_all':
				$result = $this->service->validate_all();
				$valid  = true;

				foreach ( (array) ( $result['results'] ?? array() ) as $row ) {
					if ( empty( $row['success'] ) ) {
						$valid = false;
						break;
					}
				}

				$this->redirect_with_notice(
					array(
						'success' => $valid,
						'message' => $valid
							? __( 'All packets validated successfully.', 'prose-core' )
							: __( 'One or more packets failed validation.', 'prose-core' ),
					)
				);
				unset( $result );
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

		$rows   = $this->service->list_packages();
		$notice = isset( $_GET['prose_packet_notice'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['prose_packet_notice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		?>
		<div class="wrap prose-admin-wrap">
			<h1><?php esc_html_e( 'PDF Packets', 'prose-core' ); ?></h1>
			<p><?php esc_html_e( 'Pre-generate and cache blank court-form packets. End users only download published artifacts.', 'prose-core' ); ?></p>

			<?php if ( '' !== $notice ) : ?>
				<div class="notice notice-info is-dismissible"><p><?php echo esc_html( $notice ); ?></p></div>
			<?php endif; ?>

			<form method="post" style="margin-bottom: 1em;">
				<?php wp_nonce_field( self::NONCE_ACTION ); ?>
				<input type="hidden" name="prose_packet_action" value="rebuild_all" />
				<?php submit_button( __( 'Rebuild All Packets', 'prose-core' ), 'secondary', 'submit', false ); ?>
			</form>

			<form method="post" style="margin-bottom: 1.5em;">
				<?php wp_nonce_field( self::NONCE_ACTION ); ?>
				<input type="hidden" name="prose_packet_action" value="rebuild_changed" />
				<?php submit_button( __( 'Rebuild Changed Packets', 'prose-core' ), 'secondary', 'submit', false ); ?>
			</form>

			<form method="post" style="margin-bottom: 1.5em;">
				<?php wp_nonce_field( self::NONCE_ACTION ); ?>
				<input type="hidden" name="prose_packet_action" value="validate_all" />
				<?php submit_button( __( 'Validate All Packets', 'prose-core' ), 'secondary', 'submit', false ); ?>
			</form>

			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Package ID', 'prose-core' ); ?></th>
						<th><?php esc_html_e( 'Package Name', 'prose-core' ); ?></th>
						<th><?php esc_html_e( 'Form Count', 'prose-core' ); ?></th>
						<th><?php esc_html_e( 'Packet Status', 'prose-core' ); ?></th>
						<th><?php esc_html_e( 'PDF Packet Exists', 'prose-core' ); ?></th>
						<th><?php esc_html_e( 'ZIP Packet Exists', 'prose-core' ); ?></th>
						<th><?php esc_html_e( 'Missing PDFs', 'prose-core' ); ?></th>
						<th><?php esc_html_e( 'Invalid PDFs', 'prose-core' ); ?></th>
						<th><?php esc_html_e( 'Packet Size', 'prose-core' ); ?></th>
						<th><?php esc_html_e( 'Last Generated', 'prose-core' ); ?></th>
						<th><?php esc_html_e( 'Actions', 'prose-core' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $rows as $row ) : ?>
						<tr>
							<td><code><?php echo esc_html( (string) $row['package_id'] ); ?></code></td>
							<td><?php echo esc_html( (string) $row['package_name'] ); ?></td>
							<td><?php echo esc_html( (string) $row['form_count'] ); ?></td>
							<td><?php echo esc_html( (string) $row['packet_status'] ); ?></td>
							<td>
								<?php if ( ! empty( $row['pdf_exists'] ) && '' !== (string) $row['pdf_packet_url'] ) : ?>
									<a href="<?php echo esc_url( (string) $row['pdf_packet_url'] ); ?>" download><?php esc_html_e( 'Download PDF', 'prose-core' ); ?></a>
								<?php elseif ( ! empty( $row['pdf_exists'] ) ) : ?>
									<?php esc_html_e( 'Yes', 'prose-core' ); ?>
								<?php else : ?>
									<?php esc_html_e( 'No', 'prose-core' ); ?>
								<?php endif; ?>
							</td>
							<td>
								<?php if ( ! empty( $row['zip_exists'] ) && '' !== (string) $row['zip_packet_url'] ) : ?>
									<a href="<?php echo esc_url( (string) $row['zip_packet_url'] ); ?>" download><?php esc_html_e( 'Download ZIP', 'prose-core' ); ?></a>
								<?php elseif ( ! empty( $row['zip_exists'] ) ) : ?>
									<?php esc_html_e( 'Yes', 'prose-core' ); ?>
								<?php else : ?>
									<?php esc_html_e( 'No', 'prose-core' ); ?>
								<?php endif; ?>
							</td>
							<td><?php echo esc_html( implode( ', ', (array) $row['missing_pdfs'] ) ); ?></td>
							<td><?php echo esc_html( implode( ', ', (array) $row['invalid_pdfs'] ) ); ?></td>
							<td><?php echo esc_html( size_format( (int) $row['packet_size'], 1 ) ); ?></td>
							<td><?php echo esc_html( (string) $row['last_generated'] ); ?></td>
							<td>
								<?php $this->render_row_actions( (string) $row['package_id'] ); ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	/**
	 * Render per-row action buttons.
	 *
	 * @param string $package_id Package enum id.
	 * @return void
	 */
	private function render_row_actions( string $package_id ): void {
		$actions = array(
			'generate_packet'   => __( 'Generate Packet', 'prose-core' ),
			'generate_zip'      => __( 'Generate ZIP', 'prose-core' ),
			'regenerate_packet' => __( 'Regenerate Packet', 'prose-core' ),
			'validate_packet'   => __( 'Validate Packet', 'prose-core' ),
		);

		foreach ( $actions as $action => $label ) {
			?>
			<form method="post" style="display:inline-block; margin-right:4px;">
				<?php wp_nonce_field( self::NONCE_ACTION ); ?>
				<input type="hidden" name="prose_packet_action" value="<?php echo esc_attr( $action ); ?>" />
				<input type="hidden" name="package_id" value="<?php echo esc_attr( $package_id ); ?>" />
				<?php submit_button( $label, 'small', 'submit', false ); ?>
			</form>
			<?php
		}
	}

	/**
	 * Redirect back with an admin notice.
	 *
	 * @param array<string, mixed> $result Operation result.
	 * @return void
	 */
	private function redirect_with_notice( array $result ): void {
		$message = '';

		if ( ! empty( $result['success'] ) ) {
			$message = (string) ( $result['message'] ?? __( 'Packet operation completed.', 'prose-core' ) );
		} elseif ( ! empty( $result['error']['message'] ) ) {
			$message = (string) $result['error']['message'];
		} elseif ( ! empty( $result['errors'][0]['message'] ) ) {
			$message = (string) $result['errors'][0]['message'];
		} else {
			$message = __( 'Packet operation failed.', 'prose-core' );
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'                => self::SLUG,
					'prose_packet_notice' => rawurlencode( $message ),
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}
}
