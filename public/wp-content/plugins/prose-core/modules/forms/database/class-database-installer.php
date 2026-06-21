<?php
/**
 * CourtFlow custom table installer (dbDelta).
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Forms\Database;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Database_Installer
 */
final class Database_Installer {

	/**
	 * Schema version stored in prose_core_db_version.
	 */
	public const DB_VERSION = '1.3.0';

	/**
	 * Option key for schema version.
	 */
	public const VERSION_OPTION = 'prose_core_db_version';

	/**
	 * All custom table suffixes (without prefix).
	 *
	 * @return string[]
	 */
	public static function table_names(): array {
		return array(
			'prose_workflows',
			'prose_workflow_nodes',
			'prose_workflow_edges',
			'prose_routing_rules',
			'prose_deadline_rules',
			'prose_case_deadlines',
			'prose_node_packages',
			'prose_package_forms',
			'prose_package_relations',
			'prose_cases',
			'prose_case_packages',
			'prose_case_forms',
			'prose_case_events',
			'prose_conversations',
			'prose_messages',
			'prose_documents',
		);
	}

	/**
	 * Fully qualified table name.
	 *
	 * @param string $suffix Table suffix.
	 * @return string
	 */
	public static function table( string $suffix ): string {
		global $wpdb;

		return $wpdb->prefix . $suffix;
	}

	/**
	 * Install or upgrade all CourtFlow tables.
	 *
	 * @return void
	 */
	public static function install(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();
		$now     = current_time( 'mysql' );

		$workflows = self::table( 'prose_workflows' );
		$nodes     = self::table( 'prose_workflow_nodes' );
		$edges     = self::table( 'prose_workflow_edges' );
		$routing   = self::table( 'prose_routing_rules' );
		$dl_rules  = self::table( 'prose_deadline_rules' );
		$case_dl   = self::table( 'prose_case_deadlines' );
		$node_pkg  = self::table( 'prose_node_packages' );
		$pkg_forms = self::table( 'prose_package_forms' );
		$pkg_rel   = self::table( 'prose_package_relations' );
		$cases     = self::table( 'prose_cases' );
		$case_pkg  = self::table( 'prose_case_packages' );
		$case_form = self::table( 'prose_case_forms' );
		$case_evt  = self::table( 'prose_case_events' );
		$conv      = self::table( 'prose_conversations' );
		$msgs      = self::table( 'prose_messages' );
		$user_docs = self::table( 'prose_documents' );

		dbDelta(
			"CREATE TABLE {$workflows} (
				workflow_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				workflow_key varchar(64) NOT NULL,
				workflow_name varchar(191) NOT NULL DEFAULT '',
				court_routing varchar(32) NOT NULL DEFAULT '',
				description text NULL,
				is_active tinyint(1) NOT NULL DEFAULT 1,
				sort_order int(11) NOT NULL DEFAULT 0,
				created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
				updated_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
				PRIMARY KEY  (workflow_id),
				UNIQUE KEY uq_workflow_key (workflow_key),
				KEY idx_active_sort (is_active, sort_order),
				KEY idx_routing (court_routing)
			) {$charset};"
		);

