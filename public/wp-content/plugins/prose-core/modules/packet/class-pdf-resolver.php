<?php
/**
 * PDF Resolver — maps form_id to a blank source PDF path.
 *
 * Resolution order:
 *  1. Forms Repository JSON (docs/forms) with prose_form overlay
 *  2. Direct prose_form lookup when JSON is missing or paths are empty
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Packet;

use ProSe\Core\Forms\Form_Pdf_Path_Resolver;
use ProSe\Core\Forms\Form_Source_Selector;
use ProSe\Core\Forms\Forms_Catalog;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Pdf_Resolver
 */
class Pdf_Resolver {

	/**
	 * Source slots checked for blank PDF assets (highest priority first).
	 */
	private const PDF_SLOTS = array(
		'pdf',
		'fillable_pdf',
	);

	/**
	 * Forms catalog.
	 *
	 * @var Forms_Catalog
	 */
	private Forms_Catalog $catalog;

	/**
	 * Constructor.
	 *
	 * @param Forms_Catalog|null $catalog Forms catalog.
	 */
	public function __construct( ?Forms_Catalog $catalog = null ) {
		$this->catalog = $catalog ?? new Forms_Catalog();
	}

	/**
	 * Resolve a form id to its blank PDF path.
	 *
	 * @param string $form_id Form code (e.g. UD-1).
	 * @return array{form_id: string, pdf_path: string}
	 */
	public function resolve( string $form_id ): array {
		$form_id = strtoupper( trim( $form_id ) );

		if ( '' === $form_id ) {
			return array(
				'form_id'  => '',
				'pdf_path' => '',
			);
		}

		$record = $this->catalog->by_code( $form_id );

		if ( ! is_array( $record ) ) {
			$pdf_path = ( new Form_Pdf_Path_Resolver() )->resolve_for_code( $form_id );

			return array(
				'form_id'  => $form_id,
				'pdf_path' => $pdf_path,
			);
		}

		$source_files = is_array( $record['source_files'] ?? null ) ? $record['source_files'] : array();
		$pdf_path     = $this->resolve_pdf_path_from_source_files( $source_files );

		if ( '' === $pdf_path ) {
			$pdf_path = ( new Form_Pdf_Path_Resolver() )->resolve_for_code( $form_id );
		}

		return array(
			'form_id'  => $form_id,
			'pdf_path' => $pdf_path,
		);
	}

	/**
	 * Resolve many form ids.
	 *
	 * @param array<int, string> $form_ids Form codes.
	 * @return array<int, array{form_id: string, pdf_path: string}>
	 */
	public function resolve_many( array $form_ids ): array {
		$resolved = array();

		foreach ( $form_ids as $form_id ) {
			$resolved[] = $this->resolve( (string) $form_id );
		}

		return $resolved;
	}

	/**
	 * Resolve PDF path from source_files, preferring the pdf slot.
	 *
	 * @param array<string, array<string, mixed>> $source_files Source file slots.
	 * @return string
	 */
	private function resolve_pdf_path_from_source_files( array $source_files ): string {
		foreach ( self::PDF_SLOTS as $slot ) {
			if ( ! isset( $source_files[ $slot ] ) || ! is_array( $source_files[ $slot ] ) ) {
				continue;
			}

			$slot_data = $source_files[ $slot ];
			$path      = (string) ( $slot_data['path'] ?? '' );
			$status    = (string) ( $slot_data['download_status'] ?? '' );

			if ( in_array( $status, array( 'failed', 'unsupported' ), true ) ) {
				continue;
			}

			if ( '' !== $path && is_readable( $path ) ) {
				return $path;
			}
		}

		$preferred = Form_Source_Selector::preferred_source( $source_files );

		if ( in_array( $preferred, self::PDF_SLOTS, true ) && isset( $source_files[ $preferred ] ) ) {
			$path = (string) ( $source_files[ $preferred ]['path'] ?? '' );

			if ( '' !== $path && is_readable( $path ) ) {
				return $path;
			}
		}

		return '';
	}
}
