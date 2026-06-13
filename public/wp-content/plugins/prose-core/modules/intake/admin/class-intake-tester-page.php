<?php
/**
 * Intake Agent Tester — admin debugging interface.
 *
 * Tools -> Intake Agent Tester. Drives Intake_Agent directly (no HTTP hop) and
 * renders the full intake state for debugging multi-turn intake.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Intake\Admin;

use ProSe\Core\Intake\Intake_Agent;
use ProSe\Core\Loader;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Intake_Tester_Page
 */
final class Intake_Tester_Page {

	/**
	 * Menu slug.
	 */
	private const SLUG = 'prose-intake-tester';

	/**
	 * Nonce action.
	 */
	private const NONCE = 'prose_intake_tester';

	/**
	 * Intake agent.
	 *
	 * @var Intake_Agent
	 */
	private Intake_Agent $agent;

	/**
	 * Constructor.
	 *
	 * @param Intake_Agent|null $agent Intake agent.
	 */
	public function __construct( ?Intake_Agent $agent = null ) {
		$this->agent = $agent ?? new Intake_Agent();
	}

	/**
	 * Register hooks.
	 *
	 * @param Loader $loader Hook loader.
	 * @return void
	 */
	public function register( Loader $loader ): void {
		$loader->add_action( 'admin_menu', $this, 'register_page' );
	}

	/**
	 * Register the submenu page under the ProSe menu.
	 *
	 * @return void
	 */
	public function register_page(): void {
		add_submenu_page(
			'prose',
			__( 'Intake Agent Tester', 'prose-core' ),
			__( 'Intake Agent Tester', 'prose-core' ),
			'manage_options',
			self::SLUG,
			array( $this, 'render' )
		);
	}

	/**
	 * Render the tester page.
	 *
	 * @return void
	 */
	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'prose-core' ) );
		}

		$message      = '';
		$case_profile = array();
		$result       = null;

		if ( isset( $_POST['prose_intake_nonce'] ) ) {
			check_admin_referer( self::NONCE, 'prose_intake_nonce' );

			$reset = isset( $_POST['prose_intake_reset'] );

			if ( ! $reset ) {
				$message      = isset( $_POST['prose_intake_message'] )
					? sanitize_textarea_field( wp_unslash( $_POST['prose_intake_message'] ) )
					: '';
				$case_profile = $this->decode_profile(
					isset( $_POST['prose_intake_profile'] ) ? wp_unslash( $_POST['prose_intake_profile'] ) : ''
				);

				$result       = $this->agent->process( $message, $case_profile );
				$case_profile = is_array( $result['case_profile'] ) ? $result['case_profile'] : array();
			}
		}

		$profile_json = wp_json_encode( $case_profile, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
		?>
		<div class="wrap prose-admin-wrap">
			<h1><?php esc_html_e( 'Intake Agent Tester', 'prose-core' ); ?></h1>
			<p class="description">
				<?php esc_html_e( 'Deterministic intake debugging. The case profile is carried across turns so you can simulate a full multi-turn conversation.', 'prose-core' ); ?>
			</p>

			<form method="post" action="">
				<?php wp_nonce_field( self::NONCE, 'prose_intake_nonce' ); ?>
				<input type="hidden" name="prose_intake_profile" value="<?php echo esc_attr( (string) $profile_json ); ?>" />

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">
							<label for="prose_intake_message"><?php esc_html_e( 'User message', 'prose-core' ); ?></label>
						</th>
						<td>
							<textarea
								id="prose_intake_message"
								name="prose_intake_message"
								rows="3"
								class="large-text"
								placeholder="<?php esc_attr_e( 'e.g. I want a divorce and we have two children.', 'prose-core' ); ?>"
							></textarea>
						</td>
					</tr>
				</table>

				<p class="submit">
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Send message', 'prose-core' ); ?></button>
					<button type="submit" name="prose_intake_reset" value="1" class="button"><?php esc_html_e( 'Reset conversation', 'prose-core' ); ?></button>
				</p>
			</form>

			<?php if ( null !== $result ) : ?>
				<?php $this->render_result( (array) $result, $message ); ?>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Render the intake result panels.
	 *
	 * @param array<string, mixed> $result  Intake result.
	 * @param string               $message Submitted message.
	 * @return void
	 */
	private function render_result( array $result, string $message ): void {
		$workflow      = isset( $result['workflow'] ) && '' !== (string) $result['workflow'] ? (string) $result['workflow'] : '—';
		$completion    = (int) ( $result['completion'] ?? 0 );
		$next_question = (string) ( $result['next_question'] ?? '' );
		$conversation  = (string) ( $result['conversation_id'] ?? '' );
		$missing       = (array) ( $result['missing_fields'] ?? array() );
		$extracted     = (array) ( $result['facts_extracted'] ?? array() );
		$profile       = (array) ( $result['case_profile'] ?? array() );
		$facts         = isset( $profile['facts'] ) && is_array( $profile['facts'] ) ? $profile['facts'] : array();
		?>
		<h2><?php esc_html_e( 'Result', 'prose-core' ); ?></h2>
		<table class="widefat striped" style="max-width:900px;">
			<tbody>
				<tr>
					<th style="width:220px;"><?php esc_html_e( 'You said', 'prose-core' ); ?></th>
					<td><?php echo esc_html( $message ); ?></td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Conversation ID', 'prose-core' ); ?></th>
					<td><code><?php echo esc_html( $conversation ); ?></code></td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Workflow', 'prose-core' ); ?></th>
					<td><code><?php echo esc_html( $workflow ); ?></code></td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Completion', 'prose-core' ); ?></th>
					<td><strong><?php echo esc_html( (string) $completion ); ?>%</strong></td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Next question', 'prose-core' ); ?></th>
					<td><?php echo '' !== $next_question ? esc_html( $next_question ) : '<em>' . esc_html__( 'Intake complete', 'prose-core' ) . '</em>'; ?></td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Missing fields', 'prose-core' ); ?></th>
					<td><?php echo $missing ? esc_html( implode( ', ', array_map( 'strval', $missing ) ) ) : '—'; ?></td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Facts extracted (this turn)', 'prose-core' ); ?></th>
					<td><pre style="margin:0;"><?php echo esc_html( (string) wp_json_encode( $extracted, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) ); ?></pre></td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Known facts', 'prose-core' ); ?></th>
					<td><pre style="margin:0;"><?php echo esc_html( (string) wp_json_encode( $facts, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) ); ?></pre></td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Current case profile', 'prose-core' ); ?></th>
					<td><pre style="margin:0;"><?php echo esc_html( (string) wp_json_encode( $profile, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) ); ?></pre></td>
				</tr>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Decode the round-tripped case profile JSON.
	 *
	 * @param string $raw Raw JSON string.
	 * @return array<string, mixed>
	 */
	private function decode_profile( string $raw ): array {
		if ( '' === trim( $raw ) ) {
			return array();
		}

		$decoded = json_decode( $raw, true );

		return is_array( $decoded ) ? $decoded : array();
	}
}
