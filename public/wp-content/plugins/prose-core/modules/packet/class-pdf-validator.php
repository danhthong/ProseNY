<?php
/**
 * PDF Validator — validates source PDFs and cached packet artifacts.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Packet;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Pdf_Validator
 */
final class Pdf_Validator {

	public const CODE_PDF_MISSING            = 'pdf_missing';
	public const CODE_PDF_UNREADABLE         = 'pdf_unreadable';
	public const CODE_PDF_CORRUPTED          = 'pdf_corrupted';
	public const CODE_EMPTY_PACKET           = 'empty_packet';
	public const CODE_MERGED_PACKET_MISSING  = 'merged_packet_missing';
	public const CODE_ZIP_PACKAGE_MISSING    = 'zip_package_missing';
	public const CODE_INVALID_MANIFEST       = 'invalid_manifest';
	public const CODE_PACKET_GEN_FAILURE     = 'packet_generation_failure';
	public const CODE_PACKAGE_NOT_FOUND      = 'package_not_found';

	/**
	 * Validate resolved source PDFs for a package.
	 *
	 * @param array<int, array{form_id: string, pdf_path: string}> $resolved Resolved forms.
	 * @return array<int, array{code: string, message: string, form_id?: string}>
	 */
	public function validate_sources( array $resolved ): array {
		if ( empty( $resolved ) ) {
			return array(
				array(
					'code'    => self::CODE_EMPTY_PACKET,
					'message' => __( 'Package has no forms.', 'prose-core' ),
				),
			);
		}

		$errors = array();

		foreach ( $resolved as $row ) {
			$form_id  = (string) ( $row['form_id'] ?? '' );
			$pdf_path = (string) ( $row['pdf_path'] ?? '' );

			if ( '' === $pdf_path ) {
				$errors[] = array(
					'code'    => self::CODE_PDF_MISSING,
					'message' => sprintf(
						/* translators: %s: form code */
						__( '%s PDF not found.', 'prose-core' ),
						$form_id
					),
					'form_id' => $form_id,
				);
				continue;
			}

			if ( ! is_readable( $pdf_path ) ) {
				$errors[] = array(
					'code'    => self::CODE_PDF_UNREADABLE,
					'message' => sprintf(
						/* translators: %s: form code */
						__( '%s PDF is not readable.', 'prose-core' ),
						$form_id
					),
					'form_id' => $form_id,
				);
				continue;
			}

			if ( ! $this->is_valid_pdf_file( $pdf_path ) ) {
				$errors[] = array(
					'code'    => self::CODE_PDF_CORRUPTED,
					'message' => sprintf(
						/* translators: %s: form code */
						__( '%s PDF appears corrupted.', 'prose-core' ),
						$form_id
					),
					'form_id' => $form_id,
				);
			}
		}

		return $errors;
	}

	/**
	 * Validate cached packet artifacts.
	 *
	 * @param Packet_Store           $store      Packet store.
	 * @param string                 $package_id Package id.
	 * @param array<string, mixed>|null $manifest Stored manifest.
	 * @return array<int, array{code: string, message: string}>
	 */
	public function validate_artifacts( Packet_Store $store, string $package_id, ?array $manifest ): array {
		$errors = array();

		if ( null === $manifest || empty( $manifest['package_id'] ) ) {
			$errors[] = array(
				'code'    => self::CODE_INVALID_MANIFEST,
				'message' => __( 'Packet manifest is missing or invalid.', 'prose-core' ),
			);
		}

		if ( ! $store->pdf_exists( $package_id ) ) {
			$errors[] = array(
				'code'    => self::CODE_MERGED_PACKET_MISSING,
				'message' => __( 'Merged PDF packet does not exist.', 'prose-core' ),
			);
		} elseif ( ! $this->is_valid_pdf_file( $store->pdf_path( $package_id ) ) ) {
			$errors[] = array(
				'code'    => self::CODE_PDF_CORRUPTED,
				'message' => __( 'Merged PDF packet appears corrupted.', 'prose-core' ),
			);
		}

		if ( ! $store->zip_exists( $package_id ) ) {
			$errors[] = array(
				'code'    => self::CODE_ZIP_PACKAGE_MISSING,
				'message' => __( 'ZIP packet does not exist.', 'prose-core' ),
			);
		}

		return $errors;
	}

	/**
	 * Partition source validation errors into missing vs invalid lists.
	 *
	 * @param array<int, array{code: string, message: string, form_id?: string}> $errors Errors.
	 * @return array{missing: string[], invalid: string[]}
	 */
	public function partition_source_errors( array $errors ): array {
		$missing  = array();
		$invalid  = array();

		foreach ( $errors as $error ) {
			$form_id = (string) ( $error['form_id'] ?? '' );
			$code    = (string) ( $error['code'] ?? '' );

			if ( '' === $form_id ) {
				continue;
			}

			if ( self::CODE_PDF_MISSING === $code ) {
				$missing[] = $form_id;
			} elseif ( in_array( $code, array( self::CODE_PDF_UNREADABLE, self::CODE_PDF_CORRUPTED ), true ) ) {
				$invalid[] = $form_id;
			}
		}

		return array(
			'missing' => array_values( array_unique( $missing ) ),
			'invalid' => array_values( array_unique( $invalid ) ),
		);
	}

	/**
	 * Check whether a file looks like a valid PDF.
	 *
	 * @param string $path Absolute file path.
	 * @return bool
	 */
	public function is_valid_pdf_file( string $path ): bool {
		if ( '' === $path || ! is_readable( $path ) ) {
			return false;
		}

		$size = filesize( $path );

		if ( false === $size || $size < 5 ) {
			return false;
		}

		$bytes = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

		if ( false === $bytes || substr( $bytes, 0, 5 ) !== '%PDF-' ) {
			return false;
		}

		return false !== strpos( $bytes, '%%EOF' );
	}
}
