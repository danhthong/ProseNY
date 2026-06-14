<?php
/**
 * Assembly input validator.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Assembly;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Validator
 */
final class Validator {

	/**
	 * Validate assembly request inputs.
	 *
	 * @param mixed  $intake     Intake payload.
	 * @param string $package_id Package identifier.
	 * @return array{valid: bool, error?: array{code: string, message: string}}
	 */
	public function validate_request( $intake, string $package_id ): array {
		$package_id = trim( $package_id );

		if ( '' === $package_id ) {
			return $this->error(
				'missing_package',
				__( 'Package ID is required.', 'prose-core' )
			);
		}

		if ( ! $this->is_valid_package_id( $package_id ) ) {
			return $this->error(
				'invalid_package',
				__( 'Package ID format is invalid.', 'prose-core' )
			);
		}

		if ( null === $intake ) {
			return $this->error(
				'missing_intake',
				__( 'Intake data is required.', 'prose-core' )
			);
		}

		if ( ! is_array( $intake ) ) {
			return $this->error(
				'malformed_intake',
				__( 'Intake data must be a JSON object.', 'prose-core' )
			);
		}

		if ( empty( $intake ) ) {
			return $this->error(
				'missing_intake',
				__( 'Intake data is required.', 'prose-core' )
			);
		}

		return array( 'valid' => true );
	}

	/**
	 * Validate that a package definition was resolved.
	 *
	 * @param array<string, mixed>|null $package Package definition.
	 * @return array{valid: bool, error?: array{code: string, message: string}}
	 */
	public function validate_package( ?array $package ): array {
		if ( null === $package || empty( $package['package_id'] ) ) {
			return $this->error(
				'package_not_found',
				__( 'Package could not be resolved.', 'prose-core' )
			);
		}

		$forms = (array) ( $package['forms'] ?? array() );

		if ( empty( $forms ) ) {
			return $this->error(
				'invalid_package',
				__( 'Package contains no forms.', 'prose-core' )
			);
		}

		return array( 'valid' => true );
	}

	/**
	 * Determine whether a package identifier matches the expected enum pattern.
	 *
	 * @param string $package_id Package identifier.
	 * @return bool
	 */
	private function is_valid_package_id( string $package_id ): bool {
		return (bool) preg_match( '/^PKG_[A-Z0-9_]+$/', $package_id );
	}

	/**
	 * Build a validation error payload.
	 *
	 * @param string $code    Error code.
	 * @param string $message Error message.
	 * @return array{valid: false, error: array{code: string, message: string}}
	 */
	private function error( string $code, string $message ): array {
		return array(
			'valid' => false,
			'error' => array(
				'code'    => $code,
				'message' => $message,
			),
		);
	}
}
