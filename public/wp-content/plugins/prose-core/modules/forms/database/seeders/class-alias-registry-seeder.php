<?php
/**
 * Seed alias-registry.json into wp_options.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Forms\Database\Seeders;

use ProSe\Core\Forms\Database\Import\Alias_Registry;
use ProSe\Core\Forms\Database\Import\Import_Run_Context;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Alias_Registry_Seeder
 */
final class Alias_Registry_Seeder {

	/**
	 * Load alias registry from artifact.
	 *
	 * @param array<string, mixed> $artifact Decoded alias-registry.json.
	 * @param Import_Run_Context   $context  Import context.
	 * @return array{aliases: int, validation: array{hard: string[], soft: string[]}}
	 */
	public function seed_from_artifact( array $artifact, Import_Run_Context $context ): array {
		$registry = new Alias_Registry();
		$before   = $registry->snapshot_option();

		$registry->load_from_artifact( $artifact, true );
		$validation = $registry->validate();

		$alias_count = count( (array) ( $artifact['aliases'] ?? array() ) );

		$context->record(
			'alias',
			'registry',
			'update',
			array( 'snapshot' => $before ),
			array( 'registry_version' => (string) ( $artifact['registry_version'] ?? '' ) ),
			Import_Run_Context::content_hash( $artifact )
		);

		return array(
			'aliases'    => $alias_count,
			'validation' => $validation,
		);
	}
}
