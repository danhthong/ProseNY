<?php
/**
 * Procedural Navigator validator — structured intake and resolution checks.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Procedural;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Validator
 */
final class Validator {

	/**
	 * Validate the intake payload shape.
	 *
	 * @param mixed $intake Intake payload.
	 * @return array{valid: bool, error?: array{code: string, message: string}}
	 */
	public function validate_intake( $intake ): array {
		if ( ! is_array( $intake ) ) {
			return $this->error(
				'invalid_intake',
				__( 'Intake payload must be an object.', 'prose-core' )
			);
		}

		$issue = isset( $intake['issue'] ) ? trim( (string) $intake['issue'] ) : '';

		if ( '' === $issue ) {
			return $this->error(
				'invalid_intake',
				__( 'Intake must include an issue.', 'prose-core' )
			);
		}

		if ( isset( $intake['facts'] ) && ! is_array( $intake['facts'] ) ) {
			return $this->error(
				'invalid_intake',
				__( 'Intake facts must be an object when provided.', 'prose-core' )
			);
		}

		return array( 'valid' => true );
	}

	/**
	 * Validate that routing resolved an issue.
	 *
	 * @param string|null $issue Resolved issue.
	 * @return array{valid: bool, error?: array{code: string, message: string}}
	 */
	public function validate_issue( ?string $issue ): array {
		if ( null === $issue || '' === $issue ) {
			return $this->error(
				'issue_not_found',
				__( 'Issue could not be resolved.', 'prose-core' )
			);
		}

		return array( 'valid' => true );
	}

	/**
	 * Validate that routing resolved a court.
	 *
	 * @param string|null $court Resolved court.
	 * @return array{valid: bool, error?: array{code: string, message: string}}
	 */
	public function validate_court( ?string $court ): array {
		if ( null === $court || '' === $court ) {
			return $this->error(
				'court_not_found',
				__( 'Court could not be resolved.', 'prose-core' )
			);
		}

		return array( 'valid' => true );
	}

	/**
	 * Validate that a workflow was resolved.
	 *
	 * @param string|null               $workflow   Workflow key.
	 * @param array<string, mixed>|null $definition Workflow definition.
	 * @return array{valid: bool, error?: array{code: string, message: string}}
	 */
	public function validate_workflow( ?string $workflow, ?array $definition ): array {
		if ( null === $workflow || '' === $workflow || null === $definition ) {
			return $this->error(
				'workflow_not_found',
				__( 'Workflow could not be resolved.', 'prose-core' )
			);
		}

		return array( 'valid' => true );
	}

	/**
	 * Validate that a package was resolved.
	 *
	 * @param string|null $package_id Package enum.
	 * @return array{valid: bool, error?: array{code: string, message: string}}
	 */
	public function validate_package( ?string $package_id ): array {
		if ( null === $package_id || '' === $package_id ) {
			return $this->error(
				'package_not_found',
				__( 'Package could not be resolved.', 'prose-core' )
			);
		}

		return array( 'valid' => true );
	}

	/**
	 * Validate that forms were resolved for a package.
	 *
	 * @param string   $package_id Package enum.
	 * @param string[] $forms      Resolved form codes.
	 * @return array{valid: bool, error?: array{code: string, message: string}}
	 */
	public function validate_forms( string $package_id, array $forms ): array {
		if ( empty( $forms ) ) {
			return $this->error(
				'form_package_mismatch',
				sprintf(
					/* translators: %s: package enum id */
					__( 'No forms could be resolved for package %s.', 'prose-core' ),
					$package_id
				)
			);
		}

		return array( 'valid' => true );
	}

	/**
	 * Validate that a package belongs to the resolved workflow enum.
	 *
	 * @param string               $package_id    Package enum.
	 * @param string               $workflow_enum Expected workflow enum.
	 * @param array<string, mixed> $package_row   Package catalog row.
	 * @return array{valid: bool, error?: array{code: string, message: string}}
	 */
	public function validate_workflow_package_match( string $package_id, string $workflow_enum, array $package_row ): array {
		$package_workflow = (string) ( $package_row['workflow_id'] ?? '' );

		if ( '' === $package_workflow ) {
			return array( 'valid' => true );
		}

		if ( $package_workflow !== $workflow_enum ) {
			return $this->error(
				'workflow_package_mismatch',
				sprintf(
					/* translators: 1: package enum, 2: workflow enum */
					__( 'Package %1$s does not match workflow %2$s.', 'prose-core' ),
					$package_id,
					$workflow_enum
				)
			);
		}

		return array( 'valid' => true );
	}

	/**
	 * Build a failure response envelope.
	 *
	 * @param array{code: string, message: string} $error Error payload.
	 * @return array{success: false, error: array{code: string, message: string}}
	 */
	public function failure( array $error ): array {
		return array(
			'success' => false,
			'error'   => array(
				'code'    => (string) ( $error['code'] ?? 'navigation_failed' ),
				'message' => (string) ( $error['message'] ?? __( 'Navigation failed.', 'prose-core' ) ),
			),
		);
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
