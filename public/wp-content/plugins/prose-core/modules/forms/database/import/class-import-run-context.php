<?php
/**
 * Tracks import run ID, manifest snapshots, and content hashes.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Forms\Database\Import;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Import_Run_Context
 */
final class Import_Run_Context {

	public const MANIFEST_PREFIX     = 'prose_import_manifest_';
	public const HASHES_OPTION       = 'prose_import_content_hashes';
	public const LATEST_RUN_OPTION     = 'prose_import_latest_run_id';
	public const ALIAS_REGISTRY_OPTION = 'prose_form_alias_registry';

	/**
	 * Current import run ID.
	 *
	 * @var string
	 */
	private string $run_id;

	/**
	 * In-memory manifest for the active run.
	 *
	 * @var array<string, mixed>
	 */
	private array $manifest = array();

	/**
	 * Whether writes are enabled (false = dry-run).
	 *
	 * @var bool
	 */
	private bool $dry_run;

	/**
	 * Constructor.
	 *
	 * @param string|null $run_id  Optional existing run ID.
	 * @param bool        $dry_run Dry-run mode.
	 */
	public function __construct( ?string $run_id = null, bool $dry_run = false ) {
		$this->run_id  = $run_id ?? self::generate_run_id();
		$this->dry_run = $dry_run;
		$this->manifest = array(
			'import_run_id' => $this->run_id,
			'started_at'    => gmdate( 'c' ),
			'dry_run'       => $dry_run,
			'domains'       => array(),
		);
	}

	/**
	 * Generate a unique import run ID.
	 *
	 * @return string
	 */
	public static function generate_run_id(): string {
		return 'import_' . gmdate( 'Ymd_His' ) . '_' . wp_generate_password( 8, false );
	}

	/**
	 * Get the current run ID.
	 *
	 * @return string
	 */
	public function get_run_id(): string {
		return $this->run_id;
	}

	/**
	 * Whether this is a dry-run.
	 *
	 * @return bool
	 */
	public function is_dry_run(): bool {
		return $this->dry_run;
	}

	/**
	 * Compute a content hash for idempotent comparison.
	 *
	 * @param array<string, mixed> $fields Seed fields.
	 * @return string
	 */
	public static function content_hash( array $fields ): string {
		ksort( $fields );

		return hash( 'sha256', (string) wp_json_encode( $fields ) );
	}

	/**
	 * Get stored hash for a domain natural key.
	 *
	 * @param string $domain     Domain name (workflows, nodes, etc.).
	 * @param string $natural_key Natural key string.
	 * @return string|null
	 */
	public function get_stored_hash( string $domain, string $natural_key ): ?string {
		$all = get_option( self::HASHES_OPTION, array() );

		if ( ! is_array( $all ) ) {
			return null;
		}

		return isset( $all[ $domain ][ $natural_key ]['content_hash'] )
			? (string) $all[ $domain ][ $natural_key ]['content_hash']
			: null;
	}

	/**
	 * Record an import action in the manifest.
	 *
	 * @param string               $domain      Domain name.
	 * @param string               $natural_key Natural key.
	 * @param string               $action      create|update|unchanged|archive.
	 * @param array<string, mixed> $before      Prior state snapshot.
	 * @param array<string, mixed> $after       New state snapshot.
	 * @param string               $content_hash Content fingerprint.
	 * @return void
	 */
	public function record(
		string $domain,
		string $natural_key,
		string $action,
		array $before,
		array $after,
		string $content_hash
	): void {
		if ( ! isset( $this->manifest['domains'][ $domain ] ) ) {
			$this->manifest['domains'][ $domain ] = array();
		}

		$this->manifest['domains'][ $domain ][ $natural_key ] = array(
			'action'       => $action,
			'before'       => $before,
			'after'        => $after,
			'content_hash' => $content_hash,
			'import_run_id' => $this->run_id,
		);

		if ( $this->dry_run ) {
			return;
		}

		$all = get_option( self::HASHES_OPTION, array() );
		if ( ! is_array( $all ) ) {
			$all = array();
		}
		if ( ! isset( $all[ $domain ] ) ) {
			$all[ $domain ] = array();
		}

		$all[ $domain ][ $natural_key ] = array(
			'content_hash'  => $content_hash,
			'import_run_id' => $this->run_id,
			'updated_at'    => gmdate( 'c' ),
		);

		update_option( self::HASHES_OPTION, $all, false );
	}

	/**
	 * Determine upsert action from hash comparison.
	 *
	 * @param string      $domain      Domain.
	 * @param string      $natural_key Natural key.
	 * @param string      $new_hash    New content hash.
	 * @param object|null $existing    Existing DB row if any.
	 * @return string create|update|unchanged
	 */
	public function resolve_action( string $domain, string $natural_key, string $new_hash, ?object $existing ): string {
		if ( null === $existing ) {
			return 'create';
		}

		$stored = $this->get_stored_hash( $domain, $natural_key );

		if ( null !== $stored && $stored === $new_hash ) {
			return 'unchanged';
		}

		return 'update';
	}

	/**
	 * Begin a DB transaction for a seeder stage.
	 *
	 * @return void
	 */
	public function begin_transaction(): void {
		if ( $this->dry_run ) {
			return;
		}

		global $wpdb;
		$wpdb->query( 'START TRANSACTION' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Commit the current transaction.
	 *
	 * @return void
	 */
	public function commit_transaction(): void {
		if ( $this->dry_run ) {
			return;
		}

		global $wpdb;
		$wpdb->query( 'COMMIT' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Roll back the current transaction.
	 *
	 * @return void
	 */
	public function rollback_transaction(): void {
		if ( $this->dry_run ) {
			return;
		}

		global $wpdb;
		$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Persist the manifest snapshot for rollback.
	 *
	 * @return void
	 */
	public function finalize(): void {
		$this->manifest['completed_at'] = gmdate( 'c' );

		if ( $this->dry_run ) {
			return;
		}

		update_option( self::MANIFEST_PREFIX . $this->run_id, $this->manifest, false );
		update_option( self::LATEST_RUN_OPTION, $this->run_id, false );
	}

	/**
	 * Load a manifest by run ID.
	 *
	 * @param string $run_id Import run ID.
	 * @return array<string, mixed>|null
	 */
	public static function load_manifest( string $run_id ): ?array {
		$data = get_option( self::MANIFEST_PREFIX . $run_id, null );

		return is_array( $data ) ? $data : null;
	}

	/**
	 * Get the in-memory manifest.
	 *
	 * @return array<string, mixed>
	 */
	public function get_manifest(): array {
		return $this->manifest;
	}

	/**
	 * Store a top-level manifest value (e.g. alias_snapshot).
	 *
	 * @param string $key   Manifest key.
	 * @param mixed  $value Value to store.
	 * @return void
	 */
	public function set_manifest_value( string $key, $value ): void {
		$this->manifest[ $key ] = $value;
	}
}
