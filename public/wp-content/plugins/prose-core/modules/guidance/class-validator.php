<?php
/**
 * Guidance Validator — structured errors and warnings for guidance content.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Guidance;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Validator
 */
final class Validator {

	public const CODE_WORKFLOW_NOT_FOUND      = 'workflow_not_found';
	public const CODE_STAGE_NOT_FOUND         = 'stage_not_found';
	public const CODE_GUIDANCE_FILE_MISSING   = 'guidance_file_missing';
	public const CODE_INVALID_GUIDANCE_SCHEMA = 'invalid_guidance_schema';

	public const WARN_GUIDANCE_MISSING        = 'guidance_missing';
	public const WARN_MISSING_TITLE           = 'missing_title';
	public const WARN_MISSING_DESCRIPTION     = 'missing_description';
	public const WARN_MALFORMED_GUIDANCE_FILE = 'malformed_guidance_file';

	/**
	 * Known optional stage guidance fields.
	 */
	private const OPTIONAL_ARRAY_FIELDS = array( 'tips', 'warnings', 'related_forms', 'resources' );

	/**
	 * Build a failure envelope.
	 *
	 * @param string $code    Error code.
	 * @param string $message Human-readable message.
	 * @return array{success: false, error: array{code: string, message: string}}
	 */
	public function failure( string $code, string $message ): array {
		return array(
			'success' => false,
			'error'   => array(
				'code'    => $code,
				'message' => $message,
			),
		);
	}

	/**
	 * Build a warning record.
	 *
	 * @param string               $code  Warning code.
	 * @param array<string, mixed> $extra Additional fields.
	 * @return array<string, mixed>
	 */
	public function warning( string $code, array $extra = array() ): array {
		return array_merge(
			array( 'code' => $code ),
			$extra
		);
	}

	/**
	 * Validate a decoded stage guidance record.
	 *
	 * @param string               $stage_id Stage identifier.
	 * @param array<string, mixed> $data     Decoded guidance data.
	 * @return array<int, array<string, mixed>>
	 */
	public function validate_stage_guidance( string $stage_id, array $data ): array {
		$warnings = array();

		if ( '' === trim( (string) ( $data['title'] ?? '' ) ) ) {
			$warnings[] = $this->warning(
				self::WARN_MISSING_TITLE,
				array( 'stage' => $stage_id )
			);
		}

		if ( '' === trim( (string) ( $data['description'] ?? '' ) ) ) {
			$warnings[] = $this->warning(
				self::WARN_MISSING_DESCRIPTION,
				array( 'stage' => $stage_id )
			);
		}

		foreach ( self::OPTIONAL_ARRAY_FIELDS as $field ) {
			if ( ! isset( $data[ $field ] ) ) {
				continue;
			}

			if ( ! is_array( $data[ $field ] ) ) {
				$warnings[] = $this->warning(
					self::CODE_INVALID_GUIDANCE_SCHEMA,
					array(
						'stage' => $stage_id,
						'field' => $field,
					)
				);
			}
		}

		if ( isset( $data['estimated_time'] ) && ! is_string( $data['estimated_time'] ) && ! is_null( $data['estimated_time'] ) ) {
			$warnings[] = $this->warning(
				self::CODE_INVALID_GUIDANCE_SCHEMA,
				array(
					'stage' => $stage_id,
					'field' => 'estimated_time',
				)
			);
		}

		return $warnings;
	}

	/**
	 * Validate a decoded county guidance record.
	 *
	 * @param string               $county County name.
	 * @param array<string, mixed> $data   Decoded county guidance.
	 * @return array<int, array<string, mixed>>
	 */
	public function validate_county_guidance( string $county, array $data ): array {
		$warnings = array();

		foreach ( array( 'filing_notes', 'special_requirements' ) as $field ) {
			if ( ! isset( $data[ $field ] ) ) {
				continue;
			}

			if ( ! is_array( $data[ $field ] ) ) {
				$warnings[] = $this->warning(
					self::CODE_INVALID_GUIDANCE_SCHEMA,
					array(
						'county' => $county,
						'field'  => $field,
					)
				);
			}
		}

		return $warnings;
	}
}
