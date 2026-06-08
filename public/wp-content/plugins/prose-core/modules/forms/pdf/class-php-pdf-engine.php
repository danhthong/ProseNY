<?php
/**
 * Zero-dependency PHP PDF text and AcroForm field extractor.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Forms\Pdf;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Php_Pdf_Engine
 */
final class Php_Pdf_Engine implements Pdf_Engine_Interface {

	/**
	 * {@inheritDoc}
	 */
	public function is_available(): bool {
		return true;
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_id(): string {
		return 'php';
	}

	/**
	 * {@inheritDoc}
	 */
	public function extract_text( string $file_path, int $max_pages = 3 ): string {
		if ( ! is_readable( $file_path ) ) {
			return '';
		}

		$raw = file_get_contents( $file_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

		if ( false === $raw || '' === $raw ) {
			return '';
		}

		$objects = $this->parse_objects( $raw );
		$pages   = $this->find_page_objects( $raw, $objects );
		$text    = array();

		foreach ( array_slice( $pages, 0, max( 1, $max_pages ) ) as $page_obj ) {
			$content = $this->get_page_content( $raw, $objects, $page_obj );

			if ( '' !== $content ) {
				$text[] = $this->extract_text_from_content( $content );
			}
		}

		return trim( implode( "\n", $text ) );
	}

	/**
	 * {@inheritDoc}
	 */
	public function extract_fields( string $file_path ): array {
		if ( ! is_readable( $file_path ) ) {
			return array();
		}

		$raw = file_get_contents( $file_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

		if ( false === $raw || '' === $raw ) {
			return array();
		}

		$objects = $this->parse_objects( $raw );
		$fields  = array();

		foreach ( $objects as $obj ) {
			if ( ! str_contains( $obj['body'], '/Subtype /Widget' ) && ! str_contains( $obj['body'], '/FT' ) ) {
				continue;
			}

			$name = $this->extract_pdf_string( $obj['body'], '/T' );

			if ( '' === $name ) {
				continue;
			}

			$fields[] = array(
				'name' => $name,
				'type' => $this->detect_field_type( $obj['body'] ),
			);
		}

		// Deduplicate by field name.
		$seen   = array();
		$unique = array();

		foreach ( $fields as $field ) {
			$key = $field['name'];

			if ( isset( $seen[ $key ] ) ) {
				continue;
			}

			$seen[ $key ]   = true;
			$unique[] = $field;
		}

		return $unique;
	}

	/**
	 * Parse PDF object bodies keyed by object number.
	 *
	 * @param string $raw Raw PDF bytes.
	 * @return array<int, array{num: int, body: string}>
	 */
	private function parse_objects( string $raw ): array {
		$objects = array();

		if ( ! preg_match_all( '/(\d+)\s+(\d+)\s+obj\s*(.*?)endobj/s', $raw, $matches, PREG_SET_ORDER ) ) {
			return $objects;
		}

		foreach ( $matches as $match ) {
			$num = (int) $match[1];
			$objects[ $num ] = array(
				'num'  => $num,
				'body' => $match[3],
			);
		}

		return $objects;
	}

	/**
	 * Find page object numbers in document order.
	 *
	 * @param string                              $raw     Raw PDF.
	 * @param array<int, array{num: int, body: string}> $objects Parsed objects.
	 * @return int[]
	 */
	private function find_page_objects( string $raw, array $objects ): array {
		$pages = array();

		foreach ( $objects as $num => $obj ) {
			if ( preg_match( '/\/Type\s*\/Page\b/', $obj['body'] ) && ! preg_match( '/\/Type\s*\/Pages\b/', $obj['body'] ) ) {
				$pages[] = $num;
			}
		}

		if ( ! empty( $pages ) ) {
			sort( $pages, SORT_NUMERIC );
			return $pages;
		}

		// Fallback: scan content streams directly.
		if ( preg_match_all( '/stream\r?\n(.*?)\r?\nendstream/s', $raw, $streams ) ) {
			return range( 0, min( 2, count( $streams[1] ) - 1 ) );
		}

		return array();
	}

	/**
	 * Get decoded page content stream.
	 *
	 * @param string                              $raw      Raw PDF.
	 * @param array<int, array{num: int, body: string}> $objects  Parsed objects.
	 * @param int                                 $page_obj Page object number or stream index.
	 * @return string
	 */
	private function get_page_content( string $raw, array $objects, int $page_obj ): string {
		if ( isset( $objects[ $page_obj ] ) ) {
			$body = $objects[ $page_obj ]['body'];

			if ( preg_match( '/\/Contents\s+(\d+)\s+(\d+)\s+R/', $body, $ref ) ) {
				$content_num = (int) $ref[1];

				if ( isset( $objects[ $content_num ] ) ) {
					return $this->decode_stream( $objects[ $content_num ]['body'] );
				}
			}
		}

		if ( preg_match_all( '/stream\r?\n(.*?)\r?\nendstream/s', $raw, $streams ) ) {
			$index = max( 0, $page_obj );

			if ( isset( $streams[1][ $index ] ) ) {
				return $this->decode_stream( "stream\n" . $streams[1][ $index ] . "\nendstream" );
			}
		}

		return '';
	}

	/**
	 * Decode a PDF stream (FlateDecode).
	 *
	 * @param string $stream_body Stream object body or stream block.
	 * @return string
	 */
	private function decode_stream( string $stream_body ): string {
		if ( ! preg_match( '/stream\r?\n(.*?)\r?\nendstream/s', $stream_body, $match ) ) {
			return '';
		}

		$data = $match[1];

		if ( str_contains( $stream_body, '/FlateDecode' ) || str_contains( $stream_body, '/Filter /FlateDecode' ) ) {
			$decoded = @gzuncompress( $data ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

			if ( false === $decoded ) {
				$decoded = @gzinflate( substr( $data, 2 ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			}

			if ( false === $decoded ) {
				$decoded = @gzinflate( $data ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			}

			if ( false !== $decoded ) {
				return $decoded;
			}
		}

		return $data;
	}

	/**
	 * Extract visible text from a content stream.
	 *
	 * @param string $content Decoded content stream.
	 * @return string
	 */
	private function extract_text_from_content( string $content ): string {
		$parts = array();

		// (text) Tj
		if ( preg_match_all( '/\((?:\\\\.|[^\\\\)])*+\)\s*Tj/s', $content, $tj ) ) {
			foreach ( $tj[0] as $match ) {
				if ( preg_match( '/\(((?:\\\\.|[^\\\\)])*+)\)\s*Tj/s', $match, $text_match ) ) {
					$parts[] = $this->unescape_pdf_string( $text_match[1] );
				}
			}
		}

		// [(text) num (text)] TJ
		if ( preg_match_all( '/\[(.*?)\]\s*TJ/s', $content, $tj_arrays ) ) {
			foreach ( $tj_arrays[1] as $array_content ) {
				if ( preg_match_all( '/\(((?:\\\\.|[^\\\\)])*+)\)/s', $array_content, $strings ) ) {
					foreach ( $strings[1] as $str ) {
						$parts[] = $this->unescape_pdf_string( $str );
					}
				}
			}
		}

		return trim( implode( ' ', $parts ) );
	}

	/**
	 * Extract a PDF string value for a key like /T.
	 *
	 * @param string $body Object body.
	 * @param string $key  PDF key.
	 * @return string
	 */
	private function extract_pdf_string( string $body, string $key ): string {
		$pattern = '/' . preg_quote( $key, '/' ) . '\s*\((?:\\\\.|[^\\\\)])*+\)/s';

		if ( preg_match( $pattern, $body, $match ) ) {
			if ( preg_match( '/\(((?:\\\\.|[^\\\\)])*+)\)/s', $match[0], $text ) ) {
				return $this->unescape_pdf_string( $text[1] );
			}
		}

		// Hex string /T <...>
		$hex_pattern = '/' . preg_quote( $key, '/' ) . '\s*<([0-9A-Fa-f\s]+)>/';

		if ( preg_match( $hex_pattern, $body, $hex_match ) ) {
			return $this->hex_to_string( preg_replace( '/\s+/', '', $hex_match[1] ) );
		}

		return '';
	}

	/**
	 * Unescape PDF string literals.
	 *
	 * @param string $value Raw string content.
	 * @return string
	 */
	private function unescape_pdf_string( string $value ): string {
		$map = array(
			'\\n'  => "\n",
			'\\r'  => "\r",
			'\\t'  => "\t",
			'\\b'  => "\x08",
			'\\f'  => "\x0C",
			'\\\\' => '\\',
			'\\('  => '(',
			'\\)'  => ')',
		);

		return strtr( $value, $map );
	}

	/**
	 * Convert hex string to UTF-8.
	 *
	 * @param string $hex Hex digits.
	 * @return string
	 */
	private function hex_to_string( string $hex ): string {
		if ( '' === $hex || 0 !== strlen( $hex ) % 2 ) {
			return '';
		}

		$bytes = pack( 'H*', $hex );

		return false !== $bytes ? $bytes : '';
	}

	/**
	 * Detect AcroForm field type from object body.
	 *
	 * @param string $body Object body.
	 * @return string
	 */
	private function detect_field_type( string $body ): string {
		if ( preg_match( '/\/FT\s*\/(\w+)/', $body, $match ) ) {
			switch ( strtolower( $match[1] ) ) {
				case 'btn':
					return 'checkbox';
				case 'ch':
					return 'choice';
				case 'sig':
					return 'signature';
				case 'tx':
				default:
					return 'text';
			}
		}

		return 'text';
	}
}
