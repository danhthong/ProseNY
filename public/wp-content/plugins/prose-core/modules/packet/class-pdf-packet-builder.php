<?php
/**
 * PDF Packet Builder — admin/build-time orchestrator for blank packet artifacts.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Packet;

use ProSe\Core\Assembly\Package_Loader;
use ProSe\Core\Forms\Classification\Vocabulary;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Pdf_Packet_Builder
 */
final class Pdf_Packet_Builder {

	/**
	 * Package loader.
	 *
	 * @var Package_Loader
	 */
	private Package_Loader $packages;

	/**
	 * PDF resolver.
	 *
	 * @var Pdf_Resolver
	 */
	private Pdf_Resolver $resolver;

	/**
	 * PDF validator.
	 *
	 * @var Pdf_Validator
	 */
	private Pdf_Validator $validator;

	/**
	 * Packet store.
	 *
	 * @var Packet_Store
	 */
	private Packet_Store $store;

	/**
	 * Manifest helper.
	 *
	 * @var Packet_Manifest
	 */
	private Packet_Manifest $manifests;

	/**
	 * PDF merge callable.
	 *
	 * @var callable|null
	 */
	private $pdf_merger;

	/**
	 * ZIP build callable.
	 *
	 * @var callable|null
	 */
	private $zip_builder;

	/**
	 * Constructor.
	 *
	 * @param Package_Loader|null  $packages    Package loader.
	 * @param Pdf_Resolver|null    $resolver    PDF resolver.
	 * @param Pdf_Validator|null   $validator   PDF validator.
	 * @param Packet_Store|null    $store       Packet store.
	 * @param Packet_Manifest|null $manifests   Manifest helper.
	 * @param callable|null        $pdf_merger  PDF merge callable (paths -> bytes|null).
	 * @param callable|null        $zip_builder ZIP build callable (resolved -> bytes|null).
	 */
	public function __construct(
		?Package_Loader $packages = null,
		?Pdf_Resolver $resolver = null,
		?Pdf_Validator $validator = null,
		?Packet_Store $store = null,
		?Packet_Manifest $manifests = null,
		?callable $pdf_merger = null,
		?callable $zip_builder = null
	) {
		$this->packages    = $packages ?? new Package_Loader();
		$this->resolver    = $resolver ?? new Pdf_Resolver();
		$this->validator   = $validator ?? new Pdf_Validator();
		$this->store       = $store ?? new Packet_Store();
		$this->manifests   = $manifests ?? new Packet_Manifest();
		$this->pdf_merger  = $pdf_merger;
		$this->zip_builder = $zip_builder;
	}

	/**
	 * Build or reuse cached packet artifacts for a package.
	 *
	 * @param string               $package_id Package enum id.
	 * @param array<string, mixed> $options    Build options.
	 * @return array<string, mixed>
	 */
	public function build( string $package_id, array $options = array() ): array {
		$package_id = trim( $package_id );

		if ( '' === $package_id ) {
			return $this->failure(
				Pdf_Validator::CODE_PACKAGE_NOT_FOUND,
				__( 'Package id is required.', 'prose-core' )
			);
		}

		$package = $this->packages->load( $package_id );

		if ( null === $package ) {
			return $this->failure(
				Pdf_Validator::CODE_PACKAGE_NOT_FOUND,
				sprintf(
					/* translators: %s: package id */
					__( 'Package %s not found.', 'prose-core' ),
					$package_id
				)
			);
		}

		$form_ids = $this->extract_form_ids( $package );
		$resolved = $this->resolver->resolve_many( $form_ids );
		$errors   = $this->validator->validate_sources( $resolved );

		if ( ! empty( $errors ) ) {
			return $this->failure(
				(string) ( $errors[0]['code'] ?? Pdf_Validator::CODE_PDF_MISSING ),
				(string) ( $errors[0]['message'] ?? __( 'Source PDF validation failed.', 'prose-core' ) ),
				$errors
			);
		}

		$fingerprint     = $this->manifests->compute_fingerprint( $package_id, $resolved );
		$force           = ! empty( $options['force'] );
		$build_pdf       = ! isset( $options['build_pdf'] ) || ! empty( $options['build_pdf'] );
		$build_zip       = ! isset( $options['build_zip'] ) || ! empty( $options['build_zip'] );
		$stored_manifest = $this->store->read_manifest( $package_id );

		if ( ! $force && $this->cache_is_valid( $package_id, $fingerprint, $stored_manifest, $build_pdf, $build_zip ) ) {
			return $this->success_from_store( $package, $stored_manifest );
		}

		$generation_errors = array();

		if ( $build_zip ) {
			$zip_bytes = $this->build_zip( $resolved );

			if ( null === $zip_bytes || '' === $zip_bytes ) {
				$generation_errors[] = array(
					'code'    => Pdf_Validator::CODE_PACKET_GEN_FAILURE,
					'message' => __( 'ZIP packet generation failed.', 'prose-core' ),
				);
			} else {
				$this->store->write_zip( $package_id, $zip_bytes );
			}
		}

		if ( $build_pdf ) {
			$pdf_bytes = $this->merge_pdfs( $resolved );

			if ( null === $pdf_bytes || '' === $pdf_bytes ) {
				$generation_errors[] = array(
					'code'    => Pdf_Validator::CODE_PACKET_GEN_FAILURE,
					'message' => __( 'Merged PDF packet generation failed.', 'prose-core' ),
				);
			} else {
				$this->store->write_pdf( $package_id, $pdf_bytes );
			}
		}

		$manifest = $this->manifests->build_record( $package, $resolved, $fingerprint, $this->store );
		$this->store->write_manifest( $package_id, $manifest );

		if ( ! empty( $generation_errors ) && ! $this->store->pdf_exists( $package_id ) && ! $this->store->zip_exists( $package_id ) ) {
			return $this->failure(
				(string) $generation_errors[0]['code'],
				(string) $generation_errors[0]['message'],
				$generation_errors
			);
		}

		$result = $this->success_from_store( $package, $manifest );

		if ( ! empty( $generation_errors ) ) {
			$result['warnings'] = $generation_errors;
		}

		return $result;
	}

