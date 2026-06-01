<?php
/**
 * Fired when the plugin is uninstalled.
 *
 * @package ProseCore
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

require_once plugin_dir_path( __FILE__ ) . 'vendor/autoload.php';

\Prose\Core\Database\Schema::drop_all();

delete_option( 'prose_core_version' );
delete_option( 'courtflow_db_version' );
delete_option( 'courtflow_settings' );
delete_option( 'courtflow_seeded' );
