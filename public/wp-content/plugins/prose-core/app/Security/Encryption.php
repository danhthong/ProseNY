<?php
/**
 * libsodium encryption for facts at rest.
 *
 * @package ProseCore
 */

namespace Prose\Core\Security;

use Prose\Core\Support\Config;

final class Encryption {

	private string $key;

	public function __construct() {
		$secret = Config::get( 'pii_secret', '' );
		if ( ! $secret ) {
			$secret = wp_salt( 'auth' );
		}
		$this->key = hash( 'sha256', $secret, true );
	}

	public function encrypt( string $plaintext ): string {
		if ( ! function_exists( 'sodium_crypto_secretbox' ) ) {
			return base64_encode( $plaintext );
		}

		$nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$cipher = sodium_crypto_secretbox( $plaintext, $nonce, $this->key );

		return base64_encode( $nonce . $cipher );
	}

	public function decrypt( string $encoded ): string {
		if ( ! function_exists( 'sodium_crypto_secretbox_open' ) ) {
			return base64_decode( $encoded ) ?: '';
		}

		$decoded = base64_decode( $encoded, true );
		if ( false === $decoded ) {
			return '';
		}

		$nonce  = substr( $decoded, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$cipher = substr( $decoded, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );

		$plain = sodium_crypto_secretbox_open( $cipher, $nonce, $this->key );
		return false === $plain ? '' : $plain;
	}
}
