<?php
/**
 * Chat Packet Admin — publish workflow blank PDFs for intake Case Actions.
 *
 * Uses Workflow_Catalog + Forms_Catalog (JSON) and Merged_Blank_Pdf_Service — the
 * same path the chat widget uses via POST /package/merged-pdf.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\PackageBuilder\Admin;

use ProSe\Core\Forms\Forms_Catalog;
use ProSe\Core\Loader;
use ProSe\Core\PackageBuilder\Merged_Blank_Pdf_Service;
use ProSe\Core\Packet\Packet_Store;
use ProSe\Core\Routing\Workflow_Catalog;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Chat_Packet_Admin_Page
 */
final class Chat_Packet_Admin_Page {

	/**
	 * Admin page slug.
	 */
	public const SLUG = 'prose-chat-packets';

	/**
	 * Cached merged PDF id prefix (matches Merged_Blank_Pdf_Service).
	 */
	private const STORE_PREFIX = 'blank-';

	/**
	 * Nonce action.
	 */
	private const NONCE_ACTION = 'prose_chat_packet_admin';

	/**
	 * Merged blank PDF service.
	 *
	 * @var Merged_Blank_Pdf_Service
	 */
	private Merged_Blank_Pdf_Service $merged;

	/**
	 * Workflow catalog.
	 *
	 * @var Workflow_Catalog
	 */
	private Workflow_Catalog $workflows;

	/**
	 * Forms catalog.
	 *
	 * @var Forms_Catalog
	 */
	private Forms_Catalog $forms;

	/**
	 * Packet store.
	 *
	 * @var Packet_Store
	 */
	private Packet_Store $store;

