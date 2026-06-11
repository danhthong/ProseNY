<?php
/**
 * Seed form-package-seeder.json into wp_prose_package_forms.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Forms\Database\Seeders;

use ProSe\Core\Forms\Database\Database_Installer;
use ProSe\Core\Forms\Database\Import\Alias_Registry;
use ProSe\Core\Forms\Database\Import\Import_Run_Context;
use ProSe\Core\Forms\Database\Repositories\Package_Form_Repository;
use ProSe\Core\Forms\Form_Repository;
use ProSe\Core\Forms\Package_Repository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Form_Mapping_Seeder
 */
final class Form_Mapping_Seeder {

	/**
	 * Package repository.
	 *
	 * @var Package_Repository
	 */
	private Package_Repository $packages;

	/**
	 * Form repository.
	 *
	 * @var Form_Repository
	 */
	private Form_Repository $forms;

	/**
	 * Package-form repository.
	 *
	 * @var Package_Form_Repository
	 */
	private Package_Form_Repository $package_forms;

	/**
	 * Alias registry.
	 *
	 * @var Alias_Registry
	 */
	private Alias_Registry $aliases;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->packages      = new Package_Repository();
		$this->forms         = new Form_Repository();
		$this->package_forms = new Package_Form_Repository();
		$this->aliases       = new Alias_Registry();
	}

	/**
	 * Seed mappings from form-package-seeder.json.
	 *
	 * @param array<string, mixed> $artifact Decoded artifact.
	 * @param Import_Run_Context   $context  Import context.
	 * @return array{created: int, updated: int, skipped_generated: int}
	 */
	public function seed_from_artifact( array $artifact, Import_Run_Context $context ): array {
		$this->aliases->load_from_option();

		$stats = array(
			'created'          => 0,
			'updated'          => 0,
			'skipped_generated' => 0,
		);

		$seen_keys = array();

		foreach ( (array) ( $artifact['mappings'] ?? array() ) as $mapping ) {
			$form_class = (string) ( $mapping['form_class'] ?? 'import_backed' );
			if ( 'generated' === $form_class ) {
				++$stats['skipped_generated'];
				continue;
			}

			$package_key = (string) ( $mapping['package_key'] ?? '' );
			$raw_code    = (string) ( $mapping['form_code'] ?? '' );
			$canonical   = $this->aliases->resolve( $raw_code );
			$requirement = sanitize_text_field( (string) ( $mapping['requirement'] ?? 'optional' ) );

			$package_post = $this->packages->get_by_package_id( $package_key );
			if ( ! $package_post ) {
				continue;
			}

			$package_id = (int) $package_post->ID;
			$form_post  = $this->forms->get_by_form_code( $canonical );

			$mapping_source = (string) ( $mapping['mapping_source'] ?? 'CATALOG' );
			if ( $this->aliases->was_aliased( $raw_code ) ) {
				$mapping_source = 'ALIAS_RESOLUTION';
			}

			$row = array(
				'package_id'    => $package_id,
				'form_code'     => $canonical,
				'form_id'       => $form_post ? $form_post->ID : null,
				'requirement'   => $requirement,
				'condition_key' => sanitize_text_field( (string) ( $mapping['condition_key'] ?? '' ) ),
				'sequence'      => (int) ( $mapping['sequence'] ?? 0 ),
			);

			$natural = "{$package_id}:{$canonical}:{$requirement}";
			$hash    = Import_Run_Context::content_hash(
				array_merge(
					$row,
					array(
						'mapping_source'   => $mapping_source,
						'confidence_score' => (float) ( $mapping['confidence_score'] ?? 1.0 ),
						'original_code'    => $raw_code,
					)
				)
			);

			$seen_keys[] = $natural;

			global $wpdb;
			$table = Database_Installer::table( 'prose_package_forms' );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$existing = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT * FROM {$table} WHERE package_id = %d AND form_code = %s AND requirement = %s LIMIT 1",
					$package_id,
					$canonical,
					$requirement
				)
			);

			$action = $context->resolve_action( 'package_forms', $natural, $hash, $existing );
			if ( 'unchanged' === $action ) {
				continue;
			}

			$before = $existing ? (array) $existing : array();
			$id     = $this->package_forms->upsert( $row );

			if ( $id > 0 ) {
				$after = array( 'id' => $id );
				$context->record( 'package_forms', $natural, $action, $before, $after, $hash );
				if ( 'create' === $action ) {
					++$stats['created'];
				} else {
					++$stats['updated'];
				}
			}
		}

		return $stats;
	}
}
