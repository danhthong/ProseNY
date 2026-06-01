<?php
/**
 * Admin module.
 *
 * @package ProseCore
 */

namespace Prose\Core\Admin;

use Prose\Core\Container;

final class Module {

	public static function boot( Container $container ): void {
		Menu::register();
		add_action( 'admin_init', array( SettingsPage::class, 'register' ) );
		add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue_assets' ) );
	}

	public static function enqueue_assets( string $hook ): void {
		if ( ! str_contains( $hook, 'courtflow' ) ) {
			return;
		}

		wp_enqueue_style(
			'courtflow-admin',
			PROSE_CORE_ASSETS_URL . 'admin/courtflow-admin.css',
			array(),
			PROSE_CORE_VERSION
		);

		wp_enqueue_script(
			'courtflow-admin',
			PROSE_CORE_ASSETS_URL . 'admin/courtflow-admin.js',
			array(),
			PROSE_CORE_VERSION,
			true
		);
	}
}
