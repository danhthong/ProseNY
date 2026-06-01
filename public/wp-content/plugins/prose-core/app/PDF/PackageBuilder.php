<?php
/**
 * Filing package ZIP builder.
 *
 * @package ProseCore
 */

namespace Prose\Core\PDF;

use Prose\Core\Database\Repositories\DocumentRepository;
use Prose\Core\Forms\FormRegistry;
use RuntimeException;
use ZipArchive;

final class PackageBuilder {

	public function __construct(
		private readonly PDFEngine $pdf,
		private readonly DocumentRepository $documents
	) {}

	/**
	 * @param array<string, mixed> $facts
	 * @param array<int, string> $form_slugs
	 * @return array<string, mixed>
	 */
	public function build( int $session_id, array $facts, array $form_slugs ): array {
		if ( empty( $form_slugs ) ) {
			throw new RuntimeException( __( 'No forms are required for this case yet.', 'prose-core' ) );
		}

		$upload_dir = wp_upload_dir();
		$base       = trailingslashit( $upload_dir['basedir'] ) . 'courtflow/' . $session_id;
		wp_mkdir_p( $base );

		$generated = array();
		$errors    = array();

		foreach ( $form_slugs as $slug ) {
			$output     = $base . '/' . strtoupper( $slug ) . '_filled.pdf';
			$hash_input = wp_json_encode( array( 'facts' => $facts, 'form' => $slug ) );
			$hash       = hash( 'sha256', $hash_input );

			$existing = $this->documents->find_by_hash( $hash );
			if ( $existing && file_exists( $existing['storage_path'] ) ) {
				$generated[] = $existing;
				continue;
			}

			try {
				$this->pdf->fill( $slug, $facts, $output );
			} catch ( RuntimeException $e ) {
				$errors[] = array(
					'form'    => strtoupper( (string) $slug ),
					'message' => $e->getMessage(),
				);
				continue;
			}

			if ( ! file_exists( $output ) ) {
				$errors[] = array(
					'form'    => strtoupper( (string) $slug ),
					'message' => __( 'PDF file was not created.', 'prose-core' ),
				);
				continue;
			}

			$file_hash = $this->pdf->hash_file( $output );
			$doc_id    = $this->documents->create(
				array(
					'session_id'   => $session_id,
					'form_slug'    => $slug,
					'storage_path' => $output,
					'file_hash'    => $file_hash ?: $hash,
				)
			);

			$generated[] = array(
				'id'            => $doc_id,
				'form_slug'     => strtoupper( (string) $slug ),
				'storage_path'  => $output,
				'path'          => $output,
				'status'        => 'ready',
			);
		}

		if ( empty( $generated ) ) {
			throw new RuntimeException(
				$errors[0]['message'] ?? __( 'Could not generate any documents.', 'prose-core' )
			);
		}

		$zip_path = $base . '/filing_package_' . gmdate( 'Ymd_His' ) . '.zip';
		$zip      = new ZipArchive();

		if ( true !== $zip->open( $zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
			throw new RuntimeException( 'Could not create ZIP package.' );
		}

		foreach ( $generated as $doc ) {
			$path = $doc['storage_path'] ?? $doc['path'] ?? '';
			if ( $path && file_exists( $path ) ) {
				$zip->addFile( $path, basename( $path ) );
			}
		}

		$instructions = $this->build_instructions( $facts, $form_slugs );
		$instr_path   = $base . '/INSTRUCTIONS.txt';
		file_put_contents( $instr_path, $instructions );
		$zip->addFile( $instr_path, 'INSTRUCTIONS.txt' );
		$zip->close();

		return array(
			'documents' => $generated,
			'zip_path'  => $zip_path,
			'errors'    => $errors,
		);
	}

	/**
	 * @param array<int, string> $form_slugs
	 */
	private function build_instructions( array $facts, array $form_slugs ): string {
		$court = $facts['case']['court'] ?? 'Supreme Court';
		$lines = array(
			'CourtFlow AI — Filing Package Instructions',
			'==========================================',
			'',
			'This package contains procedurally generated court forms.',
			'CourtFlow AI does not provide legal advice.',
			'',
			'Suggested filing court: ' . $court,
			'',
			'Forms included:',
		);

		foreach ( $form_slugs as $slug ) {
			$lines[] = '  - ' . $slug;
		}

		$lines[] = '';
		$lines[] = 'Review all forms for accuracy before filing.';
		$lines[] = 'Sign where required. Attach any required supporting documents.';

		return implode( "\n", $lines );
	}
}
