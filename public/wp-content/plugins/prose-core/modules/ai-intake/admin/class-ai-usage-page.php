<?php
/**
 * AI Usage admin page — Tools → AI Usage.
 *
 * Lists every OpenAI API call with token usage and cumulative totals.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Ai_Intake\Admin;

use ProSe\Core\Ai_Intake\Usage_Logger;
use ProSe\Core\Loader;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class AI_Usage_Page
 */
final class AI_Usage_Page {

	/**
	 * Page slug.
	 */
	public const SLUG = 'prose-ai-usage';

	/**
	 * Nonce action.
	 */
	private const NONCE_ACTION = 'prose_ai_usage';

	/**
	 * Usage logger.
	 *
	 * @var Usage_Logger
	 */
	private Usage_Logger $usage;

	/**
	 * Constructor.
	 *
	 * @param Usage_Logger|null $usage Usage logger.
	 */
	public function __construct( ?Usage_Logger $usage = null ) {
		$this->usage = $usage ?? new Usage_Logger();
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
	 * Register the Tools submenu page.
	 *
	 * @return void
	 */
	public function register_page(): void {
		add_management_page(
			__( 'AI Usage', 'prose-core' ),
			__( 'AI Usage', 'prose-core' ),
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
		if ( ! isset( $_POST['prose_ai_usage_action'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		check_admin_referer( self::NONCE_ACTION );

		$action = sanitize_text_field( wp_unslash( (string) $_POST['prose_ai_usage_action'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing

		switch ( $action ) {
			case 'clear_log':
				$this->usage->clear();
				$this->redirect_with_notice( 'cleared' );
				break;
			case 'reset_totals':
				$this->usage->reset_totals();
				$this->redirect_with_notice( 'reset' );
				break;
		}
	}

	/**
	 * Redirect with notice code.
	 *
	 * @param string $code Notice code.
	 * @return void
	 */
	private function redirect_with_notice( string $code ): void {
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'   => self::SLUG,
					'notice' => $code,
				),
				admin_url( 'tools.php' )
			)
		);
		exit;
	}

	/**
	 * Render the page.
	 *
	 * @return void
	 */
	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'prose-core' ) );
		}

		$totals = $this->usage->totals();
		$logs   = array_reverse( $this->usage->all() );
		$notice = isset( $_GET['notice'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['notice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'AI Usage', 'prose-core' ); ?></h1>
			<p class="description"><?php esc_html_e( 'Every OpenAI API call made by the intake interpreter, with token usage.', 'prose-core' ); ?></p>

			<?php if ( 'cleared' === $notice ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Usage log cleared.', 'prose-core' ); ?></p></div>
			<?php elseif ( 'reset' === $notice ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Lifetime totals reset.', 'prose-core' ); ?></p></div>
			<?php endif; ?>

			<h2><?php esc_html_e( 'Lifetime Totals', 'prose-core' ); ?></h2>
			<table class="widefat striped" style="max-width:640px;">
				<tbody>
					<tr>
						<th><?php esc_html_e( 'Total Requests', 'prose-core' ); ?></th>
						<td><?php echo esc_html( number_format_i18n( $totals['requests'] ) ); ?></td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Total Tokens', 'prose-core' ); ?></th>
						<td><strong><?php echo esc_html( number_format_i18n( $totals['total_tokens'] ) ); ?></strong></td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Prompt Tokens', 'prose-core' ); ?></th>
						<td><?php echo esc_html( number_format_i18n( $totals['prompt_tokens'] ) ); ?></td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Completion Tokens', 'prose-core' ); ?></th>
						<td><?php echo esc_html( number_format_i18n( $totals['completion_tokens'] ) ); ?></td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Failed Requests', 'prose-core' ); ?></th>
						<td><?php echo esc_html( number_format_i18n( $totals['errors'] ) ); ?></td>
					</tr>
				</tbody>
			</table>

			<p>
				<form method="post" style="display:inline-block;margin-right:8px;">
					<?php wp_nonce_field( self::NONCE_ACTION ); ?>
					<input type="hidden" name="prose_ai_usage_action" value="clear_log" />
					<?php submit_button( __( 'Clear Log', 'prose-core' ), 'secondary', 'submit', false ); ?>
				</form>
				<form method="post" style="display:inline-block;">
					<?php wp_nonce_field( self::NONCE_ACTION ); ?>
					<input type="hidden" name="prose_ai_usage_action" value="reset_totals" />
					<?php submit_button( __( 'Reset Totals', 'prose-core' ), 'delete', 'submit', false ); ?>
				</form>
			</p>

			<h2><?php esc_html_e( 'Recent API Calls', 'prose-core' ); ?></h2>
			<?php if ( empty( $logs ) ) : ?>
				<p><?php esc_html_e( 'No API calls recorded yet.', 'prose-core' ); ?></p>
			<?php else : ?>
				<table class="widefat striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Time (UTC)', 'prose-core' ); ?></th>
							<th><?php esc_html_e( 'Type', 'prose-core' ); ?></th>
							<th><?php esc_html_e( 'Model', 'prose-core' ); ?></th>
							<th style="text-align:right;"><?php esc_html_e( 'Prompt', 'prose-core' ); ?></th>
							<th style="text-align:right;"><?php esc_html_e( 'Completion', 'prose-core' ); ?></th>
							<th style="text-align:right;"><?php esc_html_e( 'Total Tokens', 'prose-core' ); ?></th>
							<th style="text-align:right;"><?php esc_html_e( 'Latency', 'prose-core' ); ?></th>
							<th><?php esc_html_e( 'Status', 'prose-core' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $logs as $entry ) : ?>
							<?php $ok = 'ok' === (string) ( $entry['status'] ?? 'ok' ); ?>
							<tr>
								<td><?php echo esc_html( gmdate( 'Y-m-d H:i:s', (int) ( $entry['timestamp'] ?? 0 ) ) ); ?></td>
								<td><?php echo esc_html( (string) ( $entry['type'] ?? '' ) ); ?></td>
								<td><?php echo esc_html( (string) ( $entry['model'] ?? '' ) ); ?></td>
								<td style="text-align:right;"><?php echo esc_html( number_format_i18n( (int) ( $entry['prompt_tokens'] ?? 0 ) ) ); ?></td>
								<td style="text-align:right;"><?php echo esc_html( number_format_i18n( (int) ( $entry['completion_tokens'] ?? 0 ) ) ); ?></td>
								<td style="text-align:right;"><strong><?php echo esc_html( number_format_i18n( (int) ( $entry['total_tokens'] ?? 0 ) ) ); ?></strong></td>
								<td style="text-align:right;"><?php echo esc_html( (int) ( $entry['latency_ms'] ?? 0 ) . 'ms' ); ?></td>
								<td>
									<?php if ( $ok ) : ?>
										<span style="color:#1a7f37;">&#10003; <?php esc_html_e( 'ok', 'prose-core' ); ?></span>
									<?php else : ?>
										<span style="color:#b32d2e;" title="<?php echo esc_attr( (string) ( $entry['error'] ?? '' ) ); ?>">&#10007; <?php esc_html_e( 'error', 'prose-core' ); ?></span>
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}
}
