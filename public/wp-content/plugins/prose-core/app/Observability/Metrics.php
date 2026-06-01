<?php
/**
 * Simple metrics counters.
 *
 * @package ProseCore
 */

namespace Prose\Core\Observability;

final class Metrics {

	public static function increment( string $metric, int $by = 1 ): void {
		$key = 'courtflow_metric_' . $metric;
		$val = (int) get_option( $key, 0 );
		update_option( $key, $val + $by, false );
	}

	public static function get( string $metric ): int {
		return (int) get_option( 'courtflow_metric_' . $metric, 0 );
	}

	public static function on_session_created(): void {
		self::increment( 'sessions_created' );
	}
}
