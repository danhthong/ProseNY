<?php
/**
 * Packet Store — filesystem layout for cached PDF/ZIP packets and manifests.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Packet;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Packet_Store
 */
final class Packet_Store {

	/**
	 * Absolute base directory (trailing slash).
	 *
	 * @var string
	 */
	private string $base_dir;

	/**
	 * Public base URL (trailing slash, may be empty).
	 *
	 * @var string
	 */
	private string $base_url;

	/**
	 * Constructor.
	 *
	 * @param string $base_dir Optional base directory override.
	 * @param string $base_url Optional base URL override.
	 */
	public function __construct( string $base_dir = '', string $base_url = '' ) {
		$this->base_dir = trailingslashit( '' !== $base_dir ? $base_dir : $this->default_dir() );
		$this->base_url = '' === $base_url ? $this->default_url() : trailingslashit( $base_url );
		$this->ensure_dirs();
	}

	/**
	 * Ensure storage directories exist.
	 *
	 * @return void
	 */
	public function ensure_dirs(): void {
		foreach ( array( 'pdf', 'zip', 'manifests' ) as $subdir ) {
			$this->mkdir( $this->base_dir . $subdir );
		}
	}

	/**
	 * Absolute path to merged PDF packet.
	 *
	 * @param string $package_id Package enum id.
	 * @return string
	 */
	public function pdf_path( string $package_id ): string {
		return $this->base_dir . 'pdf/' . $this->sanitize_id( $package_id ) . '.pdf';
	}

	/**
	 * Absolute path to ZIP packet.
	 *
	 * @param string $package_id Package enum id.
	 * @return string
	 */
	public function zip_path( string $package_id ): string {
		return $this->base_dir . 'zip/' . $this->sanitize_id( $package_id ) . '.zip';
	}

	/**
	 * Absolute path to manifest JSON.
	 *
	 * @param string $package_id Package enum id.
	 * @return string
	 */
	public function manifest_path( string $package_id ): string {
		return $this->base_dir . 'manifests/' . $this->sanitize_id( $package_id ) . '.json';
	}

	/**
	 * Whether merged PDF exists.
	 *
	 * @param string $package_id Package enum id.
	 * @return bool
	 */
	public function pdf_exists( string $package_id ): bool {
		$path = $this->pdf_path( $package_id );

		return is_readable( $path ) && filesize( $path ) > 0;
	}

	/**
	 * Whether ZIP packet exists.
	 *
	 * @param string $package_id Package enum id.
	 * @return bool
	 */
	public function zip_exists( string $package_id ): bool {
		$path = $this->zip_path( $package_id );

		return is_readable( $path ) && filesize( $path ) > 0;
	}

	/**
	 * Read stored manifest.
	 *
	 * @param string $package_id Package enum id.
	 * @return array<string, mixed>|null
	 */
	public function read_manifest( string $package_id ): ?array {
		$path = $this->manifest_path( $package_id );

		if ( ! is_readable( $path ) ) {
			return null;
		}

		$raw = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

		if ( false === $raw || '' === $raw ) {
			return null;
		}

		$data = json_decode( $raw, true );

		return is_array( $data ) ? $data : null;
	}

