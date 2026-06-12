<?php
/**
 * Layout validation service — validate a coordinate map against its page.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Forms\Documents\Overlay;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Layout_Validation_Service
 *
 * Validates a normalized layout (coordinate map): required field attributes,
 * coordinate sanity, page bounds, duplicate keys, and (optionally) consistency
 * with the official PDF's page geometry. Read-only; returns a structured result
 * so calibration problems surface before rendering.
 */
final class Layout_Validation_Service {

	/**
	 * Validate a normalized layout.
	 *
	 * @param array<string, mixed> $layout  Normalized layout (from Coordinate_Map_Loader).
	 * @param array<string, mixed> $options Options: template_path (for page-size cross-check).
	 * @return array{valid: bool, errors: string[], warnings: string[]}
	 */
	public function validate( array $layout, array $options = array() ): array {
		$errors   = array();
		$warnings = array();

		if ( '' === (string) ( $layout['form_code'] ?? '' ) ) {
			$errors[] = 'layout is missing form_code';
		}

		$fields = (array) ( $layout['fields'] ?? array() );

		if ( empty( $fields ) ) {
			$warnings[] = 'layout has no fields';
		}

		$size  = $this->page_size( $layout, $options, $warnings );
		$pages = (int) ( $layout['pages'] ?? 1 );
		$seen  = array();

		foreach ( $fields as $index => $field ) {
			$ref = $this->field_ref( $field, $index );

			$key = (string) ( $field['key'] ?? '' );

			if ( '' === $key ) {
				$errors[] = $ref . ': missing key';
			} elseif ( isset( $seen[ $key ] ) ) {
				$errors[] = $ref . ': duplicate key "' . $key . '"';
			} else {
				$seen[ $key ] = true;
			}

			$page = (int) ( $field['page'] ?? 0 );

			if ( $page < 1 ) {
				$errors[] = $ref . ': page must be >= 1';
			} elseif ( $page > $pages ) {
				$errors[] = $ref . ': page ' . $page . ' exceeds layout page count ' . $pages;
			}

			$x = (float) ( $field['x'] ?? -1 );
			$y = (float) ( $field['y'] ?? -1 );

			if ( $x < 0 ) {
				$errors[] = $ref . ': x must be >= 0';
			} elseif ( $x > $size['width'] ) {
				$warnings[] = $ref . ': x ' . $x . ' is outside page width ' . $size['width'];
			}

			if ( $y < 0 ) {
				$errors[] = $ref . ': y must be >= 0';
			} elseif ( $y > $size['height'] ) {
				$warnings[] = $ref . ': y ' . $y . ' is outside page height ' . $size['height'];
			}

			if ( (float) ( $field['font_size'] ?? 0 ) <= 0 ) {
				$errors[] = $ref . ': font_size must be > 0';
			}

			if ( ! is_bool( $field['multiline'] ?? false ) ) {
				$errors[] = $ref . ': multiline must be boolean';
			}

			if ( ! is_bool( $field['checkbox'] ?? false ) ) {
				$errors[] = $ref . ': checkbox must be boolean';
			}

			if ( ! empty( $field['multiline'] ) && (float) ( $field['max_width'] ?? 0 ) <= 0 ) {
				$warnings[] = $ref . ': multiline field has no max_width (will not wrap)';
			}
		}

		if ( '' === (string) ( $layout['template'] ?? '' ) ) {
			$warnings[] = 'layout has no template reference (official PDF)';
		}

		return array(
			'valid'    => empty( $errors ),
			'errors'   => array_values( $errors ),
			'warnings' => array_values( $warnings ),
		);
	}

	/**
	 * Resolve the page size for bounds checks, with optional official cross-check.
	 *
	 * @param array<string, mixed> $layout   Layout.
	 * @param array<string, mixed> $options  Options.
	 * @param string[]             $warnings Warnings (by reference).
	 * @return array{width: float, height: float}
	 */
	private function page_size( array $layout, array $options, array &$warnings ): array {
		$width  = (float) ( $layout['page_size']['width'] ?? 0 );
		$height = (float) ( $layout['page_size']['height'] ?? 0 );

		$template_path = (string) ( $options['template_path'] ?? '' );

		if ( '' !== $template_path && is_readable( $template_path ) ) {
			$official = Pdf_Page_Geometry::size( $template_path );

			if ( $width > 0 && $height > 0
				&& ( abs( $width - $official['width'] ) > 1.0 || abs( $height - $official['height'] ) > 1.0 ) ) {
				$warnings[] = sprintf(
					'layout page_size (%sx%s) differs from official PDF (%sx%s)',
					$width,
					$height,
					$official['width'],
					$official['height']
				);
			}

			if ( $width <= 0 || $height <= 0 ) {
				return $official;
			}
		}

		if ( $width > 0 && $height > 0 ) {
			return array(
				'width'  => $width,
				'height' => $height,
			);
		}

		return Pdf_Page_Geometry::default_size();
	}

	/**
	 * Build a human-readable field reference.
	 *
	 * @param array<string, mixed> $field Field.
	 * @param int|string           $index Index.
	 * @return string
	 */
	private function field_ref( array $field, $index ): string {
		$key = (string) ( $field['key'] ?? '' );

		return 'field[' . $index . ']' . ( '' !== $key ? ' "' . $key . '"' : '' );
	}
}
