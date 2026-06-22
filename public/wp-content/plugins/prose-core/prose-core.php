<?php
/**
 * Plugin Name:       ProSe Core
 * Plugin URI:        https://prose.ai
 * Description:       ProSe platform foundation — court forms, workflows, and intake automation.
 * Version:           1.0.17
 * Requires at least: 6.0
 * Requires PHP:      8.0
 * Author:            ProSe
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       prose-core
 *
 * @package ProSeCore
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( version_compare( PHP_VERSION, '8.0', '<' ) ) {
	add_action(
		'admin_notices',
		static function (): void {
			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				esc_html(
					sprintf(
						/* translators: %s: required PHP version */
						__( 'ProSe Core requires PHP %s or newer. The import tools will not work until PHP is upgraded.', 'prose-core' ),
						'8.0'
					)
				)
			);
		}
	);
	return;
}

define( 'PROSE_CORE_VERSION', '1.0.17' );
define( 'PROSE_CORE_FILE', __FILE__ );
define( 'PROSE_CORE_PATH', plugin_dir_path( __FILE__ ) );
define( 'PROSE_CORE_URL', plugin_dir_url( __FILE__ ) );
define( 'PROSE_CORE_BASENAME', plugin_basename( __FILE__ ) );

if ( ! function_exists( 'prose_core_asset_version' ) ) {
	/**
	 * Cache-busting version for a plugin asset (file mtime, else plugin version).
	 *
	 * @param string $relative_path Path relative to the plugin root.
	 * @return string
	 */
	function prose_core_asset_version( string $relative_path ): string {
		$path = PROSE_CORE_PATH . ltrim( $relative_path, '/' );

		if ( file_exists( $path ) ) {
			return (string) filemtime( $path );
		}

		return PROSE_CORE_VERSION;
	}
}

require_once PROSE_CORE_PATH . 'includes/class-autoloader.php';

ProSe\Core\Autoloader::register();

register_activation_hook( __FILE__, array( 'ProSe\\Core\\Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'ProSe\\Core\\Plugin', 'deactivate' ) );

add_action( 'plugins_loaded', array( 'ProSe\\Core\\Plugin', 'instance' ) );
