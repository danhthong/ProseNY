<?php
/**
 * Background jobs module (Action Scheduler).
 *
 * @package ProseCore
 */

namespace Prose\Core\Queue;

use Prose\Core\Container;
use Prose\Core\Queue\Jobs\BuildPackageJob;
use Prose\Core\Queue\Jobs\FillPDFJob;
use Prose\Core\Queue\Jobs\PurgeExpiredDocsJob;

final class Module {

	public static function boot( Container $container ): void {
		self::load_action_scheduler();
		add_action( 'init', array( self::class, 'ensure_action_scheduler' ), 5 );
		add_action( 'courtflow_fill_pdf', array( FillPDFJob::class, 'handle' ), 10, 1 );
		add_action( 'courtflow_build_package', array( BuildPackageJob::class, 'handle' ), 10, 1 );
		add_action( 'courtflow_purge_docs', array( PurgeExpiredDocsJob::class, 'handle' ) );

		if ( ! wp_next_scheduled( 'courtflow_purge_docs' ) ) {
			wp_schedule_event( time(), 'daily', 'courtflow_purge_docs' );
		}
	}

	public static function load_action_scheduler(): void {
		$path = PROSE_CORE_PATH . 'vendor/woocommerce/action-scheduler/action-scheduler.php';
		if ( file_exists( $path ) ) {
			require_once $path;
		}
	}

	public static function ensure_action_scheduler(): void {
		// Action Scheduler registers on load.
	}

	public static function dispatch( string $hook, array $args = array(), string $group = 'courtflow' ): void {
		if ( function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action( $hook, $args, $group );
			return;
		}

		do_action( $hook, ...$args );
	}
}
