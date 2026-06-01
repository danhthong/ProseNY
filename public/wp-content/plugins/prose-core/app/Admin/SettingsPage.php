<?php
/**
 * CourtFlow settings page.
 *
 * @package ProseCore
 */

namespace Prose\Core\Admin;

use Prose\Core\Support\Config;

final class SettingsPage {

	public static function register(): void {
		register_setting( 'courtflow_settings', Config::OPTION_KEY, array( self::class, 'sanitize' ) );
	}

	/**
	 * @param array<string, mixed> $input
	 * @return array<string, mixed>
	 */
	public static function sanitize( array $input ): array {
		return array(
			'openai_api_key'       => sanitize_text_field( $input['openai_api_key'] ?? '' ),
			'openai_model'         => sanitize_text_field( $input['openai_model'] ?? 'gpt-4o-mini' ),
			'openai_org'           => sanitize_text_field( $input['openai_org'] ?? '' ),
			'session_token_budget' => (int) ( $input['session_token_budget'] ?? 50000 ),
			'session_cost_budget'  => (float) ( $input['session_cost_budget'] ?? 2.0 ),
			'disclaimer_text'      => wp_kses_post( $input['disclaimer_text'] ?? Config::default_disclaimer() ),
			'documents_ttl_days'   => (int) ( $input['documents_ttl_days'] ?? 30 ),
			'rate_limit_per_min'   => (int) ( $input['rate_limit_per_min'] ?? 30 ),
		);
	}

	public static function render(): void {
		if ( ! current_user_can( 'cf_admin_settings' ) ) {
			wp_die( esc_html__( 'Unauthorized', 'prose-core' ) );
		}

		$settings = Config::all();
		include PROSE_CORE_PATH . 'templates/admin/settings.php';
	}
}
