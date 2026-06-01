<?php
/**
 * CourtFlow admin menu.
 *
 * @package ProseCore
 */

namespace Prose\Core\Admin;

final class Menu {

	public static function register(): void {
		add_action( 'admin_menu', array( self::class, 'add_menus' ) );
	}

	public static function add_menus(): void {
		add_menu_page(
			__( 'CourtFlow', 'prose-core' ),
			__( 'CourtFlow', 'prose-core' ),
			'cf_admin_workflows',
			'courtflow',
			array( DashboardPage::class, 'render' ),
			'dashicons-clipboard',
			30
		);

		add_submenu_page(
			'courtflow',
			__( 'Dashboard', 'prose-core' ),
			__( 'Dashboard', 'prose-core' ),
			'cf_admin_workflows',
			'courtflow',
			array( DashboardPage::class, 'render' )
		);

		add_submenu_page(
			'courtflow',
			__( 'Procedural Rules', 'prose-core' ),
			__( 'Procedural Rules', 'prose-core' ),
			'cf_admin_rules',
			'courtflow-rules',
			array( RulesPage::class, 'render' )
		);

		add_submenu_page(
			'courtflow',
			__( 'Official Forms', 'prose-core' ),
			__( 'Official Forms', 'prose-core' ),
			'cf_admin_forms',
			'courtflow-forms',
			array( OfficialFormsPage::class, 'render' )
		);

		add_submenu_page(
			'courtflow',
			__( 'Form Field Mappings', 'prose-core' ),
			__( 'Field Mappings', 'prose-core' ),
			'cf_admin_forms',
			'courtflow-mappings',
			array( FieldMappingsPage::class, 'render' )
		);

		add_submenu_page(
			'courtflow',
			__( 'Intake Sessions', 'prose-core' ),
			__( 'Sessions', 'prose-core' ),
			'cf_admin_sessions',
			'courtflow-sessions',
			array( SessionsPage::class, 'render' )
		);

		add_submenu_page(
			'courtflow',
			__( 'AI Audit Log', 'prose-core' ),
			__( 'AI Audit Log', 'prose-core' ),
			'cf_admin_audit',
			'courtflow-audit',
			array( AuditPage::class, 'render' )
		);

		add_submenu_page(
			'courtflow',
			__( 'Settings', 'prose-core' ),
			__( 'Settings', 'prose-core' ),
			'cf_admin_settings',
			'courtflow-settings',
			array( SettingsPage::class, 'render' )
		);
	}
}
