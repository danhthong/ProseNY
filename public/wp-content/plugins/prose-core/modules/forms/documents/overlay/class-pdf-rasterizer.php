<?php
/**
 * PDF rasterizer — render official (flat) PDF pages to JPEG bytes.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Forms\Documents\Overlay;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Pdf_Rasterizer
 *
 * Rasterizes the pages of an official court PDF to JPEG using Poppler's
 * `pdftoppm`. The rendered images are used as a faithful, layout-preserving
 * background behind the overlay text. This is the supported path because the
 * official PDFs are flat (no AcroForm) and use PDF 1.5+ object streams that the
 * bundled PDF importer cannot read; rasterizing avoids modifying the original.
 */
final class Pdf_Rasterizer {

	/**
	 * Render DPI.
	 *
	 * @var int
	 */
	private int $dpi;

	/**
	 * Path to the pdftoppm binary (resolved lazily).
	 *
	 * @var string|null
	 */
	private ?string $binary = null;

	/**
	 * Constructor.
	 *
	 * @param int $dpi Render resolution.
	 */
	public function __construct( int $dpi = 200 ) {
		$this->dpi = max( 72, $dpi );
	}

	/**
	 * Whether a rasterizer binary is available.
	 *
	 * @return bool
	 */
	public function available(): bool {
		return '' !== $this->binary();
	}

	/**
	 * Resolve the pdftoppm binary path once.
	 *
	 * @return string
	 */
	private function binary(): string {
		if ( null !== $this->binary ) {
			return $this->binary;
		}

		$this->binary = '';

		foreach ( array( 'pdftoppm', '/usr/local/bin/pdftoppm', '/opt/homebrew/bin/pdftoppm', '/usr/bin/pdftoppm' ) as $candidate ) {
			$which = self::run( 'command -v ' . escapeshellarg( $candidate ) );

			if ( '' !== trim( $which ) ) {
				$this->binary = trim( $which );
				break;
			}

			if ( is_executable( $candidate ) ) {
				$this->binary = $candidate;
				break;
			}
		}

		return $this->binary;
	}

	/**
	 * Render every page of a PDF to JPEG bytes.
	 *
	 * @param string $pdf_path PDF file path.
	 * @return string[] JPEG byte strings, in page order. Empty when unavailable.
	 */
	public function to_jpeg_pages( string $pdf_path ): array {
		if ( '' === $pdf_path || ! is_readable( $pdf_path ) || ! $this->available() ) {
			return array();
		}

		$prefix = rtrim( sys_get_temp_dir(), '/\\' ) . '/prose-overlay-' . bin2hex( random_bytes( 8 ) );

		$command = sprintf(
			'%s -jpeg -r %d %s %s 2>/dev/null',
			escapeshellarg( $this->binary() ),
			$this->dpi,
			escapeshellarg( $pdf_path ),
			escapeshellarg( $prefix )
		);

		self::run( $command );

		$pages = $this->collect_pages( $prefix );

		return $pages;
	}

	/**
	 * Collect and clean up the generated JPEG files for a prefix.
	 *
	 * @param string $prefix Output prefix.
	 * @return string[]
	 */
	private function collect_pages( string $prefix ): array {
		$files = glob( $prefix . '*.jpg' );

		if ( false === $files || array() === $files ) {
			return array();
		}

		natsort( $files );

		$pages = array();

		foreach ( $files as $file ) {
			$bytes = file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

			if ( false !== $bytes ) {
				$pages[] = $bytes;
			}

			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink, WordPress.PHP.NoSilencedErrors.Discouraged
			@unlink( $file );
		}

		return $pages;
	}

	/**
	 * Run a shell command and capture stdout.
	 *
	 * @param string $command Command.
	 * @return string
	 */
	private static function run( string $command ): string {
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.PHP.DiscouragedPHPFunctions.system_calls_shell_exec
		$output = @shell_exec( $command );

		return is_string( $output ) ? $output : '';
	}
}
