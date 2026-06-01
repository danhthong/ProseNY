<?php
/**
 * Initial CourtFlow database schema.
 *
 * @package ProseCore
 */

namespace Prose\Core\Database\Migrations;

use Prose\Core\Support\Config;

/**
 * Creates all wp_cf_* tables.
 */
final class Migration001Initial {

	public static function up(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();

		$tables = array(
			self::user_cases( $charset ),
			self::intake_sessions( $charset ),
			self::session_facts( $charset ),
			self::session_events( $charset ),
			self::workflow_nodes( $charset ),
			self::workflow_transitions( $charset ),
			self::rules( $charset ),
			self::validation_rules( $charset ),
			self::form_field_mappings( $charset ),
			self::generated_documents( $charset ),
			self::ai_audit_log( $charset ),
			self::dead_letter( $charset ),
		);

		foreach ( $tables as $sql ) {
			dbDelta( $sql );
		}
	}

	public static function down(): void {
		global $wpdb;

		$tables = array(
			'dead_letter',
			'ai_audit_log',
			'generated_documents',
			'form_field_mappings',
			'validation_rules',
			'rules',
			'workflow_transitions',
			'workflow_nodes',
			'session_events',
			'session_facts',
			'intake_sessions',
			'user_cases',
		);

		foreach ( $tables as $table ) {
			$wpdb->query( 'DROP TABLE IF EXISTS ' . Config::table( $table ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}
	}

	private static function user_cases( string $charset ): string {
		$t = Config::table( 'user_cases' );
		return "CREATE TABLE {$t} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			user_id bigint(20) unsigned NOT NULL,
			case_type varchar(64) NOT NULL DEFAULT 'divorce',
			status varchar(32) NOT NULL DEFAULT 'active',
			county_id bigint(20) unsigned DEFAULT NULL,
			court_id bigint(20) unsigned DEFAULT NULL,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY user_id (user_id),
			KEY status (status)
		) {$charset};";
	}

	private static function intake_sessions( string $charset ): string {
		$t = Config::table( 'intake_sessions' );
		return "CREATE TABLE {$t} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			case_id bigint(20) unsigned NOT NULL,
			user_id bigint(20) unsigned NOT NULL,
			status varchar(32) NOT NULL DEFAULT 'active',
			workflow_id bigint(20) unsigned DEFAULT NULL,
			current_node_id bigint(20) unsigned DEFAULT NULL,
			rule_version int unsigned NOT NULL DEFAULT 1,
			advance_key varchar(64) DEFAULT NULL,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY case_id (case_id),
			KEY user_id (user_id),
			KEY status (status)
		) {$charset};";
	}

