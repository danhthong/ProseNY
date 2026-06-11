<?php
/**
 * Orchestrates all CourtFlow database seeders.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Forms\Database\Seeders;

use ProSe\Core\Forms\Database\Database_Installer;
use ProSe\Core\Forms\Database\Schema_Validator;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Courtflow_Seeder
 */
final class Courtflow_Seeder {

	/**
	 * Install schema and seed all CourtFlow data.
	 *
	 * @return array<string, mixed> Seed report.
	 */
	public static function install_and_seed(): array {
		Database_Installer::install();

		$workflow_seeder = new Workflow_Catalog_Seeder();
		$graph_seeder    = new Graph_Seeder();
		$routing_seeder  = new Routing_Rules_Seeder();
		$deadline_seeder = new Deadline_Rules_Seeder();
		$package_seeder  = new Package_Catalog_Seeder();

		$report = array(
			'workflows' => $workflow_seeder->seed(),
			'graph'     => $graph_seeder->seed(),
			'routing'   => $routing_seeder->seed(),
			'deadlines' => $deadline_seeder->seed(),
			'packages'  => $package_seeder->seed(),
		);

		$validator = new Schema_Validator();
		$report['validation'] = $validator->validate();

		return $report;
	}
}
