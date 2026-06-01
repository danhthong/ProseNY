<?php
/**
 * Database schema manager.
 *
 * @package ProseCore
 */

namespace Prose\Core\Database;

use Prose\Core\Database\Migrations\Migration001Initial;

/**
 * Runs versioned migrations via dbDelta.
 */
final class Schema {

	public const DB_VERSION_OPTION = 'courtflow_db_version';
	public const CURRENT_VERSION   = 1;
	public const LOCK_OPTION         = 'courtflow_db_migrating';

	/**
	 * Ensure all migrations are applied.
	 */
	public static function ensure(): void {
		$current = (int) get_option( self::DB_VERSION_OPTION, 0 );

		if ( $current >= self::CURRENT_VERSION ) {
			return;
		}

		if ( get_transient( self::LOCK_OPTION ) ) {
			return;
		}

		set_transient( self::LOCK_OPTION, 1, 60 );

		try {
			if ( $current < 1 ) {
				Migration001Initial::up();
			}

			update_option( self::DB_VERSION_OPTION, self::CURRENT_VERSION );
			update_option( 'prose_core_version', PROSE_CORE_VERSION );
		} finally {
			delete_transient( self::LOCK_OPTION );
		}
	}

	/**
	 * Drop all custom tables (uninstall).
	 */
	public static function drop_all(): void {
		Migration001Initial::down();
		delete_option( self::DB_VERSION_OPTION );
	}
}
