<?php
/**
 * Layout repository — read and write overlay coordinate-map JSON files.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Forms\Documents\Overlay;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Layout_Repository
 *
 * Reads and persists the overlay layout JSON files that the Layout Studio
 * calibrates. This is the only component that writes layout files; it keeps the
 * renderer and document generation untouched.
 */
final class Layout_Repository {

	/**
	 * Layouts directory (trailing slash).
	 *
	 * @var string
	 */
	private string $dir;

	/**
	 * Constructor.
	 *
	 * @param string $dir Layouts directory.
	 */
	public function __construct( string $dir = '' ) {
		if ( '' === $dir && defined( 'PROSE_CORE_PATH' ) ) {
			$dir = PROSE_CORE_PATH . 'modules/forms/documents/overlay/layouts/';
		}

		$this->dir = '' === $dir ? '' : rtrim( $dir, '/\\' ) . '/';
	}

	/**
	 * Layouts directory.
	 *
	 * @return string
	 */
	public function dir(): string {
		return $this->dir;
	}

	/**
	 * File path for a form code.
	 *
	 * @param string $form_code Form code.
	 * @return string
	 */
	public function path( string $form_code ): string {
		return '' === $this->dir ? '' : $this->dir . $this->sanitize_code( $form_code ) . '.json';
	}

	/**
	 * Whether a layout file exists.
	 *
	 * @param string $form_code Form code.
	 * @return bool
	 */
	public function has( string $form_code ): bool {
		$path = $this->path( $form_code );

		return '' !== $path && is_readable( $path );
	}

	/**
	 * Read and decode a layout file (raw structure).
	 *
	 * @param string $form_code Form code.
	 * @return array<string, mixed>
	 *
	 * @throws \RuntimeException When missing or invalid.
	 */
	public function read( string $form_code ): array {
		if ( ! $this->has( $form_code ) ) {
			throw new \RuntimeException( 'Layout not found: ' . $form_code ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}

		$json = (string) file_get_contents( $this->path( $form_code ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$data = json_decode( $json, true );

		if ( ! is_array( $data ) ) {
			throw new \RuntimeException( 'Invalid layout JSON: ' . $form_code ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}

		return $data;
	}

	/**
	 * Write a layout array to disk as pretty JSON.
	 *
	 * @param string               $form_code Form code.
	 * @param array<string, mixed> $layout    Layout data.
	 * @return bool
	 */
	public function write( string $form_code, array $layout ): bool {
		$path = $this->path( $form_code );

		if ( '' === $path ) {
			return false;
		}

		$json = wp_json_encode( $layout, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );

		if ( false === $json ) {
			return false;
		}

		$dir = dirname( $path );

		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_put_contents_file_put_contents, WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		return false !== file_put_contents( $path, $json . "\n" );
	}

	/**
	 * List form codes with a layout file.
	 *
	 * @return string[]
	 */
	public function codes(): array {
		if ( '' === $this->dir || ! is_dir( $this->dir ) ) {
			return array();
		}

		$codes = array();

		foreach ( (array) glob( $this->dir . '*.json' ) as $file ) {
			$codes[] = basename( (string) $file, '.json' );
		}

		sort( $codes );

		return $codes;
	}

	/**
	 * Sanitize a form code for safe filesystem use.
	 *
	 * @param string $form_code Form code.
	 * @return string
	 */
	private function sanitize_code( string $form_code ): string {
		$code = (string) preg_replace( '/[^A-Za-z0-9._-]/', '', $form_code );

		return str_replace( '..', '', $code );
	}
}
