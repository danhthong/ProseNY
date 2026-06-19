<?php
/**
 * Basic audit log — file append with transient ring buffer.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Security;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Audit_Log
 */
final class Audit_Log {

	/**
	 * Transient key for recent entries.
	 */
	private const RECENT_KEY = 'prose_audit_recent';

	/**
	 * Maximum recent entries kept in a transient.
	 */
	private const RECENT_LIMIT = 100;

	/**
	 * Log directory under uploads.
	 */
	private const UPLOAD_SUBDIR = 'prose/audit';

	/**
	 * Record an audit event.
	 *
	 * @param string               $event   Event name.
	 * @param array<string, mixed> $context Optional context (PII should be omitted by callers).
	 * @return void
	 */
	public function log( string $event, array $context = array() ): void {
		$entry = array(
			'event'      => sanitize_key( $event ),
			'timestamp'  => gmdate( 'c' ),
			'user_id'    => get_current_user_id(),
			'context'    => $this->sanitize_context( $context ),
		);

		$this->append_file( $entry );
		$this->push_recent( $entry );

		/**
		 * Fires after an audit log entry is recorded.
		 *
		 * @param array<string, mixed> $entry Log entry.
		 */
		do_action( 'prose_audit_logged', $entry );
	}

	/**
	 * Recent audit entries from the transient ring buffer.
	 *
	 * @param int $limit Max entries.
	 * @return array<int, array<string, mixed>>
	 */
	public function recent( int $limit = 50 ): array {
		$stored = get_transient( self::RECENT_KEY );

		if ( ! is_array( $stored ) ) {
			return array();
		}

		return array_slice( $stored, 0, max( 1, $limit ) );
	}

	/**
	 * Append an entry to the audit log file.
	 *
	 * @param array<string, mixed> $entry Log entry.
	 * @return void
	 */
	private function append_file( array $entry ): void {
		$path = $this->log_path();

		if ( '' === $path ) {
			return;
		}

		$dir = dirname( $path );

		if ( ! is_dir( $dir ) && function_exists( 'wp_mkdir_p' ) ) {
			wp_mkdir_p( $dir );
		}

		$line = (string) wp_json_encode( $entry ) . PHP_EOL;
		file_put_contents( $path, $line, FILE_APPEND | LOCK_EX ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
	}

	/**
	 * Push an entry into the transient ring buffer.
	 *
	 * @param array<string, mixed> $entry Log entry.
	 * @return void
	 */
	private function push_recent( array $entry ): void {
		$stored = get_transient( self::RECENT_KEY );
		$stored = is_array( $stored ) ? $stored : array();

		array_unshift( $stored, $entry );
		$stored = array_slice( $stored, 0, self::RECENT_LIMIT );

		set_transient( self::RECENT_KEY, $stored, DAY_IN_SECONDS );
	}

	/**
	 * Resolve the audit log file path.
	 *
	 * @return string
	 */
	private function log_path(): string {
		if ( ! function_exists( 'wp_upload_dir' ) ) {
			return '';
		}

		$uploads = wp_upload_dir();

		if ( empty( $uploads['basedir'] ) ) {
			return '';
		}

		$date = gmdate( 'Y-m' );

		return trailingslashit( $uploads['basedir'] ) . self::UPLOAD_SUBDIR . '/audit-' . $date . '.log';
	}

	/**
	 * Sanitize context values for logging.
	 *
	 * @param array<string, mixed> $context Raw context.
	 * @return array<string, mixed>
	 */
	private function sanitize_context( array $context ): array {
		$clean = array();

		foreach ( $context as $key => $value ) {
			$key = sanitize_key( (string) $key );

			if ( is_scalar( $value ) ) {
				$clean[ $key ] = sanitize_text_field( (string) $value );
			} elseif ( is_array( $value ) ) {
				$clean[ $key ] = $this->sanitize_context( $value );
			}
		}

		return $clean;
	}
}