	/**
	 * Write manifest JSON.
	 *
	 * @param string               $package_id Package enum id.
	 * @param array<string, mixed> $manifest   Manifest record.
	 * @return bool
	 */
	public function write_manifest( string $package_id, array $manifest ): bool {
		$path = $this->manifest_path( $package_id );
		$json = (string) wp_json_encode( $manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );

		return false !== file_put_contents( $path, $json ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
	}

	/**
	 * Write merged PDF bytes.
	 *
	 * @param string $package_id Package enum id.
	 * @param string $bytes      PDF bytes.
	 * @return bool
	 */
	public function write_pdf( string $package_id, string $bytes ): bool {
		if ( '' === $bytes ) {
			return false;
		}

		$path = $this->pdf_path( $package_id );

		return false !== file_put_contents( $path, $bytes ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
	}

	/**
	 * Write ZIP bytes.
	 *
	 * @param string $package_id Package enum id.
	 * @param string $bytes      ZIP bytes.
	 * @return bool
	 */
	public function write_zip( string $package_id, string $bytes ): bool {
		if ( '' === $bytes ) {
			return false;
		}

		$path = $this->zip_path( $package_id );

		return false !== file_put_contents( $path, $bytes ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
	}

	/**
	 * Merged PDF file size in bytes.
	 *
	 * @param string $package_id Package enum id.
	 * @return int
	 */
	public function pdf_size( string $package_id ): int {
		$path = $this->pdf_path( $package_id );

		if ( ! is_readable( $path ) ) {
			return 0;
		}

		$size = filesize( $path );

		return false === $size ? 0 : (int) $size;
	}

	/**
	 * ZIP file size in bytes.
	 *
	 * @param string $package_id Package enum id.
	 * @return int
	 */
	public function zip_size( string $package_id ): int {
		$path = $this->zip_path( $package_id );

		if ( ! is_readable( $path ) ) {
			return 0;
		}

		$size = filesize( $path );

		return false === $size ? 0 : (int) $size;
	}

	/**
	 * Combined artifact size (PDF + ZIP).
	 *
	 * @param string $package_id Package enum id.
	 * @return int
	 */
	public function total_size( string $package_id ): int {
		return $this->pdf_size( $package_id ) + $this->zip_size( $package_id );
	}

	/**
	 * Public download URL for merged PDF.
	 *
	 * @param string $package_id Package enum id.
	 * @return string
	 */
	public function pdf_url( string $package_id ): string {
		if ( '' === $this->base_url || ! $this->pdf_exists( $package_id ) ) {
			return '';
		}

		return $this->base_url . 'pdf/' . rawurlencode( $this->sanitize_id( $package_id ) . '.pdf' );
	}

	/**
	 * Public download URL for ZIP packet.
	 *
	 * @param string $package_id Package enum id.
	 * @return string
	 */
	public function zip_url( string $package_id ): string {
		if ( '' === $this->base_url || ! $this->zip_exists( $package_id ) ) {
			return '';
		}

		return $this->base_url . 'zip/' . rawurlencode( $this->sanitize_id( $package_id ) . '.zip' );
	}

	/**
	 * Base directory accessor.
	 *
	 * @return string
	 */
	public function base_dir(): string {
		return $this->base_dir;
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
				return trailingslashit( $uploads['basedir'] ) . 'prose/packets';
			}
		}

		if ( defined( 'PROSE_CORE_PATH' ) ) {
			return PROSE_CORE_PATH . 'tests/manual/packet-output';
		}

		return sys_get_temp_dir() . '/prose-packets';
	}

	/**
	 * Default public URL.
	 *
	 * @return string
	 */
	private function default_url(): string {
		if ( function_exists( 'wp_upload_dir' ) ) {
			$uploads = wp_upload_dir();

			if ( is_array( $uploads ) && ! empty( $uploads['baseurl'] ) ) {
				return trailingslashit( $uploads['baseurl'] ) . 'prose/packets/';
			}
		}

		return '';
	}

	/**
	 * Sanitize package id for filesystem use.
	 *
	 * @param string $package_id Package id.
	 * @return string
	 */
	private function sanitize_id( string $package_id ): string {
		$clean = preg_replace( '/[^A-Za-z0-9._-]/', '', $package_id );
		$clean = (string) $clean;

		return '' !== $clean ? $clean : 'package';
	}

	/**
	 * Ensure a directory exists.
	 *
	 * @param string $dir Directory path.
	 * @return void
	 */
	private function mkdir( string $dir ): void {
		if ( is_dir( $dir ) ) {
			return;
		}

		if ( function_exists( 'wp_mkdir_p' ) ) {
			wp_mkdir_p( $dir );
		} else {
			mkdir( $dir, 0775, true );
		}
	}
}
