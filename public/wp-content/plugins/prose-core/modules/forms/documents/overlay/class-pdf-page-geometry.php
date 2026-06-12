<?php
/**
 * PDF page geometry helper — read page size from an official PDF.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Forms\Documents\Overlay;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Pdf_Page_Geometry
 *
 * Reads the first /MediaBox of a PDF so an overlay can be sized to match the
 * official court document exactly. Dependency-free; falls back to US Letter
 * (612 x 792 pt) when a MediaBox cannot be located.
 */
final class Pdf_Page_Geometry {

	public const LETTER_WIDTH  = 612.0;
	public const LETTER_HEIGHT = 792.0;

	/**
	 * Default page size (US Letter).
	 *
	 * @return array{width: float, height: float}
	 */
	public static function default_size(): array {
		return array(
			'width'  => self::LETTER_WIDTH,
			'height' => self::LETTER_HEIGHT,
		);
	}

	/**
	 * Read the first page size from a PDF, or the default when unavailable.
	 *
	 * @param string $path PDF file path.
	 * @return array{width: float, height: float}
	 */
	public static function size( string $path ): array {
		if ( '' === $path || ! is_readable( $path ) ) {
			return self::default_size();
		}

		$data = (string) file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

		$box = self::find_media_box( $data );

		if ( null === $box && preg_match_all( '/stream\r?\n(.*?)\r?\nendstream/s', $data, $streams ) ) {
			foreach ( $streams[1] as $stream ) {
				$decoded = @gzuncompress( $stream ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

				if ( false === $decoded ) {
					$decoded = @gzinflate( $stream ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				}

				if ( false !== $decoded ) {
					$box = self::find_media_box( $decoded );
				}

				if ( null !== $box ) {
					break;
				}
			}
		}

		return null === $box ? self::default_size() : $box;
	}

	/**
	 * Extract a MediaBox rectangle from a text blob.
	 *
	 * @param string $blob PDF text.
	 * @return array{width: float, height: float}|null
	 */
	private static function find_media_box( string $blob ): ?array {
		if ( ! preg_match( '/\/MediaBox\s*\[\s*(-?[\d.]+)\s+(-?[\d.]+)\s+(-?[\d.]+)\s+(-?[\d.]+)\s*\]/', $blob, $m ) ) {
			return null;
		}

		$width  = abs( (float) $m[3] - (float) $m[1] );
		$height = abs( (float) $m[4] - (float) $m[2] );

		if ( $width <= 0 || $height <= 0 ) {
			return null;
		}

		return array(
			'width'  => $width,
			'height' => $height,
		);
	}
}
