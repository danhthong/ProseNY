<?php
/**
 * Token-bucket rate limiter.
 *
 * @package ProseCore
 */

namespace Prose\Core\Security;

use Prose\Core\Support\Config;

final class RateLimiter {

	public function allow( string $key, ?int $limit = null ): bool {
		$limit   = $limit ?? (int) Config::get( 'rate_limit_per_min', 30 );
		$bucket  = 'courtflow_rl_' . md5( $key );
		$current = (int) get_transient( $bucket );

		if ( $current >= $limit ) {
			return false;
		}

		set_transient( $bucket, $current + 1, MINUTE_IN_SECONDS );
		return true;
	}
}