	/**
	 * Build all known packages.
	 *
	 * @param array<string, mixed> $options Build options.
	 * @return array<string, mixed>
	 */
	public function build_all( array $options = array() ): array {
		$results = array();

		foreach ( array_keys( Vocabulary::package_catalog() ) as $package_id ) {
			$results[ $package_id ] = $this->build( (string) $package_id, $options );
		}

		return array(
			'success' => true,
			'results' => $results,
		);
	}

	/**
	 * Rebuild only packages whose fingerprint changed or artifacts are missing.
	 *
	 * @param array<string, mixed> $options Build options.
	 * @return array<string, mixed>
	 */
	public function rebuild_changed( array $options = array() ): array {
		$results = array();

		foreach ( array_keys( Vocabulary::package_catalog() ) as $package_id ) {
			$package_id = (string) $package_id;
			$package    = $this->packages->load( $package_id );

			if ( null === $package ) {
				continue;
			}

			$form_ids    = $this->extract_form_ids( $package );
			$resolved    = $this->resolver->resolve_many( $form_ids );
			$fingerprint = $this->manifests->compute_fingerprint( $package_id, $resolved );
			$manifest    = $this->store->read_manifest( $package_id );

			if ( $this->cache_is_valid( $package_id, $fingerprint, $manifest, true, true ) ) {
				$results[ $package_id ] = array(
					'success'  => true,
					'cached'   => true,
					'packet'   => $this->success_from_store( $package, $manifest )['packet'],
				);
				continue;
			}

			$results[ $package_id ] = $this->build( $package_id, $options );
		}

		return array(
			'success' => true,
			'results' => $results,
		);
	}

	/**
	 * Extract ordered form ids from a package definition.
	 *
	 * @param array<string, mixed> $package Package definition.
	 * @return array<int, string>
	 */
	private function extract_form_ids( array $package ): array {
		$form_ids = array();
		$seen     = array();

		foreach ( (array) ( $package['forms'] ?? array() ) as $row ) {
			$form_id = strtoupper( trim( (string) ( $row['form_id'] ?? '' ) ) );

			if ( '' === $form_id || isset( $seen[ $form_id ] ) ) {
				continue;
			}

			$seen[ $form_id ] = true;
			$form_ids[]       = $form_id;
		}

		return $form_ids;
	}

	/**
	 * Whether cached artifacts can be reused.
	 *
	 * @param string                    $package_id      Package id.
	 * @param string                    $fingerprint     Current fingerprint.
	 * @param array<string, mixed>|null $stored_manifest Stored manifest.
	 * @param bool                      $needs_pdf       Whether PDF artifact is required.
	 * @param bool                      $needs_zip       Whether ZIP artifact is required.
	 * @return bool
	 */
	private function cache_is_valid(
		string $package_id,
		string $fingerprint,
		?array $stored_manifest,
		bool $needs_pdf,
		bool $needs_zip
	): bool {
		if ( null === $stored_manifest ) {
			return false;
		}

		if ( (string) ( $stored_manifest['fingerprint'] ?? '' ) !== $fingerprint ) {
			return false;
		}

		if ( $needs_pdf && ! $this->store->pdf_exists( $package_id ) ) {
			return false;
		}

		if ( $needs_zip && ! $this->store->zip_exists( $package_id ) ) {
			return false;
		}

		return $needs_pdf || $needs_zip;
	}

