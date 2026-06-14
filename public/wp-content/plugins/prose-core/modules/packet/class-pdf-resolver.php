<?php
/**
 * PDF Resolver — maps form_id to a blank source PDF path via Forms Repository.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Packet;

use ProSe\Core\Forms\Form_File_Manager;
use ProSe\Core\Forms\Form_Meta;
use ProSe\Core\Forms\Form_Repository;
use ProSe\Core\Forms\Form_Source_Selector;
use ProSe\Core\Forms\Forms_Catalog;
use ProSe\Core\Forms\Classification\Field_Normalizer;
use ProSe\Core\Forms\Pdf_Analyzer;

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
	 * WordPress form repository.
	 *
	 * @var Form_Repository|null
	 */
	private ?Form_Repository $repository;

	/**
	 * PDF analyzer for post-based path resolution.
	 *
	 * @var Pdf_Analyzer|null
	 */
	private ?Pdf_Analyzer $analyzer;

	/**
	 * Constructor.
	 *
	 * @param Forms_Catalog|null    $catalog    Forms catalog.
	 * @param Form_Repository|null  $repository WordPress form repository.
	 * @param Pdf_Analyzer|null     $analyzer   PDF analyzer.
	 */
	public function __construct(
		?Forms_Catalog $catalog = null,
		?Form_Repository $repository = null,
		?Pdf_Analyzer $analyzer = null
	) {
		$this->catalog    = $catalog ?? new Forms_Catalog();
		$this->repository = $repository;
		$this->analyzer   = $analyzer;
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

		if ( is_array( $record ) ) {
			$source_files = is_array( $record['source_files'] ?? null ) ? $record['source_files'] : array();
			$pdf_path     = $this->resolve_pdf_path_from_source_files( $source_files );

			if ( '' !== $pdf_path ) {
				return array(
					'form_id'  => $form_id,
					'pdf_path' => $pdf_path,
				);
			}
		}

		$wp_path = $this->resolve_from_wordpress( $form_id );

		return array(
			'form_id'  => $form_id,
			'pdf_path' => $wp_path,
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

		// Fall back to preferred source when it resolves to a readable PDF path.
		$preferred = Form_Source_Selector::preferred_source( $source_files );

		if ( in_array( $preferred, self::PDF_SLOTS, true ) && isset( $source_files[ $preferred ] ) ) {
			$path = (string) ( $source_files[ $preferred ]['path'] ?? '' );

			if ( '' !== $path && is_readable( $path ) ) {
				return $path;
			}
		}

		return '';
	}

	/**
	 * Resolve a blank PDF from the WordPress prose_form repository.
	 *
	 * Uses prose_file_url (ProSe > Forms), prose_file_name, and prose_source_files.
	 *
	 * @param string $form_id Form code.
	 * @return string
	 */
	private function resolve_from_wordpress( string $form_id ): string {
		if ( ! function_exists( 'get_post_meta' ) ) {
			return '';
		}

		$repository = $this->repository ?? new Form_Repository();
		$post       = $repository->get_by_form_code( $form_id );

		if ( ! $post instanceof \WP_Post ) {
			return $this->resolve_by_conventional_filename( $form_id );
		}

		$analyzer = $this->analyzer ?? new Pdf_Analyzer( $repository, new Field_Normalizer() );
		$path     = $analyzer->resolve_pdf_path( (int) $post->ID );

		if ( '' !== $path && is_readable( $path ) ) {
			return $path;
		}

		$file_url = (string) get_post_meta( $post->ID, Form_Meta::META_FILE_URL, true );

		if ( '' !== $file_url ) {
			$mapped = $this->map_file_url_to_path( $file_url );

			if ( '' !== $mapped && is_readable( $mapped ) ) {
				return $mapped;
			}
		}

		$file_name = (string) get_post_meta( $post->ID, Form_Meta::META_FILE_NAME, true );

		if ( '' !== $file_name ) {
			$file_manager = new Form_File_Manager();
			$resolved     = $file_manager->resolve_local_path( sanitize_title( $form_id ), $file_name );

			if ( '' !== $resolved && is_readable( $resolved ) ) {
				return $resolved;
			}
		}

		return $this->resolve_by_conventional_filename( $form_id );
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
	 * Try the conventional flat uploads filename (e.g. ud-1.pdf).
	 *
	 * @param string $form_id Form code.
	 * @return string
	 */
	private function resolve_by_conventional_filename( string $form_id ): string {
		$file_manager = new Form_File_Manager();
		$filename     = strtolower( $form_id ) . '.pdf';

		return $file_manager->resolve_local_path( sanitize_title( $form_id ), $filename );
	}
}
