<?php
/**
 * Signed expiring download URLs.
 *
 * @package ProseCore
 */

namespace Prose\Core\Security;

final class SignedUrl {

	public function sign( int $document_id, int $ttl_seconds = 3600 ): string {
		$exp = time() + $ttl_seconds;
		$sig = $this->compute( $document_id, $exp );

		return add_query_arg(
			array(
				'id'  => $document_id,
				'exp' => $exp,
				'sig' => $sig,
			),
			rest_url( 'courtflow/v1/documents/download' )
		);
	}

	public function verify( int $document_id, int $exp, string $sig ): bool {
		if ( time() > $exp ) {
			return false;
		}

		return hash_equals( $this->compute( $document_id, $exp ), $sig );
	}

	private function compute( int $document_id, int $exp ): string {
		return hash_hmac(
			'sha256',
			$document_id . ':' . $exp,
			wp_salt( 'secure_auth' )
		);
	}
}
