<?php
/**
 * AI Settings admin page.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Ai_Intake\Admin;

use ProSe\Core\Ai_Intake\AI_Intake_Service;
use ProSe\Core\Ai_Intake\AI_Logger;
use ProSe\Core\Ai_Intake\AI_Settings;
use ProSe\Core\Loader;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class AI_Settings_Page
 */
final class AI_Settings_Page {

	/**
	 * Page slug.
	 */
	public const SLUG = 'prose-ai-settings';

	/**
	 * Nonce action.
	 */
	private const NONCE_ACTION = 'prose_ai_settings';

	/**
	 * Service.
	 *
	 * @var AI_Intake_Service
	 */
	private AI_Intake_Service $service;

	/**
	 * Constructor.
	 *
	 * @param AI_Intake_Service|null $service Service.
	 */
	public function __construct( ?AI_Intake_Service $service = null ) {
		$this->service = $service ?? new AI_Intake_Service();
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
			__( 'AI Settings', 'prose-core' ),
			__( 'AI Settings', 'prose-core' ),
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
		if ( ! isset( $_POST['prose_ai_action'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		check_admin_referer( self::NONCE_ACTION );

		$action = sanitize_text_field( wp_unslash( (string) $_POST['prose_ai_action'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing

		switch ( $action ) {
			case 'save_settings':
				$this->save_settings();
				break;
			case 'test_connection':
				$this->test_connection();
				break;
			case 'clear_logs':
				$this->service->get_logger()->clear();
				$this->redirect_with_notice( 'logs_cleared' );
				break;
		}
	}

	/**
	 * Save settings from POST.
	 *
	 * @return void
	 */
	private function save_settings(): void {
		$settings = $this->service->get_settings();
		$current  = $settings->all();

		$api_key = isset( $_POST['api_key'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['api_key'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing

		if ( '' === $api_key || str_contains( $api_key, '*' ) ) {
			$api_key = (string) ( $current['api_key'] ?? '' );
		}

		$model = sanitize_text_field( wp_unslash( (string) ( $_POST['model'] ?? 'gpt-5.5' ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$model = (string) preg_replace( '/\s+/', '-', trim( $model ) );

		$settings->update(
			array(
				'provider'           => sanitize_text_field( wp_unslash( (string) ( $_POST['provider'] ?? 'openai' ) ) ), // phpcs:ignore WordPress.Security.NonceVerification.Missing
				'api_key'            => $api_key,
				'model'              => '' !== $model ? $model : 'gpt-5.5', // phpcs:ignore WordPress.Security.NonceVerification.Missing
				'temperature'        => (float) ( $_POST['temperature'] ?? 0.2 ), // phpcs:ignore WordPress.Security.NonceVerification.Missing
				'max_tokens'         => (int) ( $_POST['max_tokens'] ?? 1024 ), // phpcs:ignore WordPress.Security.NonceVerification.Missing
				'timeout'            => (int) ( $_POST['timeout'] ?? 30 ), // phpcs:ignore WordPress.Security.NonceVerification.Missing
				'system_prompt'      => sanitize_textarea_field( wp_unslash( (string) ( $_POST['system_prompt'] ?? '' ) ) ), // phpcs:ignore WordPress.Security.NonceVerification.Missing
				'system_prompt_mode' => sanitize_text_field( wp_unslash( (string) ( $_POST['system_prompt_mode'] ?? 'append' ) ) ), // phpcs:ignore WordPress.Security.NonceVerification.Missing
			)
		);

		$this->redirect_with_notice( 'saved' );
	}

	/**
	 * Run connection test.
	 *
	 * @return void
	 */
	private function test_connection(): void {
		$result = $this->service->test_connection();
		$code   = ! empty( $result['success'] ) ? 'test_ok' : 'test_fail';

		$this->redirect_with_notice( $code );
	}

	/**
	 * Redirect with admin notice code.
	 *
	 * @param string $code Notice code.
	 * @return void
	 */
	private function redirect_with_notice( string $code ): void {
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'    => self::SLUG,
					'notice'  => $code,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
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

		$settings = $this->service->get_settings();
		$all      = $settings->all();
		$logger   = $this->service->get_logger();
		$logs     = array_slice( array_reverse( $logger->all() ), 0, 10 );
		$notice   = isset( $_GET['notice'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['notice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$last_request = is_array( $all['last_request'] ?? null ) ? $all['last_request'] : array();
		$last_error   = is_array( $all['last_error'] ?? null ) ? $all['last_error'] : array();
		$masked_key   = $settings->mask_api_key( (string) ( $all['api_key'] ?? '' ) );

		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'AI Settings', 'prose-core' ); ?></h1>

			<?php if ( 'saved' === $notice ) : ?>
				<div class="notice notice-success"><p><?php esc_html_e( 'Settings saved.', 'prose-core' ); ?></p></div>
			<?php elseif ( 'test_ok' === $notice ) : ?>
				<div class="notice notice-success"><p><?php esc_html_e( 'Connection test succeeded.', 'prose-core' ); ?></p></div>
			<?php elseif ( 'test_fail' === $notice ) : ?>
				<div class="notice notice-error"><p><?php esc_html_e( 'Connection test failed. See Last Error below.', 'prose-core' ); ?></p></div>
			<?php elseif ( 'logs_cleared' === $notice ) : ?>
				<div class="notice notice-success"><p><?php esc_html_e( 'Logs cleared.', 'prose-core' ); ?></p></div>
			<?php endif; ?>

			<form method="post">
				<?php wp_nonce_field( self::NONCE_ACTION ); ?>
				<input type="hidden" name="prose_ai_action" value="save_settings" />

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="prose-ai-provider"><?php esc_html_e( 'Provider', 'prose-core' ); ?></label></th>
						<td>
							<select name="provider" id="prose-ai-provider">
								<option value="openai" <?php selected( $all['provider'] ?? '', 'openai' ); ?>>OpenAI</option>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="prose-ai-model"><?php esc_html_e( 'Model', 'prose-core' ); ?></label></th>
						<td><input name="model" id="prose-ai-model" type="text" class="regular-text" value="<?php echo esc_attr( (string) ( $all['model'] ?? 'gpt-5.5' ) ); ?>" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="prose-ai-api-key"><?php esc_html_e( 'OpenAI API Key', 'prose-core' ); ?></label></th>
						<td>
							<input name="api_key" id="prose-ai-api-key" type="password" class="regular-text" value="" placeholder="<?php echo esc_attr( $masked_key ); ?>" autocomplete="off" />
							<p class="description"><?php esc_html_e( 'Leave blank to keep the existing key. Key is never shown in full.', 'prose-core' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="prose-ai-temperature"><?php esc_html_e( 'Temperature', 'prose-core' ); ?></label></th>
						<td><input name="temperature" id="prose-ai-temperature" type="number" step="0.1" min="0" max="2" value="<?php echo esc_attr( (string) ( $all['temperature'] ?? 0.2 ) ); ?>" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="prose-ai-max-tokens"><?php esc_html_e( 'Max Tokens', 'prose-core' ); ?></label></th>
						<td><input name="max_tokens" id="prose-ai-max-tokens" type="number" min="1" value="<?php echo esc_attr( (string) ( $all['max_tokens'] ?? 1024 ) ); ?>" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="prose-ai-timeout"><?php esc_html_e( 'Request Timeout (seconds)', 'prose-core' ); ?></label></th>
						<td><input name="timeout" id="prose-ai-timeout" type="number" min="5" value="<?php echo esc_attr( (string) ( $all['timeout'] ?? 30 ) ); ?>" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="prose-ai-system-prompt"><?php esc_html_e( 'Custom System Prompt', 'prose-core' ); ?></label></th>
						<td>
							<textarea name="system_prompt" id="prose-ai-system-prompt" class="large-text code" rows="8"><?php echo esc_textarea( (string) ( $all['system_prompt'] ?? '' ) ); ?></textarea>
							<p class="description"><?php esc_html_e( 'Default prompt is used when empty. Append adds to the default; Override replaces it.', 'prose-core' ); ?></p>
							<label>
								<input type="radio" name="system_prompt_mode" value="append" <?php checked( (string) ( $all['system_prompt_mode'] ?? 'append' ), 'append' ); ?> />
								<?php esc_html_e( 'Append to default', 'prose-core' ); ?>
							</label><br />
							<label>
								<input type="radio" name="system_prompt_mode" value="override" <?php checked( (string) ( $all['system_prompt_mode'] ?? 'append' ), 'override' ); ?> />
								<?php esc_html_e( 'Override default', 'prose-core' ); ?>
							</label>
							<details style="margin-top:1em;">
								<summary><?php esc_html_e( 'View default system prompt', 'prose-core' ); ?></summary>
								<pre style="white-space:pre-wrap;"><?php echo esc_html( AI_Settings::DEFAULT_SYSTEM_PROMPT ); ?></pre>
							</details>
						</td>
					</tr>
				</table>

				<?php submit_button( __( 'Save Settings', 'prose-core' ) ); ?>
			</form>

			<form method="post" style="display:inline-block;margin-right:8px;">
				<?php wp_nonce_field( self::NONCE_ACTION ); ?>
				<input type="hidden" name="prose_ai_action" value="test_connection" />
				<?php submit_button( __( 'Test Connection', 'prose-core' ), 'secondary' ); ?>
			</form>

			<form method="post" style="display:inline-block;">
				<?php wp_nonce_field( self::NONCE_ACTION ); ?>
				<input type="hidden" name="prose_ai_action" value="clear_logs" />
				<?php submit_button( __( 'Clear Logs', 'prose-core' ), 'delete' ); ?>
			</form>

			<h2><?php esc_html_e( 'API Status', 'prose-core' ); ?></h2>
			<table class="widefat striped">
				<tbody>
					<tr>
						<th><?php esc_html_e( 'Provider', 'prose-core' ); ?></th>
						<td><?php echo esc_html( (string) ( $all['provider'] ?? 'openai' ) ); ?></td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'API Key', 'prose-core' ); ?></th>
						<td><?php echo esc_html( '' !== $masked_key ? $masked_key : __( 'Not configured (using stub provider)', 'prose-core' ) ); ?></td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Last Request', 'prose-core' ); ?></th>
						<td>
							<?php
							if ( ! empty( $last_request['timestamp'] ) ) {
								echo esc_html(
									sprintf(
										/* translators: 1: type 2: latency 3: time */
										__( 'Type: %1$s | Latency: %2$dms | Time: %3$s', 'prose-core' ),
										(string) ( $last_request['type'] ?? '' ),
										(int) ( $last_request['latency_ms'] ?? 0 ),
										gmdate( 'Y-m-d H:i:s', (int) $last_request['timestamp'] )
									)
								);
							} else {
								esc_html_e( 'None', 'prose-core' );
							}
							?>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Last Error', 'prose-core' ); ?></th>
						<td><?php echo esc_html( (string) ( $last_error['message'] ?? __( 'None', 'prose-core' ) ) ); ?></td>
					</tr>
				</tbody>
			</table>

			<h2><?php esc_html_e( 'Recent Logs', 'prose-core' ); ?></h2>
			<?php if ( empty( $logs ) ) : ?>
				<p><?php esc_html_e( 'No log entries.', 'prose-core' ); ?></p>
			<?php else : ?>
				<table class="widefat striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Time', 'prose-core' ); ?></th>
							<th><?php esc_html_e( 'Type', 'prose-core' ); ?></th>
							<th><?php esc_html_e( 'Latency', 'prose-core' ); ?></th>
							<th><?php esc_html_e( 'Details', 'prose-core' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $logs as $entry ) : ?>
							<tr>
								<td><?php echo esc_html( gmdate( 'Y-m-d H:i:s', (int) ( $entry['timestamp'] ?? 0 ) ) ); ?></td>
								<td><?php echo esc_html( (string) ( $entry['type'] ?? '' ) ); ?></td>
								<td><?php echo esc_html( (string) ( $entry['latency_ms'] ?? '' ) ); ?></td>
								<td><code><?php echo esc_html( wp_json_encode( $entry ) ); ?></code></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}
}