	/**
	 * Constructor.
	 *
	 * @param Merged_Blank_Pdf_Service|null $merged    Merged PDF service.
	 * @param Workflow_Catalog|null         $workflows Workflow catalog.
	 * @param Forms_Catalog|null            $forms     Forms catalog.
	 * @param Packet_Store|null             $store     Packet store.
	 */
	public function __construct(
		?Merged_Blank_Pdf_Service $merged = null,
		?Workflow_Catalog $workflows = null,
		?Forms_Catalog $forms = null,
		?Packet_Store $store = null
	) {
		$this->merged    = $merged ?? new Merged_Blank_Pdf_Service();
		$this->workflows = $workflows ?? new Workflow_Catalog();
		$this->forms     = $forms ?? new Forms_Catalog( $this->workflows );
		$this->store     = $store ?? new Packet_Store();
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
			__( 'Chat Packets', 'prose-core' ),
			__( 'Chat Packets', 'prose-core' ),
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
		if ( ! isset( $_POST['prose_chat_packet_action'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		check_admin_referer( self::NONCE_ACTION );

		$action   = sanitize_text_field( wp_unslash( (string) $_POST['prose_chat_packet_action'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$workflow = isset( $_POST['workflow'] ) ? sanitize_key( wp_unslash( (string) $_POST['workflow'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing

		switch ( $action ) {
			case 'build':
				$this->redirect_with_notice( $this->merged->build( $workflow ) );
				break;

			case 'rebuild':
				$this->redirect_with_notice( $this->merged->build( $workflow, true ) );
				break;

			case 'build_all':
				$this->redirect_with_notice( $this->build_all( false ) );
				break;

			case 'rebuild_all':
				$this->redirect_with_notice( $this->build_all( true ) );
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

		$rows   = $this->list_rows();
		$notice = isset( $_GET['prose_chat_packet_notice'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['prose_chat_packet_notice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		?>
		<div class="wrap prose-admin-wrap">
			<h1><?php esc_html_e( 'Chat Packets', 'prose-core' ); ?></h1>
			<p>
				<?php esc_html_e( 'Pre-build blank PDF packets for intake chat downloads. Form lists come from workflow JSON; PDF paths come from the Forms Repository JSON catalog.', 'prose-core' ); ?>
			</p>

			<?php if ( '' !== $notice ) : ?>
				<div class="notice notice-info is-dismissible"><p><?php echo esc_html( $notice ); ?></p></div>
			<?php endif; ?>

			<form method="post" style="margin-bottom: 1em;">
				<?php wp_nonce_field( self::NONCE_ACTION ); ?>
				<input type="hidden" name="prose_chat_packet_action" value="build_all" />
				<?php submit_button( __( 'Build All Chat Packets', 'prose-core' ), 'secondary', 'submit', false ); ?>
			</form>

			<form method="post" style="margin-bottom: 1.5em;">
				<?php wp_nonce_field( self::NONCE_ACTION ); ?>
				<input type="hidden" name="prose_chat_packet_action" value="rebuild_all" />
				<?php submit_button( __( 'Rebuild All Chat Packets', 'prose-core' ), 'secondary', 'submit', false ); ?>
			</form>

			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Workflow', 'prose-core' ); ?></th>
						<th><?php esc_html_e( 'Title', 'prose-core' ); ?></th>
						<th><?php esc_html_e( 'Forms', 'prose-core' ); ?></th>
						<th><?php esc_html_e( 'Catalog Ready', 'prose-core' ); ?></th>
						<th><?php esc_html_e( 'Packet Status', 'prose-core' ); ?></th>
						<th><?php esc_html_e( 'Missing Forms', 'prose-core' ); ?></th>
						<th><?php esc_html_e( 'Size', 'prose-core' ); ?></th>
						<th><?php esc_html_e( 'Download', 'prose-core' ); ?></th>
						<th><?php esc_html_e( 'Actions', 'prose-core' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $rows as $row ) : ?>
						<tr>
							<td><code><?php echo esc_html( (string) $row['workflow'] ); ?></code></td>
							<td><?php echo esc_html( (string) $row['title'] ); ?></td>
							<td><?php echo esc_html( (string) $row['form_count'] ); ?></td>
							<td><?php echo esc_html( (string) $row['catalog_ready'] ); ?></td>
							<td><?php echo esc_html( (string) $row['packet_status'] ); ?></td>
							<td><?php echo esc_html( implode( ', ', (array) $row['missing'] ) ); ?></td>
							<td><?php echo esc_html( size_format( (int) $row['size'], 1 ) ); ?></td>
							<td>
								<?php if ( '' !== (string) $row['download_url'] ) : ?>
									<a href="<?php echo esc_url( (string) $row['download_url'] ); ?>" download><?php esc_html_e( 'PDF', 'prose-core' ); ?></a>
								<?php else : ?>
									<?php esc_html_e( '—', 'prose-core' ); ?>
								<?php endif; ?>
							</td>
							<td><?php $this->render_row_actions( (string) $row['workflow'] ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	/**
	 * Build dashboard rows for all workflows.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function list_rows(): array {
		$rows = array();

		foreach ( $this->workflows->all() as $workflow_key => $definition ) {
			$workflow_key = (string) $workflow_key;
			$codes        = $this->workflows->required_form_codes( (array) $definition );
			$status       = $this->merged->status( $workflow_key );
			$missing      = (array) ( $status['missing'] ?? array() );
			$ready_count  = 0;

			foreach ( $codes as $code ) {
				$record = $this->forms->by_code( (string) $code );

				if ( is_array( $record ) && ! empty( $record['generation_ready'] ) ) {
					++$ready_count;
				}
			}

			$stored_id = self::STORE_PREFIX . $workflow_key;
			$cached    = $this->store->pdf_exists( $stored_id );
			$available = ! empty( $status['available'] );

			if ( $cached && $available ) {
				$packet_status = 'ready';
			} elseif ( $cached ) {
				$packet_status = 'cached';
			} elseif ( $available ) {
				$packet_status = 'buildable';
			} elseif ( ! empty( $missing ) ) {
				$packet_status = 'missing_assets';
			} else {
				$packet_status = 'not_built';
			}

			$rows[] = array(
				'workflow'      => $workflow_key,
				'title'         => (string) ( $definition['title'] ?? $workflow_key ),
				'form_count'    => count( $codes ),
				'catalog_ready' => sprintf( '%d / %d', $ready_count, count( $codes ) ),
				'packet_status' => $packet_status,
				'missing'       => $missing,
				'size'          => $this->store->pdf_size( $stored_id ),
				'download_url'  => (string) ( $status['download_url'] ?? '' ),
			);
		}

		return $rows;
	}

	/**
	 * Build or rebuild all workflow packets.
	 *
	 * @param bool $force Force rebuild.
	 * @return array<string, mixed>
	 */
	private function build_all( bool $force ): array {
		$built  = 0;
		$failed = 0;

		foreach ( array_keys( $this->workflows->all() ) as $workflow_key ) {
			$result = $this->merged->build( (string) $workflow_key, $force );

			if ( ! empty( $result['success'] ) ) {
				++$built;
			} else {
				++$failed;
			}
		}

		return array(
			'success' => 0 === $failed,
			'message' => sprintf(
				/* translators: 1: built count, 2: failed count */
				__( 'Chat packets: %1$d built, %2$d failed.', 'prose-core' ),
				$built,
				$failed
			),
		);
	}

	/**
	 * Render per-row action buttons.
	 *
	 * @param string $workflow Workflow key.
	 * @return void
	 */
	private function render_row_actions( string $workflow ): void {
		$actions = array(
			'build'   => __( 'Build', 'prose-core' ),
			'rebuild' => __( 'Rebuild', 'prose-core' ),
		);

		foreach ( $actions as $action => $label ) {
			?>
			<form method="post" style="display:inline-block; margin-right:4px;">
				<?php wp_nonce_field( self::NONCE_ACTION ); ?>
				<input type="hidden" name="prose_chat_packet_action" value="<?php echo esc_attr( $action ); ?>" />
				<input type="hidden" name="workflow" value="<?php echo esc_attr( $workflow ); ?>" />
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
		if ( ! empty( $result['success'] ) ) {
			$message = (string) ( $result['message'] ?? '' );

			if ( '' === $message ) {
				$message = __( 'Chat packet built successfully.', 'prose-core' );
			}
		} elseif ( ! empty( $result['error']['message'] ) ) {
			$message = (string) $result['error']['message'];
		} else {
			$message = __( 'Chat packet operation failed.', 'prose-core' );
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'                     => self::SLUG,
					'prose_chat_packet_notice' => rawurlencode( $message ),
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}
}
