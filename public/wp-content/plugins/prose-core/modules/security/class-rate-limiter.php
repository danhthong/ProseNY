<?php
/**
 * Transient-backed rate limiter for public REST endpoints.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Security;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Rate_Limiter
 */
final class Rate_Limiter {

	/**
	 * Transient key prefix.
	 */
	private const PREFIX = 'prose_rate_';

	/**
	 * Check whether a request is within the configured limit.
	 *
	 * @param string $bucket          Rate limit bucket id.
	 * @param int    $max_requests    Maximum requests allowed in the window.
	 * @param int    $window_seconds  Window length in seconds.
	 * @return bool True when allowed.
	 */
	public function allow( string $bucket, int $max_requests = 60, int $window_seconds = 60 ): bool {
		$key   = $this->transient_key( $bucket );
		$count = (int) get_transient( $key );

		if ( $count >= $max_requests ) {
			return false;
		}

		set_transient( $key, $count + 1, $window_seconds );

		return true;
	}

	/**
	 * REST permission helper — returns true or a throttled WP_Error.
	 *
	 * @param string $bucket         Rate limit bucket id.
	 * @param int    $max_requests   Maximum requests allowed in the window.
	 * @param int    $window_seconds Window length in seconds.
	 * @return bool|\WP_Error
	 */
	public function rest_permission( string $bucket, int $max_requests = 60, int $window_seconds = 60 ) {
		/**
		 * Filter rate limit settings for a REST bucket.
		 *
		 * @param array{max: int, window: int} $limits Limits for the bucket.
		 * @param string                       $bucket Bucket id.
		 */
		$limits = apply_filters(
			'prose_rate_limit',
			array(
				'max'    => $max_requests,
				'window' => $window_seconds,
			),
			$bucket
		);

		$max    = max( 1, (int) ( $limits['max'] ?? $max_requests ) );
		$window = max( 1, (int) ( $limits['window'] ?? $window_seconds ) );

		if ( $this->allow( $bucket, $max, $window ) ) {
			return true;
		}

		return new \WP_Error(
			'prose_rate_limited',
			__( 'Too many requests. Please wait a moment and try again.', 'prose-core' ),
			array( 'status' => 429 )
		);
	}

	/**
	 * Build a bucket id from the client IP and route.
	 *
	 * @param string $route Route identifier.
	 * @return string
	 */
	public function bucket_for_route( string $route ): string {
		$ip = '';

		if ( ! empty( $_SERVER['REMOTE_ADDR'] ) ) {
			$ip = sanitize_text_field( wp_unslash( (string) $_SERVER['REMOTE_ADDR'] ) );
		}

		return \sanitize_key( $route . '_' . $ip );
	}

	/**
	 * Transient key for a bucket.
	 *
	 * @param string $bucket Bucket id.
	 * @return string
	 */
	private function transient_key( string $bucket ): string {
		return self::PREFIX . md5( $bucket );
	}
}
