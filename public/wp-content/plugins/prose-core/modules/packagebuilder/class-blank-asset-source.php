<?php
/**
 * Blank Asset Source — resolves canonical, unmodified court assets.
 *
 * Reuses Form_Source_Selector so source priority and generation readiness are
 * never re-implemented or guessed here.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\PackageBuilder;

use ProSe\Core\Forms\Form_Source_Selector;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Blank_Asset_Source
 */
final class Blank_Asset_Source implements Asset_Source {

	/**
	 * Resolve a form record into a manifest form entry.
	 *
	 * @param array<string, mixed> $form_record Canonical form record (empty when missing).
	 * @param string               $code        Requested form code.
	 * @param string               $stage       Workflow stage label.
	 * @param string               $requirement Requirement level (required|optional).
	 * @return array<string, mixed>
	 */
	public function resolve( array $form_record, string $code, string $stage, string $requirement ): array {
		if ( empty( $form_record ) ) {
			return array(
				'code'              => $code,
				'title'             => '',
				'stage'             => $stage,
				'requirement'      => $requirement,
				'asset_type'       => '',
				'asset_path'       => '',
				'fillable_strategy' => 'none',
				'generation_ready'  => false,
				'fill_status'      => 'not_applicable',
				'fields'           => array(),
				'error'            => sprintf( 'Form %s not found in Forms Repository.', $code ),
			);
		}

		$source_files = is_array( $form_record['source_files'] ?? null ) ? $form_record['source_files'] : array();

		// Prefer the record's precomputed values, fall back to the selector.
		$asset_type = (string) ( $form_record['preferred_source'] ?? '' );

		if ( '' === $asset_type ) {
			$asset_type = Form_Source_Selector::preferred_source( $source_files );
		}

		$asset_path = '';

		if ( '' !== $asset_type && isset( $source_files[ $asset_type ] ) && is_array( $source_files[ $asset_type ] ) ) {
			$asset_path = (string) ( $source_files[ $asset_type ]['path'] ?? '' );
		}

		if ( ( '' === $asset_path || ! is_readable( $asset_path ) ) && isset( $source_files['pdf'] ) && is_array( $source_files['pdf'] ) ) {
			$pdf_path = (string) ( $source_files['pdf']['path'] ?? '' );

			if ( '' !== $pdf_path && is_readable( $pdf_path ) ) {
				$asset_path = $pdf_path;
				$asset_type = 'pdf';
			}
		}

		if ( ( '' === $asset_path || ! is_readable( $asset_path ) ) && ! empty( $form_record['generation_ready'] ) ) {
			$pdf_path = ( new \ProSe\Core\Forms\Form_Pdf_Path_Resolver() )->resolve_for_code( $code );

			if ( '' !== $pdf_path && is_readable( $pdf_path ) ) {
				$asset_path = $pdf_path;
				$asset_type = 'pdf';
			}
		}

		$fillable_strategy = (string) ( $form_record['fillable_strategy'] ?? '' );

		if ( '' === $fillable_strategy ) {
			$fillable_strategy = Form_Source_Selector::fillable_strategy( $asset_type, $source_files );
		}

		// Trust overlay/enricher readiness; require a readable asset on disk.
		$generation_ready = ! empty( $form_record['generation_ready'] )
			&& '' !== $asset_path
			&& is_readable( $asset_path );

		if ( ! $generation_ready ) {
			$generation_ready = Form_Source_Selector::generation_ready( $fillable_strategy, $asset_type, $source_files );
		}

		return array(
			'code'              => (string) ( $form_record['form_code'] ?? $code ),
			'title'             => (string) ( $form_record['title'] ?? '' ),
			'stage'             => $stage,
			'requirement'      => $requirement,
			'asset_type'       => $asset_type,
			'asset_path'       => $asset_path,
			'fillable_strategy' => $fillable_strategy,
			'generation_ready'  => $generation_ready,
			'fill_status'      => 'not_applicable',
			'fields'           => array(),
		);
	}

	/**
	 * Absolute path to the canonical bytes for a form entry.
	 *
	 * @param array<string, mixed> $form_entry Manifest form entry.
	 * @return string|null
	 */
	public function open( array $form_entry ): ?string {
		$path = (string) ( $form_entry['asset_path'] ?? '' );

		if ( '' === $path || ! is_readable( $path ) ) {
			return null;
		}

		return $path;
	}
}
