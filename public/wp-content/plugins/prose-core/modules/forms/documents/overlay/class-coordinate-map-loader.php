<?php
/**
 * Coordinate map loader — parse a form layout JSON into a normalized map.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Forms\Documents\Overlay;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Coordinate_Map_Loader
 *
 * Loads a form layout (coordinate map) from JSON and normalizes it into a
 * predictable structure with per-field defaults applied. Layout coordinates use
 * a top-left origin (x from the left edge, y from the top edge, in points) which
 * is the most intuitive frame for manual calibration; the renderer converts to
 * PDF user space.
 *
 * Normalized field shape:
 *   key, label, source, page, x, y, font_size, line_height, max_width,
 *   multiline (bool), checkbox (bool)
 */
final class Coordinate_Map_Loader {

	public const ORIGIN_TOP_LEFT = 'top-left';

	public const DEFAULT_FONT_SIZE   = 10.0;
	public const DEFAULT_LINE_FACTOR = 1.2;

	/**
	 * Load and normalize a layout from a JSON file.
	 *
	 * @param string $path JSON file path.
	 * @return array<string, mixed>
	 *
	 * @throws \RuntimeException When the file is missing or invalid JSON.
	 */
	public function load( string $path ): array {
		if ( '' === $path || ! is_readable( $path ) ) {
			throw new \RuntimeException( 'Layout file not found: ' . $path ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}

		$json = (string) file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

		return $this->load_string( $json, basename( $path ) );
	}

	/**
	 * Load and normalize a layout from a JSON string.
	 *
	 * @param string $json   JSON content.
	 * @param string $source Source label for error messages.
	 * @return array<string, mixed>
	 *
	 * @throws \RuntimeException When the JSON is invalid.
	 */
	public function load_string( string $json, string $source = 'layout' ): array {
		$data = json_decode( $json, true );

		if ( ! is_array( $data ) ) {
			throw new \RuntimeException( 'Invalid layout JSON: ' . $source ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}

		return $this->normalize( $data );
	}

	/**
	 * Normalize a decoded layout array.
	 *
	 * @param array<string, mixed> $data Decoded layout.
	 * @return array<string, mixed>
	 */
	private function normalize( array $data ): array {
		$fields    = array();
		$max_page  = 1;
		$raw_field = (array) ( $data['fields'] ?? array() );

		foreach ( $raw_field as $raw ) {
			$field    = $this->normalize_field( (array) $raw );
			$fields[] = $field;
			$max_page = max( $max_page, (int) $field['page'] );
		}

		$page_size = (array) ( $data['page_size'] ?? array() );
		$width     = (float) ( $page_size['width'] ?? 0 );
		$height    = (float) ( $page_size['height'] ?? 0 );

		$pages = (int) ( $data['pages'] ?? 0 );
		$pages = max( $pages, $max_page, 1 );

		return array(
			'form_code' => (string) ( $data['form_code'] ?? '' ),
			'title'     => (string) ( $data['title'] ?? '' ),
			'template'  => (string) ( $data['template'] ?? '' ),
			'origin'    => self::ORIGIN_TOP_LEFT,
			'page_size' => array(
				'width'  => $width,
				'height' => $height,
			),
			'pages'     => $pages,
			'fields'    => $fields,
		);
	}

	/**
	 * Normalize one field definition.
	 *
	 * @param array<string, mixed> $raw Raw field.
	 * @return array<string, mixed>
	 */
	private function normalize_field( array $raw ): array {
		$key       = (string) ( $raw['key'] ?? '' );
		$font_size = (float) ( $raw['font_size'] ?? self::DEFAULT_FONT_SIZE );

		if ( $font_size <= 0 ) {
			$font_size = self::DEFAULT_FONT_SIZE;
		}

		$line_height = (float) ( $raw['line_height'] ?? 0 );

		if ( $line_height <= 0 ) {
			$line_height = round( $font_size * self::DEFAULT_LINE_FACTOR, 2 );
		}

		return array(
			'key'         => $key,
			'label'       => (string) ( $raw['label'] ?? $key ),
			'source'      => (string) ( $raw['source'] ?? $key ),
			'page'        => max( 1, (int) ( $raw['page'] ?? 1 ) ),
			'x'           => (float) ( $raw['x'] ?? 0 ),
			'y'           => (float) ( $raw['y'] ?? 0 ),
			'font_size'   => $font_size,
			'line_height' => $line_height,
			'max_width'   => (float) ( $raw['max_width'] ?? 0 ),
			'multiline'   => (bool) ( $raw['multiline'] ?? false ),
			'checkbox'    => (bool) ( $raw['checkbox'] ?? false ),
		);
	}
}
