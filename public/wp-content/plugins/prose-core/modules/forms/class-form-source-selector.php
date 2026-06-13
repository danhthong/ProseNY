<?php
/**
 * Form source priority and downstream generation readiness logic.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Forms;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Form_Source_Selector
 *
 * Single source of truth for preferred/editable source, fillable strategy,
 * and generation readiness.
 */
final class Form_Source_Selector {

	/**
	 * Source slot priority (highest first).
	 */
	private const SLOT_PRIORITY = array(
		'docx',
		'converted_docx',
		'fillable_pdf',
		'pdf',
	);

	/**
	 * Resolve preferred source slot from available source_files.
	 *
	 * @param array<string, array<string, mixed>> $source_files Source file slots.
	 * @return string Preferred slot key or empty string.
	 */
	public static function preferred_source( array $source_files ): string {
		foreach ( self::SLOT_PRIORITY as $slot ) {
			if ( self::slot_is_usable( $source_files, $slot ) ) {
				return $slot;
			}
		}

		return '';
	}

	/**
	 * Resolve editable source type from available source_files.
	 *
	 * @param array<string, array<string, mixed>> $source_files Source file slots.
	 * @return string One of docx, converted_docx, fillable_pdf, pdf, or empty.
	 */
	public static function editable_source( array $source_files ): string {
		$preferred = self::preferred_source( $source_files );

		if ( '' === $preferred ) {
			return '';
		}

		return $preferred;
	}

	/**
	 * Determine how the form will eventually be filled.
	 *
	 * @param string                              $editable_source Resolved editable source.
	 * @param array<string, array<string, mixed>> $source_files    Source file slots.
	 * @return string One of docx_template, pdf_acroform, pdf_overlay, none.
	 */
	public static function fillable_strategy( string $editable_source, array $source_files ): string {
		if ( in_array( $editable_source, array( 'docx', 'converted_docx' ), true ) ) {
			return 'docx_template';
		}

		if ( 'fillable_pdf' === $editable_source ) {
			return 'pdf_acroform';
		}

		if ( 'pdf' === $editable_source && self::slot_is_usable( $source_files, 'pdf' ) ) {
			return 'pdf_overlay';
		}

		return 'none';
	}

	/**
	 * Whether the form has a usable asset for downstream generation.
	 *
	 * @param string                              $fillable_strategy Resolved fillable strategy.
	 * @param string                              $preferred_source  Preferred source slot.
	 * @param array<string, array<string, mixed>> $source_files      Source file slots.
	 * @return bool
	 */
	public static function generation_ready( string $fillable_strategy, string $preferred_source, array $source_files ): bool {
		if ( 'none' === $fillable_strategy || '' === $preferred_source ) {
			return false;
		}

		$slot = $source_files[ $preferred_source ] ?? null;

		if ( ! is_array( $slot ) ) {
			return false;
		}

		$path   = (string) ( $slot['path'] ?? '' );
		$status = (string) ( $slot['download_status'] ?? '' );

		if ( in_array( $status, array( 'failed', 'unsupported' ), true ) ) {
			return false;
		}

		if ( '' !== $path && is_readable( $path ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Compute all derived source fields for a record.
	 *
	 * @param array<string, array<string, mixed>> $source_files Source file slots.
	 * @return array{
	 *     preferred_source: string,
	 *     editable_source: string,
	 *     fillable_strategy: string,
	 *     generation_ready: bool
	 * }
	 */
	public static function compute( array $source_files ): array {
		$preferred       = self::preferred_source( $source_files );
		$editable        = self::editable_source( $source_files );
		$strategy        = self::fillable_strategy( $editable, $source_files );
		$generation_ready = self::generation_ready( $strategy, $preferred, $source_files );

		return array(
			'preferred_source'  => $preferred,
			'editable_source'   => $editable,
			'fillable_strategy' => $strategy,
			'generation_ready'  => $generation_ready,
		);
	}

	/**
	 * Whether a source slot has a usable file entry.
	 *
	 * @param array<string, array<string, mixed>> $source_files Source file slots.
	 * @param string                              $slot         Slot key.
	 * @return bool
	 */
	private static function slot_is_usable( array $source_files, string $slot ): bool {
		$entry = $source_files[ $slot ] ?? null;

		if ( ! is_array( $entry ) ) {
			return false;
		}

		$status = (string) ( $entry['download_status'] ?? '' );

		if ( in_array( $status, array( 'failed', 'unsupported' ), true ) ) {
			return false;
		}

		$path     = (string) ( $entry['path'] ?? '' );
		$filename = (string) ( $entry['filename'] ?? '' );
		$url      = (string) ( $entry['source_url'] ?? '' );

		if ( '' !== $path && is_readable( $path ) ) {
			return true;
		}

		return '' !== $filename || '' !== $url;
	}
}
