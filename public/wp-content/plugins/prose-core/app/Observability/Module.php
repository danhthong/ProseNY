<?php
/**
 * Observability module.
 *
 * @package ProseCore
 */

namespace Prose\Core\Observability;

use Prose\Core\Container;

final class Module {

	public static function boot( Container $container ): void {
		add_action( 'courtflow_session_created', array( Metrics::class, 'on_session_created' ), 10, 0 );
	}

	public static function on_session_created(): void {
		Metrics::increment( 'sessions_created' );
	}
}
