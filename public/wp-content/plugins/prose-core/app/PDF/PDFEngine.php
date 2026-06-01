<?php
/**
 * PDF autofill engine using pdftk.
 *
 * @package ProseCore
 */

namespace Prose\Core\PDF;

use Prose\Core\Database\Repositories\FormMappingRepository;
use Prose\Core\Forms\DataResolver;
use Prose\Core\Forms\FormRegistry;
use mikehaertl\pdftk\Pdf;
use RuntimeException;

final class PDFEngine {

	public function __construct(
		private readonly FormRegistry $registry,
		private readonly FormMappingRepository $mappings,
		private readonly DataResolver $resolver,
		private readonly SummaryPdfWriter $summary_pdf
	) {}

	/**
	 * @param array<string, mixed> $facts
	 */
	public function fill( string $form_slug, array $facts, string $output_path ): string {
		$form = $this->registry->get_by_slug( $form_slug );

		if ( ! $form ) {
			throw new RuntimeException( "Form not found: {$form_slug}" );
		}

		$field_data  = $this->build_field_data( $form, $facts );
		$template    = (string) $form['pdf_path'];
		$use_summary = ! file_exists( $template );

		wp_mkdir_p( dirname( $output_path ) );

		if ( $use_summary ) {
			$lines = array(
				__( 'DRAFT — Official PDF template not uploaded yet.', 'prose-core' ),
				__( 'Upload the blank court form in CourtFlow → Official Forms.', 'prose-core' ),
				'',
			);
			foreach ( $field_data as $field => $value ) {
				$lines[] = $field . ': ' . $value;
			}
			if ( count( $lines ) <= 3 ) {
				$lines[] = __( 'No mapped field values were captured for this form.', 'prose-core' );
			}
			$this->summary_pdf->write(
				strtoupper( $form_slug ) . ' — ' . ( $form['title'] ?? $form_slug ),
				$lines,
				$output_path
			);
			return $output_path;
		}

		$pdf = new Pdf( $template );
		$pdf->fillForm( $field_data )->flatten()->saveAs( $output_path );

		if ( $pdf->getError() ) {
			throw new RuntimeException( 'PDF fill failed: ' . $pdf->getError() );
		}

		return $output_path;
	}

	/**
	 * @param array<string, mixed> $form
	 * @param array<string, mixed> $facts
	 * @return array<string, string>
	 */
	private function build_field_data( array $form, array $facts ): array {
		$field_data  = array();
		$db_mappings = ! empty( $form['id'] ) ? $this->mappings->for_form( (int) $form['id'] ) : array();

		if ( ! empty( $db_mappings ) ) {
			foreach ( $db_mappings as $map ) {
				$value = $this->resolver->resolve( $map['source_path'], $facts, $map['transform'] );
				if ( null !== $value && '' !== $value ) {
					$field_data[ $map['field_name'] ] = (string) $value;
				}
			}
			return $field_data;
		}

		foreach ( $form['mappings'] as $field => $path ) {
			$value = $this->resolver->resolve( (string) $path, $facts );
			if ( null !== $value && '' !== $value ) {
				$field_data[ (string) $field ] = (string) $value;
			}
		}

		return $field_data;
	}

	public function hash_file( string $path ): string {
		return hash_file( 'sha256', $path ) ?: '';
	}
}
