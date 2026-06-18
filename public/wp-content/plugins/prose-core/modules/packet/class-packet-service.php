<?php
/**
 * Packet Service — read-only runtime API and admin/build entrypoints.
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
 * Class Packet_Service
 */
final class Packet_Service {

	/**
	 * Packet builder.
	 *
	 * @var Pdf_Packet_Builder
	 */
	private Pdf_Packet_Builder $builder;

	/**
	 * Packet store.
	 *
	 * @var Packet_Store
	 */
	private Packet_Store $store;

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
	 * Manifest helper.
	 *
	 * @var Packet_Manifest
	 */
	private Packet_Manifest $manifests;

	/**
	 * Constructor.
	 *
	 * @param Pdf_Packet_Builder|null $builder   Packet builder.
	 * @param Packet_Store|null       $store     Packet store.
	 * @param Package_Loader|null     $packages  Package loader.
	 * @param Pdf_Resolver|null       $resolver  PDF resolver.
	 * @param Pdf_Validator|null      $validator PDF validator.
	 * @param Packet_Manifest|null    $manifests Manifest helper.
	 */
	public function __construct(
		?Pdf_Packet_Builder $builder = null,
		?Packet_Store $store = null,
		?Package_Loader $packages = null,
		?Pdf_Resolver $resolver = null,
		?Pdf_Validator $validator = null,
		?Packet_Manifest $manifests = null
	) {
		$this->store     = $store ?? new Packet_Store();
		$this->packages  = $packages ?? new Package_Loader();
		$this->resolver  = $resolver ?? new Pdf_Resolver();
		$this->validator = $validator ?? new Pdf_Validator();
		$this->manifests = $manifests ?? new Packet_Manifest();
		$this->builder   = $builder ?? new Pdf_Packet_Builder(
			$this->packages,
			$this->resolver,
			$this->validator,
			$this->store,
			$this->manifests
		);
	}

