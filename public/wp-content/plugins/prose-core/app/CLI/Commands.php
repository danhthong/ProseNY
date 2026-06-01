<?php
/**
 * WP-CLI commands for CourtFlow.
 *
 * @package ProseCore
 */

namespace Prose\Core\CLI;

use Prose\Core\Database\Schema;
use Prose\Core\Plugin;
use Prose\Core\Seed\Seeder;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	return;
}

/**
 * CourtFlow WP-CLI commands.
 */
final class Commands {

	/**
	 * Run database migrations.
	 *
	 * ## EXAMPLES
	 *     wp courtflow migrate
	 */
	public function migrate(): void {
		Plugin::container();
		Schema::ensure();
		\WP_CLI::success( 'CourtFlow migrations complete.' );
	}

	/**
	 * Seed default workflows, rules, and forms.
	 *
	 * ## EXAMPLES
	 *     wp courtflow seed
	 */
	public function seed(): void {
		delete_option( 'courtflow_seeded' );
		Plugin::container();
		Seeder::run();
		\WP_CLI::success( 'CourtFlow seed data installed.' );
	}
}

\WP_CLI::add_command( 'courtflow migrate', array( Commands::class, 'migrate' ) );
\WP_CLI::add_command( 'courtflow seed', array( Commands::class, 'seed' ) );
