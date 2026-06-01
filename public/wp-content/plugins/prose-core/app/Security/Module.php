<?php
/**
 * Security module.
 *
 * @package ProseCore
 */

namespace Prose\Core\Security;

use Prose\Core\Container;

final class Module {

	public static function boot( Container $container ): void {
		add_filter( 'wp_headers', array( self::class, 'security_headers' ) );
		add_action( 'init', array( Capabilities::class, 'register' ), 5 );
	}

	/**
	 * @param array<string, string> $headers
	 * @return array<string, string>
	 */
	public static function security_headers( array $headers ): array {
		$headers['X-Content-Type-Options'] = 'nosniff';
		$headers['X-Frame-Options']        = 'SAMEORIGIN';
		$headers['Referrer-Policy']        = 'strict-origin-when-cross-origin';
		return $headers;
	}
}
