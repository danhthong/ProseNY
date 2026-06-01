<?php
/**
 * Plugin deactivation logic.
 *
 * @package ProseCore
 */

namespace Prose\Core;

/**
 * Runs on plugin deactivation.
 */
final class Deactivator {

	/**
	 * Deactivate the plugin.
	 */
	public static function deactivate(): void {
		flush_rewrite_rules();
	}
}
