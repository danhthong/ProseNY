<?php
/**
 * Structured logger (Monolog-compatible interface).
 *
 * @package ProseCore
 */

namespace Prose\Core\Observability;

final class Logger {

	/**
	 * @param array<string, mixed> $context
	 */
	public function info( string $message, array $context = array() ): void {
		$this->log( 'info', $message, $context );
	}

	/**
	 * @param array<string, mixed> $context
	 */
	public function error( string $message, array $context = array() ): void {
		$this->log( 'error', $message, $context );
	}

	/**
	 * @param array<string, mixed> $context
	 */
	private function log( string $level, string $message, array $context ): void {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( sprintf( '[CourtFlow:%s] %s %s', $level, $message, wp_json_encode( $context ) ) );
		}
	}
}
