<?php
/**
 * CourtFlow data import orchestrator.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Forms\Database\Import;

use ProSe\Core\Forms\Database\Database_Installer;
use ProSe\Core\Forms\Database\Graph_Backfill;
use ProSe\Core\Forms\Database\Schema_Validator;
use ProSe\Core\Forms\Database\Seeders\Alias_Registry_Seeder;
use ProSe\Core\Forms\Database\Seeders\Form_Mapping_Seeder;
use ProSe\Core\Forms\Database\Seeders\Graph_Seeder;
use ProSe\Core\Forms\Database\Seeders\Package_Catalog_Seeder;
use ProSe\Core\Forms\Database\Seeders\Routing_Rules_Seeder;
use ProSe\Core\Forms\Database\Seeders\Deadline_Rules_Seeder;
use ProSe\Core\Forms\Database\Seeders\Workflow_Catalog_Seeder;
use ProSe\Core\Forms\Engine\Routing_Engine_Foundation;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Import_Orchestrator
 */
final class Import_Orchestrator {

	/**
	 * Run the full import pipeline.
	 *
	 * @param bool $dry_run Pre-flight only (no writes).
	 * @param bool $strict  Promote soft validation to hard.
	 * @return array<string, mixed>
	 */
	public function run( bool $dry_run = false, bool $strict = false ): array {
		$loader  = new Seeder_Artifact_Loader();
		$context = new Import_Run_Context( null, $dry_run );

		try {
			$artifacts = $loader->load_all();
		} catch ( \RuntimeException $e ) {
			return array(
				'success' => false,
				'error'   => $e->getMessage(),
			);
		}

		$validator = new Import_Validator();
		$validation = $validator->validate( $artifacts, $strict );

		if ( ! $validation['passed'] ) {
			return array(
				'success'    => false,
				'validation' => $validation,
				'dry_run'    => $dry_run,
			);
		}

		if ( $dry_run ) {
			return array(
				'success'    => true,
				'dry_run'    => true,
				'validation' => $validation,
			);
		}

		Database_Installer::install();

		$context->set_manifest_value( 'alias_snapshot', ( new Alias_Registry() )->snapshot_option() );

		$report = array(
			'import_run_id' => $context->get_run_id(),
			'validation'    => $validation,
		);

		$stages = array(
			'workflows' => static function () use ( $context, $loader ) {
				$seeder = new Workflow_Catalog_Seeder();
				return $seeder->seed_from_artifact( $loader->load( 'workflow-seeder.json' ), $context );
			},
			'graph' => static function () use ( $context, $loader ) {
				$seeder = new Graph_Seeder();
				return $seeder->seed_from_artifact( $loader->load( 'node-seeder.json' ), $context );
			},
			'packages' => static function () use ( $context, $loader ) {
				$seeder = new Package_Catalog_Seeder();
				return $seeder->seed_from_artifact( $loader->load( 'package-seeder.json' ), $context );
			},
			'alias' => static function () use ( $context, $loader ) {
				$seeder = new Alias_Registry_Seeder();
				return $seeder->seed_from_artifact( $loader->load( 'alias-registry.json' ), $context );
			},
			'form_mapping' => static function () use ( $context, $loader ) {
				$seeder = new Form_Mapping_Seeder();
				return $seeder->seed_from_artifact( $loader->load( 'form-package-seeder.json' ), $context );
			},
		);

		foreach ( $stages as $name => $callback ) {
			$context->begin_transaction();
			try {
				$report[ $name ] = $callback();
				$context->commit_transaction();
			} catch ( \Throwable $e ) {
				$context->rollback_transaction();
				$report['success'] = false;
				$report['failed_stage'] = $name;
				$report['error'] = $e->getMessage();
				return $report;
			}
		}

		$routing_seeder  = new Routing_Rules_Seeder();
		$deadline_seeder = new Deadline_Rules_Seeder();
		$report['routing']   = $routing_seeder->seed();
		$report['deadlines'] = $deadline_seeder->seed();

		$backfill = new Graph_Backfill();
		$backfill->backfill_package_versions();
		$backfill->backfill_forms();
		$backfill->backfill_package_forms();

		$schema_validator = new Schema_Validator();
		$report['schema_validation'] = $schema_validator->validate();

		$foundation = new Routing_Engine_Foundation();
		$report['routing_foundation'] = $foundation->prepare();

		$context->finalize();

		$report['success'] = true;
		$report['manifest'] = $context->get_manifest();

		return $report;
	}
}