	/**
	 * Build success payload from stored artifacts.
	 *
	 * @param array<string, mixed>      $package  Package definition.
	 * @param array<string, mixed>|null $manifest Stored manifest.
	 * @return array<string, mixed>
	 */
	private function success_from_store( array $package, ?array $manifest ): array {
		$package_id = (string) ( $package['package_id'] ?? '' );
		$manifest   = is_array( $manifest ) ? $manifest : $this->store->read_manifest( $package_id );

		if ( null === $manifest ) {
			$manifest = array(
				'package_id' => $package_id,
				'filename'   => $this->manifests->download_basename( $package ),
				'form_count' => count( (array) ( $package['forms'] ?? array() ) ),
				'forms'      => array(),
			);
		}

		return array(
			'success' => true,
			'packet'  => array(
				'package_id'     => $package_id,
				'filename'       => (string) ( $manifest['filename'] ?? $this->manifests->download_basename( $package ) ),
				'form_count'     => (int) ( $manifest['form_count'] ?? 0 ),
				'pdf_packet_url' => $this->store->pdf_url( $package_id ),
				'zip_packet_url' => $this->store->zip_url( $package_id ),
				'manifest'       => $this->manifests->public_manifest( $manifest ),
			),
		);
	}

	/**
	 * Merge source PDF paths into one packet.
	 *
	 * @param array<int, array{form_id: string, pdf_path: string}> $resolved Resolved forms.
	 * @return string|null
	 */
	private function merge_pdfs( array $resolved ): ?string {
		$paths = array();

		foreach ( $resolved as $row ) {
			$path = (string) ( $row['pdf_path'] ?? '' );

			if ( '' !== $path ) {
				$paths[] = $path;
			}
		}

		if ( empty( $paths ) ) {
			return null;
		}

		if ( is_callable( $this->pdf_merger ) ) {
			return call_user_func( $this->pdf_merger, $paths );
		}

		return $this->merge_with_pdftk( $paths );
	}

	/**
	 * Build a ZIP of individual blank PDFs.
	 *
	 * @param array<int, array{form_id: string, pdf_path: string}> $resolved Resolved forms.
	 * @return string|null
	 */
	private function build_zip( array $resolved ): ?string {
		if ( is_callable( $this->zip_builder ) ) {
			return call_user_func( $this->zip_builder, $resolved );
		}

		if ( ! class_exists( '\ZipArchive' ) ) {
			return null;
		}

		$tmp = tempnam( sys_get_temp_dir(), 'prose-packet-zip-' );

		if ( false === $tmp ) {
			return null;
		}

		$zip = new \ZipArchive();

		if ( true !== $zip->open( $tmp, \ZipArchive::CREATE | \ZipArchive::OVERWRITE ) ) {
			unlink( $tmp ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
			return null;
		}

		foreach ( $resolved as $row ) {
			$form_id  = (string) ( $row['form_id'] ?? 'form' );
			$pdf_path = (string) ( $row['pdf_path'] ?? '' );

			if ( '' === $pdf_path || ! is_readable( $pdf_path ) ) {
				continue;
			}

			$filename = preg_replace( '/[^A-Za-z0-9._-]/', '_', $form_id ) . '.pdf';
			$zip->addFile( $pdf_path, $filename );
		}

		if ( ! $zip->close() ) {
			unlink( $tmp ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
			return null;
		}

		$bytes = (string) file_get_contents( $tmp ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		unlink( $tmp ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink

		return '' === $bytes ? null : $bytes;
	}

	/**
	 * Merge PDF files with pdftk when available.
	 *
	 * @param array<int, string> $paths Absolute PDF paths.
	 * @return string|null
	 */
	private function merge_with_pdftk( array $paths ): ?string {
		if ( empty( $paths ) || ! class_exists( '\mikehaertl\pdftk\Pdf' ) ) {
			return null;
		}

		$sources = array();
		$handles = array();

		try {
			foreach ( $paths as $index => $path ) {
				if ( ! is_readable( $path ) ) {
					return null;
				}

				$sources[ 'F' . $index ] = $path;
			}

			$class = '\mikehaertl\pdftk\Pdf';
			$pdf   = new $class( $sources );

			foreach ( array_keys( $sources ) as $handle ) {
				$pdf->cat( null, null, $handle );
			}

			$out = tempnam( sys_get_temp_dir(), 'prose-packet-pdf-' );

			if ( false === $out || ! $pdf->saveAs( $out ) ) {
				return null;
			}

			$bytes     = (string) file_get_contents( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			$handles[] = $out;

			return '' === $bytes ? null : $bytes;
		} catch ( \Throwable $e ) {
			return null;
		} finally {
			foreach ( $handles as $handle ) {
				if ( is_string( $handle ) && file_exists( $handle ) ) {
					unlink( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
				}
			}
		}
	}

	/**
	 * Build a failure response.
	 *
	 * @param string               $code    Error code.
	 * @param string               $message Error message.
	 * @param array<int, mixed>|null $details Optional details.
	 * @return array<string, mixed>
	 */
	private function failure( string $code, string $message, ?array $details = null ): array {
		$response = array(
			'success' => false,
			'error'   => array(
				'code'    => $code,
				'message' => $message,
			),
		);

		if ( null !== $details ) {
			$response['error']['details'] = $details;
		}

		return $response;
	}
}