	/**
	 * Read-only packet status (never generates).
	 *
	 * @param string $package_id Package enum id.
	 * @return array<string, mixed>
	 */
	public function status( string $package_id ): array {
		$package_id = trim( $package_id );
		$package    = $this->packages->load( $package_id );

		if ( null === $package ) {
			return array(
				'success' => false,
				'error'   => array(
					'code'    => Pdf_Validator::CODE_PACKAGE_NOT_FOUND,
					'message' => sprintf(
						/* translators: %s: package id */
						__( 'Package %s not found.', 'prose-core' ),
						$package_id
					),
				),
			);
		}

		$manifest = $this->store->read_manifest( $package_id );

		if ( null === $manifest ) {
			return array(
				'success' => true,
				'packet'  => array(
					'package_id'     => $package_id,
					'filename'       => $this->manifests->download_basename( $package ),
					'form_count'     => count( (array) ( $package['forms'] ?? array() ) ),
					'pdf_packet_url' => '',
					'zip_packet_url' => '',
					'manifest'       => array(
						'package_id' => $package_id,
						'form_count' => count( (array) ( $package['forms'] ?? array() ) ),
						'forms'      => array(),
					),
				),
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
	 * Resolve a download URL for a cached packet (never generates).
	 *
	 * @param string $package_id Package enum id.
	 * @return array{success: bool, download_url?: string, package_id?: string, filename?: string, error?: array{code: string, message: string}}
	 */
	public function download( string $package_id ): array {
		$package_id = trim( $package_id );

		if ( '' === $package_id ) {
			return array(
				'success' => false,
				'error'   => array(
					'code'    => Pdf_Validator::CODE_PACKAGE_NOT_FOUND,
					'message' => __( 'Package id is required.', 'prose-core' ),
				),
			);
		}

		if ( ! $this->is_available( $package_id ) ) {
			return array(
				'success' => false,
				'error'   => array(
					'code'    => 'packet_unavailable',
					'message' => __( 'The filing packet is not available for download yet.', 'prose-core' ),
				),
			);
		}

		$status = $this->status( $package_id );
		$packet = is_array( $status['packet'] ?? null ) ? $status['packet'] : array();
		$url    = (string) ( $packet['pdf_packet_url'] ?? '' );

		if ( '' === $url ) {
			$url = (string) ( $packet['zip_packet_url'] ?? '' );
		}

		if ( '' === $url ) {
			return array(
				'success' => false,
				'error'   => array(
					'code'    => 'packet_unavailable',
					'message' => __( 'No download URL is available for this packet.', 'prose-core' ),
				),
			);
		}

		$filename = (string) ( $packet['filename'] ?? $package_id );

		if ( ! str_ends_with( strtolower( $filename ), '.pdf' ) && ! str_ends_with( strtolower( $filename ), '.zip' ) ) {
			$filename .= str_contains( $url, '.zip' ) ? '.zip' : '.pdf';
		}

		return array(
			'success'      => true,
			'download_url' => $url,
			'package_id'   => $package_id,
			'filename'     => $filename,
		);
	}

	/**
	 * Whether a valid cached packet exists (never generates).
	 *
	 * @param string $package_id Package enum id.
	 * @return bool
	 */
	public function is_available( string $package_id ): bool {
		$package_id = trim( $package_id );

		if ( '' === $package_id ) {
			return false;
		}

		$manifest = $this->store->read_manifest( $package_id );

		if ( null === $manifest ) {
			return false;
		}

		if ( ! $this->store->pdf_exists( $package_id ) && ! $this->store->zip_exists( $package_id ) ) {
			return false;
		}

		$package = $this->packages->load( $package_id );

		if ( null === $package ) {
			return false;
		}

		$form_ids    = $this->extract_form_ids( $package );
		$resolved    = $this->resolver->resolve_many( $form_ids );
		$fingerprint = $this->manifests->compute_fingerprint( $package_id, $resolved );

		return (string) ( $manifest['fingerprint'] ?? '' ) === $fingerprint;
	}

	/**
	 * Alias for status() — metadata only, never generates.
	 *
	 * @param string $package_id Package enum id.
	 * @return array<string, mixed>
	 */
	public function metadata( string $package_id ): array {
		return $this->status( $package_id );
	}

	/**
	 * List all packages with dashboard row data (never generates).
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function list_packages(): array {
		$rows = array();

		foreach ( Vocabulary::package_catalog() as $package_id => $row ) {
			$rows[] = $this->dashboard_row( (string) $package_id, (array) $row );
		}

		return $rows;
	}

	/**
	 * Build dashboard row for a package.
	 *
	 * @param string               $package_id Package enum id.
	 * @param array<string, mixed> $catalog_row Catalog row.
	 * @return array<string, mixed>
	 */
	public function dashboard_row( string $package_id, array $catalog_row = array() ): array {
		$package  = $this->packages->load( $package_id );
		$manifest = $this->store->read_manifest( $package_id );
		$form_ids = $package ? $this->extract_form_ids( $package ) : array();
		$resolved = $this->resolver->resolve_many( $form_ids );
		$errors   = $this->validator->validate_sources( $resolved );
		$parts    = $this->validator->partition_source_errors( $errors );

		$status = 'not_generated';

		if ( null !== $manifest ) {
			$status = $this->is_available( $package_id ) ? 'ready' : 'stale';
		}

		return array(
			'package_id'        => $package_id,
			'package_name'      => (string) ( $package['package_name'] ?? $catalog_row['package_name'] ?? $package_id ),
			'form_count'        => count( $form_ids ),
			'packet_status'     => $status,
			'pdf_exists'        => $this->store->pdf_exists( $package_id ),
			'zip_exists'        => $this->store->zip_exists( $package_id ),
			'missing_pdfs'      => $parts['missing'],
			'invalid_pdfs'        => $parts['invalid'],
			'packet_size'       => $this->store->total_size( $package_id ),
			'last_generated'    => (string) ( $manifest['last_generated'] ?? '' ),
			'pdf_packet_url'    => $this->store->pdf_url( $package_id ),
			'zip_packet_url'    => $this->store->zip_url( $package_id ),
		);
	}

	/**
	 * Admin: build packet artifacts.
	 *
	 * @param string               $package_id Package enum id.
	 * @param array<string, mixed> $options    Build options.
	 * @return array<string, mixed>
	 */
	public function build( string $package_id, array $options = array() ): array {
		return $this->builder->build( $package_id, $options );
	}

	/**
	 * Admin: force rebuild packet artifacts.
	 *
	 * @param string               $package_id Package enum id.
	 * @param array<string, mixed> $options    Build options.
	 * @return array<string, mixed>
	 */
	public function rebuild( string $package_id, array $options = array() ): array {
		$options['force'] = true;

		return $this->builder->build( $package_id, $options );
	}

	/**
	 * Admin: build all packages.
	 *
	 * @param array<string, mixed> $options Build options.
	 * @return array<string, mixed>
	 */
	public function build_all( array $options = array() ): array {
		return $this->builder->build_all( $options );
	}

	/**
	 * Admin: rebuild only changed packages.
	 *
	 * @param array<string, mixed> $options Build options.
	 * @return array<string, mixed>
	 */
	public function rebuild_changed( array $options = array() ): array {
		return $this->builder->rebuild_changed( $options );
	}

	/**
	 * Validate a package and its cached artifacts.
	 *
	 * @param string $package_id Package enum id.
	 * @return array<string, mixed>
	 */
	public function validate( string $package_id ): array {
		$package_id = trim( $package_id );
		$package    = $this->packages->load( $package_id );

		if ( null === $package ) {
			return array(
				'success' => false,
				'error'   => array(
					'code'    => Pdf_Validator::CODE_PACKAGE_NOT_FOUND,
					'message' => sprintf(
						/* translators: %s: package id */
						__( 'Package %s not found.', 'prose-core' ),
						$package_id
					),
				),
			);
		}

		$form_ids = $this->extract_form_ids( $package );
		$resolved = $this->resolver->resolve_many( $form_ids );
		$errors   = $this->validator->validate_sources( $resolved );
		$manifest = $this->store->read_manifest( $package_id );
		$errors   = array_merge( $errors, $this->validator->validate_artifacts( $this->store, $package_id, $manifest ) );

		if ( ! empty( $errors ) ) {
			return array(
				'success' => false,
				'errors'  => $errors,
			);
		}

		return array(
			'success' => true,
			'valid'   => true,
		);
	}

	/**
	 * Validate all packages.
	 *
	 * @return array<string, mixed>
	 */
	public function validate_all(): array {
		$results = array();

		foreach ( array_keys( Vocabulary::package_catalog() ) as $package_id ) {
			$results[ (string) $package_id ] = $this->validate( (string) $package_id );
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
}