		dbDelta(
			"CREATE TABLE {$nodes} (
				node_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				node_key varchar(64) NOT NULL,
				workflow_key varchar(64) NOT NULL DEFAULT '',
				stage varchar(64) NOT NULL DEFAULT '',
				court_routing varchar(32) NOT NULL DEFAULT '',
				node_type varchar(32) NOT NULL DEFAULT '',
				label varchar(191) NOT NULL DEFAULT '',
				primary_package_id bigint(20) unsigned NULL,
				responsible_party varchar(32) NOT NULL DEFAULT '',
				instructions text NULL,
				trigger_events longtext NULL,
				completion_events longtext NULL,
				sequence int(11) NOT NULL DEFAULT 0,
				is_entry tinyint(1) NOT NULL DEFAULT 0,
				is_terminal tinyint(1) NOT NULL DEFAULT 0,
				status varchar(20) NOT NULL DEFAULT 'active',
				created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
				updated_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
				PRIMARY KEY  (node_id),
				UNIQUE KEY uq_node_key (node_key),
				KEY idx_workflow (workflow_key),
				KEY idx_workflow_stage (workflow_key, stage),
				KEY idx_routing (court_routing),
				KEY idx_package (primary_package_id)
			) {$charset};"
		);

		dbDelta(
			"CREATE TABLE {$edges} (
				edge_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				from_node_id bigint(20) unsigned NOT NULL,
				to_node_id bigint(20) unsigned NOT NULL,
				workflow_key varchar(64) NOT NULL DEFAULT '',
				edge_type varchar(32) NOT NULL DEFAULT 'next',
				condition_key varchar(64) NOT NULL DEFAULT '',
				condition_data longtext NULL,
				label varchar(191) NOT NULL DEFAULT '',
				sequence int(11) NOT NULL DEFAULT 0,
				weight int(11) NOT NULL DEFAULT 0,
				status varchar(20) NOT NULL DEFAULT 'active',
				created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
				updated_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
				PRIMARY KEY  (edge_id),
				UNIQUE KEY uq_edge (from_node_id, to_node_id, edge_type, condition_key),
				KEY idx_from (from_node_id, sequence),
				KEY idx_to (to_node_id),
				KEY idx_workflow (workflow_key)
			) {$charset};"
		);

		dbDelta(
			"CREATE TABLE {$routing} (
				rule_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				rule_key varchar(64) NOT NULL DEFAULT '',
				scope varchar(20) NOT NULL DEFAULT '',
				scope_ref varchar(64) NOT NULL DEFAULT '',
				county varchar(32) NOT NULL DEFAULT '',
				court_routing varchar(32) NOT NULL DEFAULT '',
				rule_type varchar(32) NOT NULL DEFAULT '',
				match_conditions longtext NULL,
				rule_data longtext NULL,
				priority int(11) NOT NULL DEFAULT 0,
				effective_from date NULL,
				effective_to date NULL,
				status varchar(20) NOT NULL DEFAULT 'active',
				description text NULL,
				created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
				updated_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
				PRIMARY KEY  (rule_id),
				KEY idx_scope (scope, scope_ref),
				KEY idx_county (county),
				KEY idx_routing (court_routing),
				KEY idx_type (rule_type),
				KEY idx_priority (priority)
			) {$charset};"
		);

		dbDelta(
			"CREATE TABLE {$dl_rules} (
				deadline_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				deadline_key varchar(64) NOT NULL DEFAULT '',
				workflow_key varchar(64) NOT NULL DEFAULT '',
				node_id bigint(20) unsigned NULL,
				trigger_event varchar(64) NOT NULL DEFAULT '',
				offset_days int(11) NOT NULL DEFAULT 0,
				day_type varchar(12) NOT NULL DEFAULT 'calendar',
				direction varchar(8) NOT NULL DEFAULT 'after',
				deadline_kind varchar(16) NOT NULL DEFAULT 'hard',
				applies_scope varchar(20) NOT NULL DEFAULT 'node',
				applies_ref varchar(64) NOT NULL DEFAULT '',
				county varchar(32) NOT NULL DEFAULT '',
				statute_ref varchar(128) NOT NULL DEFAULT '',
				label varchar(191) NOT NULL DEFAULT '',
				description text NULL,
				priority int(11) NOT NULL DEFAULT 0,
				status varchar(20) NOT NULL DEFAULT 'active',
				created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
				updated_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
				PRIMARY KEY  (deadline_id),
				UNIQUE KEY uq_deadline_key (deadline_key),
				KEY idx_node (node_id),
				KEY idx_trigger (trigger_event),
				KEY idx_workflow (workflow_key),
				KEY idx_applies (applies_scope, applies_ref),
				KEY idx_county (county)
			) {$charset};"
		);

		dbDelta(
			"CREATE TABLE {$case_dl} (
				case_deadline_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				case_id bigint(20) unsigned NOT NULL,
				workflow_key varchar(64) NOT NULL DEFAULT '',
				node_id bigint(20) unsigned NULL,
				deadline_rule_id bigint(20) unsigned NOT NULL,
				title varchar(191) NOT NULL DEFAULT '',
				due_date datetime NOT NULL,
				completed tinyint(1) NOT NULL DEFAULT 0,
				completed_at datetime NULL,
				source_event varchar(64) NOT NULL DEFAULT '',
				source_event_date datetime NULL,
				day_type varchar(12) NOT NULL DEFAULT 'calendar',
				status varchar(20) NOT NULL DEFAULT 'pending',
				county varchar(32) NOT NULL DEFAULT '',
				created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
				updated_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
				PRIMARY KEY  (case_deadline_id),
				UNIQUE KEY uq_case_rule_event (case_id, deadline_rule_id, source_event),
				KEY idx_case_due (case_id, due_date),
				KEY idx_due (due_date, completed),
				KEY idx_rule (deadline_rule_id),
				KEY idx_node (node_id)
			) {$charset};"
		);

		dbDelta(
			"CREATE TABLE {$node_pkg} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				node_id bigint(20) unsigned NOT NULL,
				package_key varchar(64) NOT NULL DEFAULT '',
				package_id bigint(20) unsigned NULL,
				role varchar(20) NOT NULL DEFAULT 'satisfies',
				sequence int(11) NOT NULL DEFAULT 0,
				PRIMARY KEY  (id),
				UNIQUE KEY uq_node_pkg (node_id, package_key, role),
				KEY idx_node (node_id, sequence),
				KEY idx_pkg_key (package_key),
				KEY idx_pkg (package_id)
			) {$charset};"
		);

		dbDelta(
			"CREATE TABLE {$pkg_forms} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				package_id bigint(20) unsigned NOT NULL,
				form_id bigint(20) unsigned NULL,
				form_code varchar(64) NOT NULL DEFAULT '',
				requirement varchar(16) NOT NULL DEFAULT 'required',
				condition_key varchar(64) NOT NULL DEFAULT '',
				sequence int(11) NOT NULL DEFAULT 0,
				PRIMARY KEY  (id),
				UNIQUE KEY uq_pkg_form (package_id, form_code, requirement),
				KEY idx_pkg (package_id, sequence),
				KEY idx_form (form_id),
				KEY idx_code (form_code)
			) {$charset};"
		);

		dbDelta(
			"CREATE TABLE {$pkg_rel} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				from_package_key varchar(64) NOT NULL DEFAULT '',
				to_package_key varchar(64) NOT NULL DEFAULT '',
				from_package_id bigint(20) unsigned NULL,
				to_package_id bigint(20) unsigned NULL,
				relation_type varchar(20) NOT NULL DEFAULT 'next',
				condition_key varchar(64) NOT NULL DEFAULT '',
				condition_data longtext NULL,
				sequence int(11) NOT NULL DEFAULT 0,
				PRIMARY KEY  (id),
				UNIQUE KEY uq_rel (from_package_key, to_package_key, relation_type, condition_key),
				KEY idx_from (from_package_key, relation_type, sequence),
				KEY idx_to (to_package_key, relation_type)
			) {$charset};"
		);

		dbDelta(
			"CREATE TABLE {$cases} (
				case_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				workflow_key varchar(64) NOT NULL DEFAULT '',
				court_routing varchar(32) NOT NULL DEFAULT '',
				county varchar(32) NOT NULL DEFAULT '',
				current_node varchar(64) NOT NULL DEFAULT '',
				current_package varchar(64) NOT NULL DEFAULT '',
				progress_percentage int(11) NOT NULL DEFAULT 0,
				status varchar(20) NOT NULL DEFAULT 'active',
				title varchar(191) NOT NULL DEFAULT '',
				user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				answers longtext NULL,
				opened_at datetime NULL,
				closed_at datetime NULL,
				created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
				updated_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
				PRIMARY KEY  (case_id),
				KEY idx_workflow (workflow_key),
				KEY idx_status (status),
				KEY idx_user (user_id),
				KEY idx_node (current_node)
			) {$charset};"
		);

		dbDelta(
			"CREATE TABLE {$case_pkg} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				case_id bigint(20) unsigned NOT NULL,
				package_key varchar(64) NOT NULL DEFAULT '',
				package_id bigint(20) unsigned NULL,
				state varchar(20) NOT NULL DEFAULT 'LOCKED',
				sequence int(11) NOT NULL DEFAULT 0,
				completed_at datetime NULL,
				created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
				updated_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
				PRIMARY KEY  (id),
				UNIQUE KEY uq_case_pkg (case_id, package_key),
				KEY idx_case (case_id, sequence),
				KEY idx_state (state),
				KEY idx_pkg_key (package_key)
			) {$charset};"
		);

		dbDelta(
			"CREATE TABLE {$case_form} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				case_id bigint(20) unsigned NOT NULL,
				package_key varchar(64) NOT NULL DEFAULT '',
				form_code varchar(64) NOT NULL DEFAULT '',
				form_id bigint(20) unsigned NULL,
				requirement varchar(16) NOT NULL DEFAULT 'required',
				status varchar(20) NOT NULL DEFAULT 'pending',
				completed_at datetime NULL,
				created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
				updated_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
				PRIMARY KEY  (id),
				UNIQUE KEY uq_case_form (case_id, package_key, form_code),
				KEY idx_case (case_id),
				KEY idx_form (form_id),
				KEY idx_status (status)
			) {$charset};"
		);

		dbDelta(
			"CREATE TABLE {$case_evt} (
				event_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				case_id bigint(20) unsigned NOT NULL,
				event_type varchar(64) NOT NULL DEFAULT '',
				from_node varchar(64) NOT NULL DEFAULT '',
				to_node varchar(64) NOT NULL DEFAULT '',
				payload longtext NULL,
				occurred_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
				created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
				PRIMARY KEY  (event_id),
				KEY idx_case_time (case_id, occurred_at),
				KEY idx_type (event_type)
			) {$charset};"
		);

		dbDelta(
			"CREATE TABLE {$conv} (
				conversation_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				case_id bigint(20) unsigned NULL,
				session_id varchar(36) NOT NULL DEFAULT '',
				title varchar(191) NOT NULL DEFAULT '',
				status varchar(20) NOT NULL DEFAULT 'active',
				context_json longtext NULL,
				created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
				updated_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
				PRIMARY KEY  (conversation_id),
				UNIQUE KEY uq_session (session_id),
				KEY idx_user (user_id, updated_at),
				KEY idx_case (case_id)
			) {$charset};"
		);

		dbDelta(
			"CREATE TABLE {$msgs} (
				message_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				conversation_id bigint(20) unsigned NOT NULL,
				role varchar(16) NOT NULL DEFAULT 'user',
				content longtext NOT NULL,
				sequence int(11) NOT NULL DEFAULT 0,
				created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
				PRIMARY KEY  (message_id),
				KEY idx_conversation (conversation_id, sequence)
			) {$charset};"
		);

		dbDelta(
			"CREATE TABLE {$user_docs} (
				document_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				case_id bigint(20) unsigned NOT NULL DEFAULT 0,
				conversation_id bigint(20) unsigned NULL,
				document_type varchar(32) NOT NULL DEFAULT 'generated_pdf',
				form_code varchar(64) NOT NULL DEFAULT '',
				title varchar(191) NOT NULL DEFAULT '',
				file_path varchar(255) NOT NULL DEFAULT '',
				download_token varchar(64) NOT NULL DEFAULT '',
				status varchar(20) NOT NULL DEFAULT 'pending',
				created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
				updated_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
				PRIMARY KEY  (document_id),
				KEY idx_user (user_id, created_at),
				KEY idx_case (case_id),
				KEY idx_token (download_token)
			) {$charset};"
		);

		update_option( self::VERSION_OPTION, self::DB_VERSION );

		// Silence unused variable for static analysis.
		unset( $now );
	}

	/**
	 * Run install when schema version is behind.
	 *
	 * @return void
	 */
	public static function maybe_upgrade(): void {
		$installed = get_option( self::VERSION_OPTION, '' );

		if ( version_compare( (string) $installed, self::DB_VERSION, '>=' ) ) {
			return;
		}

		self::install();
	}

	/**
	 * Whether CourtFlow graph tables are installed and current.
	 *
	 * @return bool
	 */
	public static function is_ready(): bool {
		global $wpdb;

		$installed = get_option( self::VERSION_OPTION, '' );

		if ( version_compare( (string) $installed, self::DB_VERSION, '<' ) ) {
			return false;
		}

		$table = self::table( 'prose_workflows' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );

		return $found === $table;
	}

	/**
	 * Drop all CourtFlow custom tables (destructive).
	 *
	 * @return void
	 */
	public static function drop_tables(): void {
		global $wpdb;

		foreach ( self::table_names() as $suffix ) {
			$table = self::table( $suffix );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
		}

		delete_option( self::VERSION_OPTION );
	}
}
