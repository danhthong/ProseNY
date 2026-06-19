<?php
/**
 * Resolve blank PDF filesystem paths from prose_form post metadata.
 *
 * Reads prose_pdf (canonical filename), with fallbacks to prose_file_name and
 * prose_file_url when JSON catalog paths are empty.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Forms;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Form_Pdf_Path_Resolver
 */
final class Form_Pdf_Path_Resolver {

	/**
	 * Form repository.
	 *
	 * @var Form_Repository
	 */
	private Form_Repository $forms;

	/**
	 * File manager.
	 *
	 * @var Form_File_Manager
	 */
	private Form_File_Manager $files;

	/**
	 * Constructor.
	 *
	 * @param Form_Repository|null   $forms Form repository.
	 * @param Form_File_Manager|null $files File manager.
	 */
	public function __construct( ?Form_Repository $forms = null, ?Form_File_Manager $files = null ) {
		$this->forms = $forms ?? new Form_Repository();
		$this->files = $files ?? new Form_File_Manager();
	}

	/**
	 * Resolve a readable PDF path for a form code via prose_form metadata.
	 *
	 * @param string $form_code Form code (e.g. 1-A).
	 * @return string
	 */
	public function resolve_for_code( string $form_code ): string {
		$post = $this->forms->get_by_form_code( $form_code );

		if ( ! $post instanceof \WP_Post ) {
			return '';
		}

		return $this->resolve_for_post( $post );
	}

	/**
	 * Resolve a readable PDF path from a prose_form post.
	 *
	 * @param \WP_Post $post Form post.
	 * @return string
	 */
	public function resolve_for_post( \WP_Post $post ): string {
		$form_code = (string) get_post_meta( $post->ID, Form_Meta::META_FORM_CODE, true );

		if ( '' === $form_code ) {
			$form_code = (string) get_post_meta( $post->ID, Form_Meta::META_FORM_ID, true );
		}

		$filename = $this->pdf_filename_for_post( $post );

		if ( '' !== $filename && '' !== $form_code ) {
			$path = $this->files->resolve_local_path( sanitize_title( $form_code ), $filename );

			if ( '' !== $path && is_readable( $path ) ) {
				return $path;
			}
		}

		$file_url = (string) get_post_meta( $post->ID, Form_Meta::META_FILE_URL, true );
		$path     = self::map_url_to_path( $file_url );

		return ( '' !== $path && is_readable( $path ) ) ? $path : '';
	}

	/**
	 * PDF filename stored on the form post.
	 *
	 * @param \WP_Post $post Form post.
	 * @return string
	 */
	public function pdf_filename_for_post( \WP_Post $post ): string {
		foreach ( array(
			Form_Meta::META_PDF,
			Form_Meta::META_FILE_NAME,
		) as $meta_key ) {
			$filename = sanitize_file_name( (string) get_post_meta( $post->ID, $meta_key, true ) );

			if ( '' !== $filename ) {
				return $filename;
			}
		}

		$file_url = (string) get_post_meta( $post->ID, Form_Meta::META_FILE_URL, true );

		if ( '' !== $file_url ) {
			return sanitize_file_name( basename( wp_parse_url( $file_url, PHP_URL_PATH ) ?: '' ) );
		}

		return '';
	}

	/**
	 * Map a stored uploads URL to a local filesystem path.
	 *
	 * @param string $file_url Stored file URL.
	 * @return string
	 */
	public static function map_url_to_path( string $file_url ): string {
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
}
