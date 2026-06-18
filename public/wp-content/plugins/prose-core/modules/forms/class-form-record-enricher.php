<?php
/**
 * Enrich Forms Repository JSON records from prose_form asset metadata.
 *
 * Option 2: procedural fields stay in JSON; posts contribute files and PDF analysis only.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Forms;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Form_Record_Enricher
 */
final class Form_Record_Enricher {

	/**
	 * Merge asset slots and computed readiness from a prose_form post.
	 *
	 * Does not overwrite title, court, workflow refs, or aliases.
	 *
	 * @param array<string, mixed> $record Catalog record.
	 * @param \WP_Post             $post   Form post.
	 * @return array<string, mixed>
	 */
	public function enrich_assets_from_post( array $record, \WP_Post $post ): array {
		$slots = (array) ( $record['source_files'] ?? array() );

		$source_files_meta = get_post_meta( $post->ID, Form_Meta::META_SOURCE_FILES, true );

		if ( is_array( $source_files_meta ) && ! empty( $source_files_meta['files'] ) ) {
			foreach ( (array) $source_files_meta['files'] as $entry ) {
				if ( ! is_array( $entry ) ) {
					continue;
				}

				$filename  = (string) ( $entry['filename'] ?? '' );
				$extension = strtolower( (string) ( $entry['extension'] ?? pathinfo( $filename, PATHINFO_EXTENSION ) ) );
				$slot      = $this->classify_slot( $filename, $extension );

				if ( null === $slot ) {
					continue;
				}

				$slots[ $slot ] = array(
					'filename'        => $filename,
					'path'            => (string) ( $entry['local_path'] ?? '' ),
					'source_url'      => (string) ( $entry['source_url'] ?? '' ),
					'download_status' => (string) ( $entry['download_status'] ?? 'unknown' ),
				);
			}
		}

		$slots = $this->merge_legacy_file_meta( $post, $slots );

		$is_fillable = (bool) get_post_meta( $post->ID, Form_Meta::META_PDF_FILLABLE, true );

		if ( $is_fillable ) {
			if ( isset( $slots['fillable_pdf'] ) ) {
				$slots['fillable_pdf']['download_status'] = 'success';
			} elseif ( isset( $slots['pdf'] ) ) {
				$slots['fillable_pdf'] = $slots['pdf'];
				unset( $slots['pdf'] );
			}
		}

		$record['source_files'] = $slots;

		if ( isset( $slots['wpd'] ) ) {
			$record['wpd_conversion'] = array(
				'original_wpd'   => (string) ( $slots['wpd']['path'] ?? '' ),
				'converted_docx' => $record['wpd_conversion']['converted_docx'] ?? null,
			);
		}

		if ( isset( $slots['converted_docx'] ) ) {
			$record['wpd_conversion']['converted_docx'] = (string) ( $slots['converted_docx']['path'] ?? '' );
		}

		return $record;
	}

	/**
	 * Optional metadata merge for bulk repository builds (not runtime asset sync).
	 *
	 * @param array<string, mixed> $record Record.
	 * @param \WP_Post|null        $post   Form post.
	 * @return array<string, mixed>
	 */
	public function enrich_metadata_from_post( array $record, ?\WP_Post $post ): array {
		if ( ! $post instanceof \WP_Post ) {
			return $record;
		}

		$stored_code = (string) get_post_meta( $post->ID, Form_Meta::META_FORM_CODE, true );

		if ( '' !== $stored_code ) {
			$record['internal_code'] = $stored_code;
		}

		$aliases = get_post_meta( $post->ID, Form_Meta::META_ALIASES, true );

		if ( is_array( $aliases ) && ! empty( $aliases ) ) {
			$record['aliases'] = array_values( array_map( 'strval', $aliases ) );
		}

		$official_url = (string) get_post_meta( $post->ID, Form_Meta::META_OFFICIAL_URL, true );

		if ( '' !== $official_url ) {
			$record['official_url'] = esc_url_raw( $official_url );
		}

		return $record;
	}

	/**
	 * Recompute derived asset fields on a catalog record.
	 *
	 * @param array<string, mixed> $record Catalog record.
	 * @return array<string, mixed>
	 */
	public function apply_computed_fields( array $record ): array {
		$computed = Form_Source_Selector::compute( (array) ( $record['source_files'] ?? array() ) );

		$record['preferred_source']  = $computed['preferred_source'];
		$record['editable_source']   = $computed['editable_source'];
		$record['fillable_strategy'] = $computed['fillable_strategy'];
		$record['generation_ready']  = $computed['generation_ready'];
		$record['import_status']     = $this->resolve_import_status( $record );
		$record['docx_available']    = $this->slot_available( $record, 'docx' ) || $this->slot_available( $record, 'converted_docx' );
		$record['fillable_pdf_available'] = $this->slot_available( $record, 'fillable_pdf' );
		$record['wpd_available']     = $this->slot_available( $record, 'wpd' );

		return $record;
	}

	/**
	 * Preserve manual QA field mapping status from disk.
	 *
	 * @param array<string, mixed> $record Record.
	 * @param string               $path   Existing record path.
	 * @return array<string, mixed>
	 */
	public function preserve_field_mapping_status( array $record, string $path ): array {
		if ( ! is_readable( $path ) ) {
			return $record;
		}

		$raw = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

		if ( false === $raw ) {
			return $record;
		}

		$existing = json_decode( $raw, true );

		if ( ! is_array( $existing ) ) {
			return $record;
		}

		$status = (string) ( $existing['field_mapping_status'] ?? '' );

		if ( in_array( $status, array( 'unmapped', 'partial', 'mapped', 'not_required' ), true ) ) {
			$record['field_mapping_status'] = $status;
		}

		return $record;
	}

