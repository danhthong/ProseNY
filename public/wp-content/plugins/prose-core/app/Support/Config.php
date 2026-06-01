<?php
/**
 * Plugin configuration accessor.
 *
 * @package ProseCore
 */

namespace Prose\Core\Support;

/**
 * Reads CourtFlow settings from wp_options and environment.
 */
final class Config {

	public const OPTION_KEY = 'courtflow_settings';

	/**
	 * @return array<string, mixed>
	 */
	public static function all(): array {
		$defaults = array(
			'openai_api_key'       => '',
			'openai_model'         => 'gpt-4o-mini',
			'openai_org'           => '',
			'pii_secret'           => '',
			'session_token_budget' => 50000,
			'session_cost_budget'  => 2.0,
			'disclaimer_text'      => self::default_disclaimer(),
			'documents_ttl_days'   => 30,
			'rate_limit_per_min'   => 30,
		);

		$stored = get_option( self::OPTION_KEY, array() );

		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		$merged = array_merge( $defaults, $stored );

		if ( defined( 'COURTFLOW_OPENAI_API_KEY' ) && COURTFLOW_OPENAI_API_KEY ) {
			$merged['openai_api_key'] = COURTFLOW_OPENAI_API_KEY;
		}

		if ( defined( 'COURTFLOW_PII_SECRET' ) && COURTFLOW_PII_SECRET ) {
			$merged['pii_secret'] = COURTFLOW_PII_SECRET;
		}

		return $merged;
	}

	public static function get( string $key, mixed $default = null ): mixed {
		$all = self::all();
		return $all[ $key ] ?? $default;
	}

	public static function default_disclaimer(): string {
		return __(
			'CourtFlow AI provides procedural information and document assistance only. It is not a law firm and does not provide legal advice. For legal advice, consult a licensed attorney.',
			'prose-core'
		);
	}

	public static function table( string $name ): string {
		global $wpdb;
		return $wpdb->prefix . 'cf_' . $name;
	}
}
