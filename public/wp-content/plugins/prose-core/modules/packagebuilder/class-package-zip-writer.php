<?php
/**
 * Package ZIP Writer — materializes a manifest + assets into a ZIP archive.
 *
 * Writes packages/{package_id}/manifest.json and forms/{CODE}.{ext}, then zips
 * to package.zip. Asset bytes are obtained through the Asset_Source contract so
 * blank (copy-only) and future filled sources share this writer unchanged.
 *
 * Defaults to the WordPress uploads directory and falls back to a plugin-local
 * or temp path so it also works in DB-free / CLI contexts (mirrors
 * Pdf_Storage_Service).
 *
 * @package ProSeCore
 */

namespace ProSe\Core\PackageBuilder;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Package_Zip_Writer
 */
final class Package_Zip_Writer {

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
	 * Asset source for resolving form bytes.
	 *
	 * @var Asset_Source
	 */
	private Asset_Source $asset_source;

	/**
	 * Constructor.
	 *
	 * @param Asset_Source $asset_source Asset source.
	 * @param string       $base_dir     Absolute storage directory.
	 * @param string       $base_url     Public base URL for downloads.
	 */
	public function __construct( Asset_Source $asset_source, string $base_dir = '', string $base_url = '' ) {
		$this->asset_source = $asset_source;
		$this->base_dir     = trailingslashit( '' !== $base_dir ? $base_dir : $this->default_dir() );
		$this->base_url     = '' === $base_url ? $this->default_url() : trailingslashit( $base_url );
	}

	/**
	 * Write the package directory and ZIP for a manifest.
	 *
	 * @param Package_Manifest $manifest Finalized manifest.
	 * @return array<string, mixed> { package_path, zip_path, download_url, written_forms }.
	 */
	public function write( Package_Manifest $manifest ): array {
		$package_id   = $this->sanitize_id( $manifest->package_id() );
		$package_dir  = $this->base_dir . $package_id . '/';
		$forms_dir    = $package_dir . 'forms/';
		$manifest_abs = $package_dir . 'manifest.json';
		$zip_abs      = $package_dir . 'package.zip';

		$this->ensure_dir( $forms_dir );

		$written_forms = array();
		$zip_files     = array();

		foreach ( $manifest->forms() as $form ) {
			$source_path = $this->asset_source->open( $form );

			if ( null === $source_path ) {
				continue;
			}

			$filename = $this->asset_filename( $form, $source_path );
			$dest     = $forms_dir . $filename;

			if ( false !== copy( $source_path, $dest ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions
				$written_forms[]                 = (string) ( $form['code'] ?? '' );
				$zip_files[ 'forms/' . $filename ] = $dest;
			}
		}

		$manifest_json = (string) wp_json_encode( $manifest->to_array(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
		file_put_contents( $manifest_abs, $manifest_json ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$zip_files['manifest.json'] = $manifest_abs;

		$zip_written = $this->build_zip( $zip_abs, $zip_files );

		return array(
			'package_path'  => $package_dir,
			'zip_path'      => $zip_written ? $zip_abs : '',
			'download_url'  => ( $zip_written && '' !== $this->base_url ) ? $this->base_url . $package_id . '/package.zip' : '',
			'written_forms' => $written_forms,
		);
	}

	/**
	 * Build a ZIP archive from a map of archive-path => absolute-source-path.
	 *
	 * @param string                $zip_abs Absolute zip output path.
	 * @param array<string, string> $files   archive_name => absolute path.
	 * @return bool
	 */
	private function build_zip( string $zip_abs, array $files ): bool {
		if ( ! class_exists( '\ZipArchive' ) ) {
			return false;
		}

		$zip = new \ZipArchive();

		if ( true !== $zip->open( $zip_abs, \ZipArchive::CREATE | \ZipArchive::OVERWRITE ) ) {
			return false;
		}

		foreach ( $files as $archive_name => $absolute ) {
			if ( is_readable( $absolute ) ) {
				$zip->addFile( $absolute, $archive_name );
			}
		}

		return $zip->close();
	}

	/**
	 * Determine the in-package filename for a form asset ({CODE}.{ext}).
	 *
	 * @param array<string, mixed> $form        Form entry.
	 * @param string               $source_path Absolute source path.
	 * @return string
	 */
	private function asset_filename( array $form, string $source_path ): string {
		$code = (string) ( $form['code'] ?? 'form' );
		$code = preg_replace( '/[^A-Za-z0-9._-]/', '_', $code );
		$ext  = strtolower( pathinfo( $source_path, PATHINFO_EXTENSION ) );

		return '' !== $ext ? $code . '.' . $ext : $code;
	}

	/**
	 * Sanitize a package id for safe filesystem use.
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
	private function ensure_dir( string $dir ): void {
		if ( is_dir( $dir ) ) {
			return;
		}

		if ( function_exists( 'wp_mkdir_p' ) ) {
			wp_mkdir_p( $dir );
		} else {
			mkdir( $dir, 0775, true );
		}
	}

	/**
	 * Default base directory.
	 *
	 * @return string
	 */
	private function default_dir(): string {
		if ( function_exists( 'wp_upload_dir' ) ) {
			$uploads = wp_upload_dir();

			if ( is_array( $uploads ) && ! empty( $uploads['basedir'] ) ) {
				return trailingslashit( $uploads['basedir'] ) . 'prose-packages';
			}
		}

		if ( defined( 'PROSE_CORE_PATH' ) ) {
			return PROSE_CORE_PATH . 'tests/manual/package-output';
		}

		return sys_get_temp_dir() . '/prose-packages';
	}

	/**
	 * Default base URL.
	 *
	 * @return string
	 */
	private function default_url(): string {
		if ( function_exists( 'wp_upload_dir' ) ) {
			$uploads = wp_upload_dir();

			if ( is_array( $uploads ) && ! empty( $uploads['baseurl'] ) ) {
				return trailingslashit( $uploads['baseurl'] ) . 'prose-packages/';
			}
		}

		return '';
	}
}
