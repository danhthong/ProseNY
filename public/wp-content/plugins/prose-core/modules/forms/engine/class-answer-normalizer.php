<?php
/**
 * Intake answer normalization helpers for the routing engine.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Forms\Engine;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Trait Answer_Normalizer
 *
 * Deterministic coercion of free-form intake answers into stable tokens
 * and tri-state booleans. Shared by the routing resolvers so that the
 * same input always normalizes identically.
 */
trait Answer_Normalizer {

	/**
	 * Normalize a value into a lowercase underscore token.
	 *
	 * @param mixed $value Raw answer value.
	 * @return string
	 */
	protected function normalize_token( $value ): string {
		if ( ! is_scalar( $value ) ) {
			return '';
		}

		$token = strtolower( trim( (string) $value ) );
		$token = (string) preg_replace( '/[\s\-]+/', '_', $token );

		return $token;
	}

	/**
	 * Coerce a value into a tri-state boolean (true, false, or null).
	 *
	 * Null indicates the answer was absent or ambiguous, which keeps the
	 * routing decision deterministic instead of guessing.
	 *
	 * @param mixed $value Raw answer value.
	 * @return bool|null
	 */
	protected function to_bool( $value ): ?bool {
		if ( is_bool( $value ) ) {
			return $value;
		}

		if ( is_int( $value ) || is_float( $value ) ) {
			return 0 !== (int) $value;
		}

		if ( ! is_string( $value ) ) {
			return null;
		}

		$token = strtolower( trim( $value ) );

		if ( '' === $token ) {
			return null;
		}

		$truthy = array( 'true', '1', 'yes', 'y', 'agree', 'agreed', 'on' );
		$falsy  = array( 'false', '0', 'no', 'n', 'disagree', 'off' );

		if ( in_array( $token, $truthy, true ) ) {
			return true;
		}

		if ( in_array( $token, $falsy, true ) ) {
			return false;
		}

		return null;
	}
}
