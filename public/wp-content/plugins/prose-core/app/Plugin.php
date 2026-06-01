<?php
/**
 * Main plugin orchestrator.
 *
 * @package ProseCore
 */

namespace Prose\Core;

use Prose\Core\Admin\Module as Admin;
use Prose\Core\AI\Module as AI;
use Prose\Core\API\Module as API;
use Prose\Core\Database\Schema;
use Prose\Core\Forms\Module as Forms;
use Prose\Core\Intake\Module as Intake;
use Prose\Core\Observability\Module as Observability;
use Prose\Core\PDF\Module as PDF;
use Prose\Core\PostTypes\Registrar;
use Prose\Core\Queue\Module as Queue;
use Prose\Core\Rules\Module as Rules;
use Prose\Core\Security\Module as Security;
use Prose\Core\Validation\Module as Validation;
use Prose\Core\Workflows\Module as Workflows;

/**
 * Singleton that bootstraps CourtFlow AI modules.
 */
final class Plugin {

	private static ?self $instance = null;

	private static ?Container $container = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public static function container(): Container {
		if ( null === self::$container ) {
			self::$container = new Container();
			ServiceProvider::register( self::$container );
		}
		return self::$container;
	}

	private function __construct() {
		add_action( 'init', array( $this, 'load_textdomain' ) );
		add_action( 'init', array( $this, 'init' ), 5 );
		add_action( 'admin_init', array( $this, 'maybe_seed' ), 20 );
		add_action( 'init', array( $this, 'maybe_upgrade_rules' ), 15 );
	}

	public function maybe_upgrade_rules(): void {
		if ( ! get_option( 'courtflow_seeded' ) ) {
			return;
		}

		try {
			\Prose\Core\Seed\Seeder::maybe_upgrade_rules();
		} catch ( \Throwable $e ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log( '[CourtFlow] Rules upgrade error: ' . $e->getMessage() );
			}
		}
	}

	public function maybe_seed(): void {
		if ( ! get_option( 'courtflow_needs_seed' ) ) {
			return;
		}

		try {
			\Prose\Core\Seed\Seeder::run();
		} catch ( \Throwable $e ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log( '[CourtFlow] Seeder error: ' . $e->getMessage() );
			}
			return;
		}

		delete_option( 'courtflow_needs_seed' );
	}

	public function load_textdomain(): void {
		load_plugin_textdomain(
			'prose-core',
			false,
			dirname( PROSE_CORE_BASENAME ) . '/languages'
		);
	}

	public function init(): void {
		// Must run on every request (including front-end REST), not only admin_init.
		Schema::ensure();

		try {
			$c = self::container();

			Registrar::register();

			Security::boot( $c );
			Rules::boot( $c );
			Workflows::boot( $c );
			Intake::boot( $c );
			AI::boot( $c );
			Forms::boot( $c );
			PDF::boot( $c );
			Validation::boot( $c );
			Queue::boot( $c );
			Observability::boot( $c );
			Admin::boot( $c );
			API::boot( $c );
			\Prose\Core\Shortcodes\Workspace::register();
		} catch ( \Throwable $e ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log( '[CourtFlow] Init error: ' . $e->getMessage() );
			}
		}
	}

	public static function template_path( string $name ): string {
		return PROSE_CORE_PATH . 'templates/' . ltrim( $name, '/' );
	}
}
