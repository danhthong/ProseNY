<?php
/**
 * Plugin activation logic.
 *
 * @package ProseCore
 */

namespace Prose\Core;

use Prose\Core\Database\Schema;
use Prose\Core\Security\Capabilities;
use Throwable;

/**
 * Runs on plugin activation.
 *
 * Keeps activation minimal and never fatal — heavy work (seeding, CPT-dependent
 * inserts) defers to admin_init after WordPress is fully bootstrapped.
 */
final class Activator {

	public static function activate(): void {
		try {
			Schema::ensure();
			Capabilities::register();
			update_option( 'courtflow_needs_seed', 1, false );
			update_option( 'prose_core_version', PROSE_CORE_VERSION, false );
			flush_rewrite_rules();
		} catch ( Throwable $e ) {
			// Never block activation on a non-fatal error; log for debugging.
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log( '[CourtFlow] Activation soft-error: ' . $e->getMessage() );
			}
		}
	}
}
