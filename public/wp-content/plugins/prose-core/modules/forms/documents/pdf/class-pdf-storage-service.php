<?php
/**
 * PDF storage service — persist rendered PDFs and report their metadata.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Forms\Documents\Pdf;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Pdf_Storage_Service
 *
 * Writes rendered PDF bytes to a storage directory and returns the stored
 * file_path, a download_url (when a base URL is configured) and a content
 * checksum. Defaults to the WordPress uploads directory when available, and
 * otherwise to a plugin-local path so it works in DB-free / CLI contexts.
 */
final class Pdf_Storage_Service {

	/**
	 * Absolute base directory (trailing slash).
	 *
	 * @var string
	 */
	private string $base_dir;

	/**
	 * Base URL for download links (trailing slash, may be empty).
	 *
	 * @var string
	 */
	private string $base_url;

	/**
	 * Constructor.
	 *
	 * @param string $base_dir Absolute storage directory.
	 * @param string $base_url Public base URL for downloads.
	 */
	public function __construct( string $base_dir = '', string $base_url = '' ) {
		$this->base_dir = trailingslashit( '' !== $base_dir ? $base_dir : $this->default_dir() );
		$this->base_url = '' === $base_url ? '' : trailingslashit( $base_url );
	}

	/**
	 * Storage base directory.
	 *
	 * @return string
	 */
	public function base_dir(): string {
		return $this->base_dir;
	}

	/**
	 * Store PDF bytes under a relative path and report the artifact metadata.
	 *
	 * @param string $bytes         Raw PDF bytes.
	 * @param string $relative_path Relative file path (e.g. ud1-sample.pdf).
	 * @return array<string, mixed> { file_path, download_url, checksum, bytes, stored }.
	 */
	public function store( string $bytes, string $relative_path ): array {
		$relative_path = ltrim( $this->sanitize_relative( $relative_path ), '/' );
		$file_path     = $this->base_dir . $relative_path;

		$stored = $this->put( $file_path, $bytes );

		return array(
			'file_path'    => $file_path,
			'download_url' => '' === $this->base_url ? '' : $this->base_url . $relative_path,
			'checksum'     => 'sha256:' . hash( 'sha256', $bytes ),
			'bytes'        => strlen( $bytes ),
			'stored'       => $stored,
		);
	}

	/**
	 * Compute the checksum of byte content without storing it.
	 *
	 * @param string $bytes Bytes.
	 * @return string
	 */
	public function checksum( string $bytes ): string {
		return 'sha256:' . hash( 'sha256', $bytes );
	}

	/**
	 * Default storage directory.
	 *
	 * @return string
	 */
	private function default_dir(): string {
		if ( function_exists( 'wp_upload_dir' ) ) {
			$uploads = wp_upload_dir();

			if ( is_array( $uploads ) && ! empty( $uploads['basedir'] ) ) {
				return trailingslashit( $uploads['basedir'] ) . 'prose-documents';
			}
		}

		if ( defined( 'PROSE_CORE_PATH' ) ) {
			return PROSE_CORE_PATH . 'tests/manual/pdf-render-output';
		}

		return sys_get_temp_dir() . '/prose-documents';
	}

	/**
	 * Ensure the parent directory exists and write the file.
	 *
	 * @param string $file_path Absolute file path.
	 * @param string $bytes     Bytes.
	 * @return bool
	 */
	private function put( string $file_path, string $bytes ): bool {
		$dir = dirname( $file_path );

		if ( ! is_dir( $dir ) ) {
			if ( function_exists( 'wp_mkdir_p' ) ) {
				wp_mkdir_p( $dir );
			} else {
				mkdir( $dir, 0775, true );
			}
		}

		return false !== file_put_contents( $file_path, $bytes ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
	}

	/**
	 * Sanitize a relative path, stripping traversal and leading separators.
	 *
	 * @param string $relative_path Relative path.
	 * @return string
	 */
	private function sanitize_relative( string $relative_path ): string {
		$relative_path = str_replace( '\\', '/', $relative_path );
		$relative_path = preg_replace( '#\.\.+/#', '', $relative_path );

		return (string) $relative_path;
	}
}