	/**
	 * Classify a filename into a source_files slot.
	 *
	 * @param string $filename  Filename.
	 * @param string $extension Lowercase extension.
	 * @return string|null
	 */
	public function classify_slot( string $filename, string $extension ): ?string {
		$lower_name = strtolower( $filename );

		if ( 'docx' === $extension ) {
			return 'docx';
		}

		if ( 'doc' === $extension ) {
			return 'doc';
		}

		if ( 'wpd' === $extension ) {
			return 'wpd';
		}

		if ( 'rtf' === $extension ) {
			return 'rtf';
		}

		if ( 'pdf' === $extension ) {
			if ( str_contains( $lower_name, 'fillable' ) ) {
				return 'fillable_pdf';
			}

			return 'pdf';
		}

		return null;
	}

	/**
	 * Add pdf slot from legacy prose_file_url / prose_file_name meta.
	 *
	 * @param \WP_Post                    $post  Form post.
	 * @param array<string, array<string, mixed>> $slots Existing slots.
	 * @return array<string, array<string, mixed>>
	 */
	private function merge_legacy_file_meta( \WP_Post $post, array $slots ): array {
		if ( isset( $slots['pdf'] ) || isset( $slots['fillable_pdf'] ) ) {
			return $slots;
		}

		$form_code = (string) get_post_meta( $post->ID, Form_Meta::META_FORM_CODE, true );

		if ( '' === $form_code ) {
			$form_code = (string) get_post_meta( $post->ID, Form_Meta::META_FORM_ID, true );
		}

		$file_name = (string) get_post_meta( $post->ID, Form_Meta::META_FILE_NAME, true );
		$path      = '';

		if ( '' !== $file_name && '' !== $form_code ) {
			$file_manager = new Form_File_Manager();
			$path         = $file_manager->resolve_local_path( sanitize_title( $form_code ), $file_name );
		}

		if ( ( '' === $path || ! is_readable( $path ) ) && function_exists( 'get_post_meta' ) ) {
			$file_url = (string) get_post_meta( $post->ID, Form_Meta::META_FILE_URL, true );
			$path     = $this->map_file_url_to_path( $file_url );
		}

		if ( '' === $path || ! is_readable( $path ) ) {
			return $slots;
		}

		$slots['pdf'] = array(
			'filename'        => '' !== $file_name ? $file_name : basename( $path ),
			'path'            => $path,
			'source_url'      => (string) get_post_meta( $post->ID, Form_Meta::META_FILE_URL, true ),
			'download_status' => 'success',
		);

		return $slots;
	}

	/**
	 * Map prose_file_url to a local filesystem path.
	 *
	 * @param string $file_url Stored file URL.
	 * @return string
	 */
	private function map_file_url_to_path( string $file_url ): string {
		$file_url = trim( $file_url );

		if ( '' === $file_url ) {
			return '';
		}

		if ( function_exists( 'wp_upload_dir' ) ) {
			$uploads = wp_upload_dir();

			if ( is_array( $uploads ) && ! empty( $uploads['baseurl'] ) && ! empty( $uploads['basedir'] ) ) {
				$baseurl = trailingslashit( $uploads['baseurl'] );
				$basedir = trailingslashit( $uploads['basedir'] );

				if ( 0 === strpos( $file_url, $baseurl ) ) {
					$path = $basedir . substr( $file_url, strlen( $baseurl ) );

					if ( is_readable( $path ) ) {
						return $path;
					}
				}
			}
		}

		if ( function_exists( 'wp_parse_url' ) ) {
			$path = (string) wp_parse_url( $file_url, PHP_URL_PATH );
			$pos  = strpos( $path, '/wp-content/uploads/' );

			if ( false !== $path && false !== $pos && defined( 'WP_CONTENT_DIR' ) ) {
				$mapped = WP_CONTENT_DIR . substr( $path, strlen( '/wp-content' ) );

				if ( is_readable( $mapped ) ) {
					return $mapped;
				}
			}
		}

		return '';
	}

	/**
	 * Resolve import status from record state.
	 *
	 * @param array<string, mixed> $record Record.
	 * @return string
	 */
	private function resolve_import_status( array $record ): string {
		$preferred = (string) ( $record['preferred_source'] ?? '' );
		$files     = (array) ( $record['source_files'] ?? array() );

		if ( '' === $preferred ) {
			return empty( $files ) ? 'pending' : 'partial';
		}

		$slot = $files[ $preferred ] ?? null;

		if ( is_array( $slot ) && '' !== (string) ( $slot['path'] ?? '' ) && is_readable( (string) $slot['path'] ) ) {
			return 'complete';
		}

		foreach ( $files as $entry ) {
			if ( is_array( $entry ) && '' !== (string) ( $entry['path'] ?? '' ) && is_readable( (string) $entry['path'] ) ) {
				return 'partial';
			}
		}

		return 'pending';
	}

	/**
	 * Whether a source slot is available.
	 *
	 * @param array<string, mixed> $record Record.
	 * @param string               $slot   Slot key.
	 * @return bool
	 */
	private function slot_available( array $record, string $slot ): bool {
		$files = (array) ( $record['source_files'] ?? array() );
		$entry = $files[ $slot ] ?? null;

		if ( ! is_array( $entry ) ) {
			return false;
		}

		$status = (string) ( $entry['download_status'] ?? '' );

		if ( in_array( $status, array( 'failed', 'unsupported' ), true ) ) {
			return false;
		}

		$path = (string) ( $entry['path'] ?? '' );

		if ( '' !== $path && is_readable( $path ) ) {
			return true;
		}

		return '' !== (string) ( $entry['source_url'] ?? '' );
	}
}
