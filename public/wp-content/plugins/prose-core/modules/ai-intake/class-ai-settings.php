<?php
/**
 * AI settings — WordPress options wrapper.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Ai_Intake;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class AI_Settings
 */
final class AI_Settings {

	/**
	 * Option key.
	 */
	public const OPTION_KEY = 'prose_ai_settings';

	/**
	 * Default system prompt.
	 */
	public const DEFAULT_SYSTEM_PROMPT = 'You are the ProSeNY intake interpreter. Your job is to extract structured legal intake facts from natural language. Never decide court, workflow, package, or forms. Extract all facts present in each message. Return JSON only. Include confidence (0-1) for each fact. Interpret short answers in the context of pending_field when provided.';

	/**
	 * Cached settings.
	 *
	 * @var array<string, mixed>|null
	 */
	private static ?array $cache = null;

	/**
	 * Get all settings merged with defaults.
	 *
	 * @return array<string, mixed>
	 */
	public function all(): array {
		if ( null !== self::$cache ) {
			return self::$cache;
		}

		$stored = get_option( self::OPTION_KEY, array() );
		$stored = is_array( $stored ) ? $stored : array();

		self::$cache = array_merge( $this->defaults(), $stored );

		return self::$cache;
	}

	/**
	 * Get a single setting.
	 *
	 * @param string $key     Setting key.
	 * @param mixed  $default Default value.
	 * @return mixed
	 */
	public function get( string $key, $default = null ) {
		$all = $this->all();

		return $all[ $key ] ?? $default;
	}

	/**
	 * Update settings.
	 *
	 * @param array<string, mixed> $values Settings to merge.
	 * @return void
	 */
	public function update( array $values ): void {
		$current = $this->all();
		$merged  = array_merge( $current, $values );

		update_option( self::OPTION_KEY, $merged );
		self::$cache = $merged;
	}

	/**
	 * Resolved system prompt for provider requests.
	 *
	 * @return string
	 */
	public function system_prompt(): string {
		$default  = self::DEFAULT_SYSTEM_PROMPT;
		$custom   = trim( (string) $this->get( 'system_prompt', '' ) );
		$mode     = (string) $this->get( 'system_prompt_mode', 'append' );

		if ( '' === $custom ) {
			return $default;
		}

		if ( 'override' === $mode ) {
			return $custom;
		}

		return $default . "\n\n" . $custom;
	}

	/**
	 * Mask an API key for display.
	 *
	 * @param string $key API key.
	 * @return string
	 */
	public function mask_api_key( string $key ): string {
		$key = trim( $key );

		if ( '' === $key ) {
			return '';
		}

		if ( strlen( $key ) <= 8 ) {
			return str_repeat( '*', strlen( $key ) );
		}

		return substr( $key, 0, 4 ) . str_repeat( '*', max( 4, strlen( $key ) - 8 ) ) . substr( $key, -4 );
	}

	/**
	 * Create a provider instance from settings.
	 *
	 * @param Ai_Provider_Interface|null $override Optional provider override (tests).
	 * @return Ai_Provider_Interface
	 */
	public function make_provider( ?Ai_Provider_Interface $override = null ): Ai_Provider_Interface {
		if ( null !== $override ) {
			return $override;
		}

		$provider = (string) $this->get( 'provider', 'openai' );
		$api_key  = (string) $this->get( 'api_key', '' );

		if ( 'openai' === $provider && '' !== trim( $api_key ) ) {
			return new OpenAI_Client( $api_key );
		}

		return new Stub_Ai_Provider();
	}

	/**
	 * Provider request options.
	 *
	 * @return array<string, mixed>
	 */
	public function provider_options(): array {
		return array(
			'model'       => (string) $this->get( 'model', 'gpt-5.5' ),
			'temperature' => (float) $this->get( 'temperature', 0.2 ),
			'max_tokens'  => (int) $this->get( 'max_tokens', 1024 ),
			'timeout'     => (int) $this->get( 'timeout', 30 ),
		);
	}

	/**
	 * Record last request metadata.
	 *
	 * @param array<string, mixed> $meta Metadata.
	 * @return void
	 */
	public function record_request( array $meta ): void {
		$this->update(
			array(
				'last_request' => array_merge(
					array( 'timestamp' => time() ),
					$meta
				),
			)
		);
	}

	/**
	 * Record last error.
	 *
	 * @param string $message Error message.
	 * @return void
	 */
	public function record_error( string $message ): void {
		$this->update(
			array(
				'last_error' => array(
					'message'   => $message,
					'timestamp' => time(),
				),
			)
		);
	}

	/**
	 * Clear settings cache (tests).
	 *
	 * @return void
	 */
	public static function clear_cache(): void {
		self::$cache = null;
	}

	/**
	 * Default settings.
	 *
	 * @return array<string, mixed>
	 */
	private function defaults(): array {
		return array(
			'provider'            => 'openai',
			'api_key'             => '',
			'model'               => 'gpt-5.5',
			'temperature'         => 0.2,
			'max_tokens'          => 1024,
			'timeout'             => 30,
			'system_prompt'       => '',
			'system_prompt_mode'  => 'append',
			'last_request'        => array(),
			'last_error'          => array(),
		);
	}
}
