<?php
/**
 * PDF analysis service — text extraction, fillable detection, field normalization.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Forms;

use ProSe\Core\Forms\Classification\Field_Normalizer;
use ProSe\Core\Forms\Pdf\Pdf_Engine_Factory;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Pdf_Analyzer
 */
class Pdf_Analyzer {

	/**
	 * Form repository.
	 *
	 * @var Form_Repository
	 */
	private Form_Repository $repository;

	/**
	 * Field normalizer.
	 *
	 * @var Field_Normalizer
	 */
	private Field_Normalizer $normalizer;

	/**
	 * Constructor.
	 *
	 * @param Form_Repository  $repository Form repository.
	 * @param Field_Normalizer $normalizer Field normalizer.
	 */
	public function __construct( Form_Repository $repository, Field_Normalizer $normalizer ) {
		$this->repository = $repository;
		$this->normalizer = $normalizer;
	}

	/**
	 * Analyze a form's PDF and persist metadata.
	 *
	 * @param int $post_id Form post ID.
	 * @return array<string, mixed>
	 */
	public function analyze( int $post_id ): array {
		$file_path = $this->resolve_pdf_path( $post_id );

		if ( '' === $file_path ) {
			return array(
				'success' => false,
				'message' => __( 'No PDF file found for this form.', 'prose-core' ),
			);
		}

		$text   = $this->extract_text( $file_path );
		$fields = $this->extract_fields( $file_path );
		$normalized = $this->normalize_fields( $fields );

		$data = array(
			'text'            => $text,
			'fillable'        => $this->detect_fillable( $fields ),
			'field_count'     => count( $fields ),
			'fields_json'     => array(
				'field_count' => count( $fields ),
				'fields'      => array_column( $fields, 'name' ),
			),
			'fillable_fields' => $normalized,
			'analyzed_at'     => gmdate( 'c' ),
			'engine'          => Pdf_Engine_Factory::get_engine()->get_id(),
		);

		$this->save_metadata( $post_id, $data );

		return array_merge( array( 'success' => true ), $data );
	}

	/**
	 * Extract text from a PDF file (first 3 pages).
	 *
	 * @param string $file_path Local PDF path.
	 * @return string
	 */
	public function extract_text( string $file_path ): string {
		return Pdf_Engine_Factory::get_engine()->extract_text( $file_path, 3 );
	}

	/**
	 * Whether the PDF has fillable AcroForm fields.
	 *
	 * @param array<int, array<string, mixed>>|string $fields_or_path Fields array or file path.
	 * @return bool
	 */
	public function detect_fillable( $fields_or_path ): bool {
		if ( is_string( $fields_or_path ) ) {
			$fields_or_path = $this->extract_fields( $fields_or_path );
		}

		return is_array( $fields_or_path ) && count( $fields_or_path ) > 0;
	}

	/**
	 * Extract raw fields from a PDF file.
	 *
	 * @param string $file_path Local PDF path.
	 * @return array<int, array<string, mixed>>
	 */
	public function extract_fields( string $file_path ): array {
		return Pdf_Engine_Factory::get_engine()->extract_fields( $file_path );
	}

	/**
	 * Normalize extracted PDF fields into ProSe schema.
	 *
	 * @param array<int, array<string, mixed>> $fields Raw fields.
	 * @return array<int, array<string, mixed>>
	 */
	public function normalize_fields( array $fields ): array {
		return $this->normalizer->normalize( $fields );
	}

	/**
	 * Save PDF analysis metadata to a form post.
	 *
	 * @param int                  $post_id Form post ID.
	 * @param array<string, mixed> $data    Analysis data.
	 * @return bool
	 */
	public function save_metadata( int $post_id, array $data ): bool {
		return $this->repository->update_pdf_metadata(
			$post_id,
			array(
				'fillable'        => (bool) ( $data['fillable'] ?? false ),
				'field_count'     => (int) ( $data['field_count'] ?? 0 ),
				'fields_json'     => $data['fields_json'] ?? array(),
				'analyzed_at'     => (string) ( $data['analyzed_at'] ?? gmdate( 'c' ) ),
				'fillable_fields' => $data['fillable_fields'] ?? array(),
			)
		);
	}

	/**
	 * Resolve local PDF path for a form post.
	 *
	 * @param int $post_id Post ID.
	 * @return string
	 */
	public function resolve_pdf_path( int $post_id ): string {
		$file_name = (string) get_post_meta( $post_id, Form_Meta::META_FILE_NAME, true );

		if ( '' === $file_name ) {
			return $this->resolve_pdf_path_from_source_files( $post_id );
		}

		$form_code    = (string) get_post_meta( $post_id, Form_Meta::META_FORM_CODE, true );
		$file_manager = new Form_File_Manager();
		$path         = $file_manager->resolve_local_path( sanitize_title( $form_code ), $file_name );

		if ( '' !== $path ) {
			return $path;
		}

		return $this->resolve_pdf_path_from_source_files( $post_id );
	}

	/**
	 * Resolve a PDF path from multi-file source metadata.
	 *
	 * @param int $post_id Post ID.
	 * @return string
	 */
	private function resolve_pdf_path_from_source_files( int $post_id ): string {
		$raw = get_post_meta( $post_id, Form_Meta::META_SOURCE_FILES, true );

		if ( is_string( $raw ) && '' !== trim( $raw ) ) {
			$decoded = json_decode( $raw, true );
		} elseif ( is_array( $raw ) ) {
			$decoded = $raw;
		} else {
			return '';
		}

		if ( ! is_array( $decoded ) || empty( $decoded['files'] ) || ! is_array( $decoded['files'] ) ) {
			return '';
		}

		$form_code    = (string) get_post_meta( $post_id, Form_Meta::META_FORM_CODE, true );
		$file_manager = new Form_File_Manager();

		foreach ( $decoded['files'] as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}

			$extension = strtolower( (string) ( $entry['extension'] ?? '' ) );
			$filename  = (string) ( $entry['filename'] ?? '' );

			if ( 'pdf' !== $extension && ! str_ends_with( strtolower( $filename ), '.pdf' ) ) {
				continue;
			}

			$local_path = (string) ( $entry['local_path'] ?? '' );

			if ( '' !== $local_path && is_readable( $local_path ) ) {
				return $local_path;
			}

			if ( '' !== $filename ) {
				$resolved = $file_manager->resolve_local_path( sanitize_title( $form_code ), $filename );

				if ( '' !== $resolved ) {
					return $resolved;
				}
			}
		}

		return '';
	}
}
