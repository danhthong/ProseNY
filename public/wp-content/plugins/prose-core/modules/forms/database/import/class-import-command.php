<?php
/**
 * Admin import validation and execution commands.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Forms\Database\Import;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Import_Command
 */
final class Import_Command {

	/**
	 * Register admin hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'register_menu' ), 20 );
		add_action( 'admin_post_prose_run_catalog_import', array( $this, 'handle_import' ) );
		add_action( 'admin_post_prose_validate_catalog_import', array( $this, 'handle_validate' ) );
		add_action( 'admin_post_prose_rollback_catalog_import', array( $this, 'handle_rollback' ) );
	}

	/**
	 * Register ProSe submenu page.
	 *
	 * @return void
	 */
	public function register_menu(): void {
		add_submenu_page(
			'prose',
			__( 'Import Catalog', 'prose-core' ),
			__( 'Import Catalog', 'prose-core' ),
			'manage_options',
			'prose-import-catalog',
			array( $this, 'render_page' )
		);
	}

	/**
	 * Render import admin page.
	 *
	 * @return void
	 */
	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized.', 'prose-core' ) );
		}

		$latest_run = get_option( Import_Run_Context::LATEST_RUN_OPTION, '' );
		$message    = isset( $_GET['prose_import_message'] ) ? sanitize_text_field( wp_unslash( $_GET['prose_import_message'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'CourtFlow Catalog Import', 'prose-core' ); ?></h1>
			<?php if ( '' !== $message ) : ?>
				<div class="notice notice-info"><p><?php echo esc_html( $message ); ?></p></div>
			<?php endif; ?>
			<p><?php esc_html_e( 'Import workflows, nodes, packages, aliases, and form mappings from JSON seeder artifacts.', 'prose-core' ); ?></p>
			<?php if ( '' !== $latest_run ) : ?>
				<p><strong><?php esc_html_e( 'Latest import run:', 'prose-core' ); ?></strong> <code><?php echo esc_html( (string) $latest_run ); ?></code></p>
			<?php endif; ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;margin-right:8px;">
				<?php wp_nonce_field( 'prose_validate_catalog_import' ); ?>
				<input type="hidden" name="action" value="prose_validate_catalog_import" />
				<?php submit_button( __( 'Validate (Dry Run)', 'prose-core' ), 'secondary', 'submit', false ); ?>
			</form>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;margin-right:8px;">
				<?php wp_nonce_field( 'prose_run_catalog_import' ); ?>
				<input type="hidden" name="action" value="prose_run_catalog_import" />
				<?php submit_button( __( 'Run Import', 'prose-core' ), 'primary', 'submit', false ); ?>
			</form>
			<?php if ( '' !== $latest_run ) : ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;">
				<?php wp_nonce_field( 'prose_rollback_catalog_import' ); ?>
				<input type="hidden" name="action" value="prose_rollback_catalog_import" />
				<input type="hidden" name="import_run_id" value="<?php echo esc_attr( (string) $latest_run ); ?>" />
				<?php submit_button( __( 'Rollback Latest Run', 'prose-core' ), 'delete', 'submit', false ); ?>
			</form>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Handle dry-run validation.
	 *
	 * @return void
	 */
	public function handle_validate(): void {
		$this->assert_admin( 'prose_validate_catalog_import' );

		$orchestrator = new Import_Orchestrator();
		$result       = $orchestrator->run( true, false );

		$msg = ! empty( $result['validation']['passed'] )
			? __( 'Validation passed. See import-validation-report.md in seeders/data.', 'prose-core' )
			: __( 'Validation failed. See import-validation-report.md.', 'prose-core' );

		$this->redirect( $msg );
	}

	/**
	 * Handle full import.
	 *
	 * @return void
	 */
	public function handle_import(): void {
		$this->assert_admin( 'prose_run_catalog_import' );

		$orchestrator = new Import_Orchestrator();
		$result       = $orchestrator->run( false, false );

		if ( ! empty( $result['success'] ) ) {
			$msg = sprintf(
				/* translators: %s: import run id */
				__( 'Import completed. Run ID: %s', 'prose-core' ),
				(string) ( $result['import_run_id'] ?? '' )
			);
		} else {
			$msg = (string) ( $result['error'] ?? __( 'Import failed.', 'prose-core' ) );
		}

		$this->redirect( $msg );
	}

	/**
	 * Handle rollback of latest import run.
	 *
	 * @return void
	 */
	public function handle_rollback(): void {
		$this->assert_admin( 'prose_rollback_catalog_import' );

		$run_id = isset( $_POST['import_run_id'] ) ? sanitize_text_field( wp_unslash( $_POST['import_run_id'] ) ) : '';

		$rollback = new Import_Rollback();
		$counts   = $rollback->rollback( $run_id );

		$msg = sprintf(
			/* translators: %s: import run id */
			__( 'Rollback completed for %s', 'prose-core' ),
			$run_id
		) . ' — ' . wp_json_encode( $counts );

		$this->redirect( $msg );
	}

	/**
	 * Verify capability and nonce.
	 *
	 * @param string $action Nonce action name.
	 * @return void
	 */
	private function assert_admin( string $action ): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized.', 'prose-core' ) );
		}

		check_admin_referer( $action );
	}

	/**
	 * Redirect back to import page with message.
	 *
	 * @param string $message User message.
	 * @return void
	 */
	private function redirect( string $message ): void {
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'                  => 'prose-import-catalog',
					'prose_import_message'  => rawurlencode( $message ),
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}
}
