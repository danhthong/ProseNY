<?php
/**
 * Plugin Name:       Prose Core
 * Plugin URI:        https://prose.ai
 * Description:       CourtFlow AI — procedural workflow engine for NY family court.
 * Version:           0.2.0
 * Requires at least: 6.0
 * Requires PHP:      8.2
 * Author:            Prose
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       prose-core
 *
 * @package ProseCore
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'PROSE_CORE_VERSION', '0.2.0' );
define( 'PROSE_CORE_FILE', __FILE__ );
define( 'PROSE_CORE_PATH', plugin_dir_path( __FILE__ ) );
define( 'PROSE_CORE_URL', plugin_dir_url( __FILE__ ) );
define( 'PROSE_CORE_BASENAME', plugin_basename( __FILE__ ) );
define( 'PROSE_CORE_APP_PATH', PROSE_CORE_PATH . 'app/' );
define( 'PROSE_CORE_DATA_PATH', PROSE_CORE_PATH . 'data/' );
define( 'PROSE_CORE_TEMPLATES_PATH', PROSE_CORE_PATH . 'templates/' );
define( 'PROSE_CORE_ASSETS_URL', PROSE_CORE_URL . 'assets/' );

$prose_core_autoload = PROSE_CORE_PATH . 'vendor/autoload.php';

if ( ! file_exists( $prose_core_autoload ) ) {
	add_action(
		'admin_notices',
		static function (): void {
			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				esc_html__(
					'Prose Core is missing Composer dependencies. Run composer install in the prose-core plugin directory.',
					'prose-core'
				)
			);
		}
	);

	return;
}

require_once $prose_core_autoload;

register_activation_hook( __FILE__, array( 'Prose\\Core\\Activator', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Prose\\Core\\Deactivator', 'deactivate' ) );

add_action( 'plugins_loaded', array( 'Prose\\Core\\Plugin', 'instance' ) );

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once PROSE_CORE_APP_PATH . 'CLI/Commands.php';
}