	private static function session_facts( string $charset ): string {
		$t = Config::table( 'session_facts' );
		return "CREATE TABLE {$t} (
			session_id bigint(20) unsigned NOT NULL,
			facts longtext NOT NULL,
			facts_encrypted tinyint(1) NOT NULL DEFAULT 0,
			facts_version int unsigned NOT NULL DEFAULT 1,
			summary longtext DEFAULT NULL,
			updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (session_id)
		) {$charset};";
	}

	private static function session_events( string $charset ): string {
		$t = Config::table( 'session_events' );
		return "CREATE TABLE {$t} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			session_id bigint(20) unsigned NOT NULL,
			event_type varchar(64) NOT NULL,
			actor varchar(32) NOT NULL DEFAULT 'system',
			payload longtext NOT NULL,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY session_id (session_id),
			KEY event_type (event_type),
			KEY created_at (created_at)
		) {$charset};";
	}

	private static function workflow_nodes( string $charset ): string {
		$t = Config::table( 'workflow_nodes' );
		return "CREATE TABLE {$t} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			workflow_id bigint(20) unsigned NOT NULL,
			slug varchar(128) NOT NULL,
			node_type varchar(64) NOT NULL DEFAULT 'intake_question',
			title varchar(255) NOT NULL DEFAULT '',
			config longtext NOT NULL,
			sort_order int NOT NULL DEFAULT 0,
			PRIMARY KEY (id),
			UNIQUE KEY workflow_slug (workflow_id, slug),
			KEY workflow_id (workflow_id)
		) {$charset};";
	}

	private static function workflow_transitions( string $charset ): string {
		$t = Config::table( 'workflow_transitions' );
		return "CREATE TABLE {$t} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			workflow_id bigint(20) unsigned NOT NULL,
			from_node_id bigint(20) unsigned NOT NULL,
			to_node_id bigint(20) unsigned NOT NULL,
			condition_json longtext NOT NULL,
			priority int NOT NULL DEFAULT 0,
			PRIMARY KEY (id),
			KEY workflow_id (workflow_id),
			KEY from_node (from_node_id)
		) {$charset};";
	}

	private static function rules( string $charset ): string {
		$t = Config::table( 'rules' );
		return "CREATE TABLE {$t} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			workflow_id bigint(20) unsigned DEFAULT NULL,
			slug varchar(128) NOT NULL,
			priority int NOT NULL DEFAULT 100,
			conditions longtext NOT NULL,
			actions longtext NOT NULL,
			version int unsigned NOT NULL DEFAULT 1,
			enabled tinyint(1) NOT NULL DEFAULT 1,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			UNIQUE KEY slug_version (slug, version),
			KEY workflow_priority (workflow_id, priority)
		) {$charset};";
	}

	private static function validation_rules( string $charset ): string {
		$t = Config::table( 'validation_rules' );
		return "CREATE TABLE {$t} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			scope varchar(64) NOT NULL DEFAULT 'global',
			slug varchar(128) NOT NULL,
			expr longtext NOT NULL,
			severity varchar(16) NOT NULL DEFAULT 'error',
			message text NOT NULL,
			enabled tinyint(1) NOT NULL DEFAULT 1,
			PRIMARY KEY (id),
			UNIQUE KEY scope_slug (scope, slug)
		) {$charset};";
	}

	private static function form_field_mappings( string $charset ): string {
		$t = Config::table( 'form_field_mappings' );
		return "CREATE TABLE {$t} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			form_id bigint(20) unsigned NOT NULL,
			field_name varchar(255) NOT NULL,
			source_path varchar(255) NOT NULL,
			transform longtext DEFAULT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY form_field (form_id, field_name)
		) {$charset};";
	}

	private static function generated_documents( string $charset ): string {
		$t = Config::table( 'generated_documents' );
		return "CREATE TABLE {$t} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			session_id bigint(20) unsigned NOT NULL,
			form_slug varchar(64) NOT NULL,
			storage_path varchar(512) NOT NULL,
			file_hash varchar(64) NOT NULL DEFAULT '',
			signed_url_expires_at datetime DEFAULT NULL,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY session_id (session_id),
			KEY file_hash (file_hash)
		) {$charset};";
	}

	private static function ai_audit_log( string $charset ): string {
		$t = Config::table( 'ai_audit_log' );
		return "CREATE TABLE {$t} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			session_id bigint(20) unsigned DEFAULT NULL,
			case_id bigint(20) unsigned DEFAULT NULL,
			agent varchar(64) NOT NULL,
			provider varchar(32) NOT NULL,
			model varchar(64) NOT NULL,
			prompt_hash varchar(64) NOT NULL DEFAULT '',
			redacted_input longtext NOT NULL,
			redacted_output longtext NOT NULL,
			tokens_in int unsigned NOT NULL DEFAULT 0,
			tokens_out int unsigned NOT NULL DEFAULT 0,
			cost_usd decimal(10,6) NOT NULL DEFAULT 0,
			latency_ms int unsigned NOT NULL DEFAULT 0,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY session_id (session_id),
			KEY case_id (case_id),
			KEY created_at (created_at)
		) {$charset};";
	}

	private static function dead_letter( string $charset ): string {
		$t = Config::table( 'dead_letter' );
		return "CREATE TABLE {$t} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			job_name varchar(128) NOT NULL,
			payload longtext NOT NULL,
			error_message text NOT NULL,
			attempts int unsigned NOT NULL DEFAULT 0,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY job_name (job_name)
		) {$charset};";
	}
}
