<?php
/**
 * Packet Manifest — fingerprint, filenames, and manifest records.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Packet;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Packet_Manifest
 */
final class Packet_Manifest {

	/**
	 * Compute a source fingerprint for cache invalidation.
	 *
	 * @param string                                              $package_id Package enum id.
	 * @param array<int, array{form_id: string, pdf_path: string}> $resolved   Resolved forms in order.
	 * @return string
	 */
	public function compute_fingerprint( string $package_id, array $resolved ): string {
		$parts   = array( strtoupper( trim( $package_id ) ) );
		$form_ids = array();

		foreach ( $resolved as $row ) {
			$form_id  = (string) ( $row['form_id'] ?? '' );
			$pdf_path = (string) ( $row['pdf_path'] ?? '' );

			if ( '' === $form_id ) {
				continue;
			}

			$form_ids[] = $form_id;
			$parts[]    = $form_id;

			if ( '' !== $pdf_path && is_readable( $pdf_path ) ) {
				$parts[] = (string) filesize( $pdf_path );
				$parts[] = (string) filemtime( $pdf_path );
			} else {
				$parts[] = '0';
				$parts[] = '0';
			}
		}

		$parts[] = implode( ',', $form_ids );

		return hash( 'sha256', implode( '|', $parts ) );
	}

	/**
	 * Build a manifest record for storage.
	 *
	 * @param array<string, mixed>                                $package  Normalized package definition.
	 * @param array<int, array{form_id: string, pdf_path: string}> $resolved Resolved forms.
	 * @param string                                              $fingerprint Source fingerprint.
	 * @param Packet_Store                                        $store    Packet store.
	 * @return array<string, mixed>
	 */
	public function build_record(
		array $package,
		array $resolved,
		string $fingerprint,
		Packet_Store $store
	): array {
		$package_id   = (string) ( $package['package_id'] ?? '' );
		$package_name = (string) ( $package['package_name'] ?? $package_id );
		$form_entries = array();

		foreach ( $resolved as $row ) {
			$form_id = (string) ( $row['form_id'] ?? '' );

			if ( '' === $form_id ) {
				continue;
			}

			$form_entries[] = array(
				'form_id' => $form_id,
			);
		}

		$filename = $this->download_basename( $package );

		return array(
			'package_id'     => $package_id,
			'package_name'   => $package_name,
			'filename'       => $filename,
			'form_count'     => count( $form_entries ),
			'forms'          => $form_entries,
			'fingerprint'    => $fingerprint,
			'pdf'            => array(
				'exists' => $store->pdf_exists( $package_id ),
				'size'   => $store->pdf_size( $package_id ),
			),
			'zip'            => array(
				'exists' => $store->zip_exists( $package_id ),
				'size'   => $store->zip_size( $package_id ),
			),
			'last_generated' => function_exists( 'current_time' ) ? current_time( 'mysql' ) : gmdate( 'Y-m-d H:i:s' ),
		);
	}

	/**
	 * Derive a human-readable download basename (without extension).
	 *
	 * @param array<string, mixed> $package Package definition.
	 * @return string
	 */
	public function download_basename( array $package ): string {
		$name = (string) ( $package['package_name'] ?? $package['package_id'] ?? 'packet' );
		$name = strtolower( $name );
		$name = preg_replace( '/[^a-z0-9]+/', '-', $name );
		$name = trim( (string) $name, '-' );

		if ( '' === $name ) {
			$name = 'ny-packet';
		}

		if ( ! str_starts_with( $name, 'ny-' ) ) {
			$name = 'ny-' . $name;
		}

		if ( ! str_ends_with( $name, '-packet' ) ) {
			$name .= '-packet';
		}

		return $name;
	}

	/**
	 * Build the public output manifest subset.
	 *
	 * @param array<string, mixed> $record Stored manifest record.
	 * @return array<string, mixed>
	 */
	public function public_manifest( array $record ): array {
		return array(
			'package_id'   => (string) ( $record['package_id'] ?? '' ),
			'form_count'   => (int) ( $record['form_count'] ?? 0 ),
			'forms'        => is_array( $record['forms'] ?? null ) ? $record['forms'] : array(),
			'fingerprint'  => (string) ( $record['fingerprint'] ?? '' ),
			'last_generated' => (string) ( $record['last_generated'] ?? '' ),
		);
	}
}
