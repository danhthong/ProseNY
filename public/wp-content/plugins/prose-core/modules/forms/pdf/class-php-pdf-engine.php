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
				$cmaps  = $this->get_page_font_cmaps( $objects, $page_obj );
				$text[] = $this->extract_text_from_content( $content, $cmaps );
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

		if ( preg_match_all( '/(\d+)\s+(\d+)\s+obj\s*(.*?)endobj/s', $raw, $matches, PREG_SET_ORDER ) ) {
			foreach ( $matches as $match ) {
				$num = (int) $match[1];
				$objects[ $num ] = array(
					'num'  => $num,
					'body' => $match[3],
				);
			}
		}

		// Modern PDFs (1.5+) pack non-stream objects (page, font, and resource
		// dictionaries) inside compressed object streams (/Type /ObjStm). Expand
		// them so font/ToUnicode resolution can reach those objects.
		return $this->expand_object_streams( $objects );
	}

	/**
	 * Expand objects packed inside object streams (/Type /ObjStm).
	 *
	 * Per the PDF spec, only non-stream objects may live in an object stream, so
	 * extracted bodies are plain dictionaries/arrays. Existing in-file objects
	 * take precedence and are never overwritten.
	 *
	 * @param array<int, array{num: int, body: string}> $objects Parsed objects.
	 * @return array<int, array{num: int, body: string}>
	 */
	private function expand_object_streams( array $objects ): array {
		foreach ( $objects as $obj ) {
			$body = $obj['body'];

			if ( ! str_contains( $body, '/ObjStm' ) ) {
				continue;
			}

			if ( ! preg_match( '/\/N\s+(\d+)/', $body, $nm ) || ! preg_match( '/\/First\s+(\d+)/', $body, $fm ) ) {
				continue;
			}

			$count = (int) $nm[1];
			$first = (int) $fm[1];
			$data  = $this->decode_stream( $body );

			if ( '' === $data || $count < 1 || $first < 1 || $first > strlen( $data ) ) {
				continue;
			}

			$header = substr( $data, 0, $first );

			if ( ! preg_match_all( '/(\d+)\s+(\d+)/', $header, $pairs, PREG_SET_ORDER ) ) {
				continue;
			}

			$entries = array_slice( $pairs, 0, $count );
			$total   = count( $entries );

			for ( $i = 0; $i < $total; $i++ ) {
				$onum   = (int) $entries[ $i ][1];
				$offset = $first + (int) $entries[ $i ][2];

				if ( $i + 1 < $total ) {
					$next  = $first + (int) $entries[ $i + 1 ][2];
					$obody = substr( $data, $offset, max( 0, $next - $offset ) );
				} else {
					$obody = substr( $data, $offset );
				}

				if ( '' === $obody || isset( $objects[ $onum ] ) ) {
					continue;
				}

				$objects[ $onum ] = array(
					'num'  => $onum,
					'body' => $obody,
				);
			}
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
		if ( preg_match_all( '/stream\r?\n?(.*?)\r?\n?endstream/s', $raw, $streams ) ) {
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

		if ( preg_match_all( '/stream\r?\n?(.*?)\r?\n?endstream/s', $raw, $streams ) ) {
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
		if ( ! preg_match( '/stream\r?\n?(.*?)\r?\n?endstream/s', $stream_body, $match ) ) {
			return '';
		}

		$data = $match[1];

		if ( str_contains( $stream_body, '/FlateDecode' ) ) {
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

		if ( str_contains( $stream_body, '/LZWDecode' ) ) {
			$decoded = $this->lzw_decode( $data );

			if ( '' !== $decoded ) {
				return $decoded;
			}
		}

		return $data;
	}

	/**
	 * Decode a PDF LZWDecode stream (variable-width codes, early change).
	 *
	 * @param string $data Compressed bytes.
	 * @return string
	 */
	private function lzw_decode( string $data ): string {
		$out         = '';
		$bit_buffer  = 0;
		$bit_count   = 0;
		$pos         = 0;
		$len         = strlen( $data );
		$dict        = array();
		$dict_size   = 258;
		$code_width  = 9;
		$previous    = null;

		for ( $i = 0; $i < 256; $i++ ) {
			$dict[ $i ] = chr( $i );
		}

		while ( true ) {
			while ( $bit_count < $code_width && $pos < $len ) {
				$bit_buffer = ( $bit_buffer << 8 ) | ord( $data[ $pos++ ] );
				$bit_count += 8;
			}

			if ( $bit_count < $code_width ) {
				break;
			}

			$code        = ( $bit_buffer >> ( $bit_count - $code_width ) ) & ( ( 1 << $code_width ) - 1 );
			$bit_count  -= $code_width;

			if ( 256 === $code ) {
				$dict = array();

				for ( $i = 0; $i < 256; $i++ ) {
					$dict[ $i ] = chr( $i );
				}

				$dict_size  = 258;
				$code_width = 9;
				$previous   = null;
				continue;
			}

			if ( 257 === $code ) {
				break;
			}

			if ( null === $previous ) {
				if ( ! isset( $dict[ $code ] ) ) {
					break;
				}

				$entry    = $dict[ $code ];
				$out     .= $entry;
				$previous = $entry;
				continue;
			}

			if ( isset( $dict[ $code ] ) ) {
				$entry = $dict[ $code ];
			} elseif ( $code === $dict_size ) {
				$entry = $previous . $previous[0];
			} else {
				break;
			}

			$out .= $entry;

			$dict[ $dict_size++ ] = $previous . $entry[0];
			$previous             = $entry;

			if ( $dict_size >= ( 1 << $code_width ) - 1 && $code_width < 12 ) {
				++$code_width;
			}
		}

		return $out;
	}

	/**
	 * Extract visible text from a content stream.
	 *
	 * Processes text-showing operators in document order. Fragments inside a
	 * single TJ array are concatenated (PDF kerning between glyphs of the same
	 * word uses small adjustments); only large negative adjustments and text
	 * positioning operators introduce a space.
	 *
	 * @param string                                  $content Decoded content stream.
	 * @param array<string, array{cid: bool, map: array<string, string>}> $cmaps Font CMaps by resource name.
	 * @return string
	 */
	private function extract_text_from_content( string $content, array $cmaps = array() ): string {
		$out  = '';
		$font = '';

		$pattern = '/\[((?:\\\\.|[^\]\\\\])*)\]\s*TJ'   // TJ array.
			. '|\(((?:\\\\.|[^\\\\)])*)\)\s*Tj'          // (string) Tj.
			. '|<([0-9A-Fa-f\s]+)>\s*Tj'                 // <hex> Tj.
			. '|\/([^\s\/\[\]<>()]+)\s+[-\d.]+\s+Tf'     // Font selection.
			. "|(T\\*|Td|TD|Tm|'|\")/s";                 // Layout / move operators.

		if ( ! preg_match_all( $pattern, $content, $matches, PREG_SET_ORDER ) ) {
			return '';
		}

		foreach ( $matches as $match ) {
			$full = rtrim( $match[0] );

			if ( str_ends_with( $full, 'Tf' ) ) {
				$font = $match[4] ?? '';
			} elseif ( str_ends_with( $full, 'TJ' ) ) {
				$out .= $this->decode_tj_array( $match[1] ?? '', $cmaps, $font );
			} elseif ( str_ends_with( $full, 'Tj' ) ) {
				if ( '' !== $full && '<' === $full[0] ) {
					$out .= $this->decode_show( $match[3] ?? '', true, $cmaps, $font );
				} else {
					$out .= $this->decode_show( $match[2] ?? '', false, $cmaps, $font );
				}
			} else {
				// Layout/move operator: treat as a separator.
				$out .= ' ';
			}
		}

		$out = preg_replace( '/[ \t]{2,}/', ' ', $out ) ?? $out;

		return trim( $out );
	}

	/**
	 * Decode a TJ array body into text.
	 *
	 * @param string                                  $body  Array body (between [ and ]).
	 * @param array<string, array{cid: bool, map: array<string, string>}> $cmaps Font CMaps.
	 * @param string                                  $font  Current font resource name.
	 * @return string
	 */
	private function decode_tj_array( string $body, array $cmaps = array(), string $font = '' ): string {
		$out = '';

		if ( ! preg_match_all( '/<([0-9A-Fa-f\s]+)>|\(((?:\\\\.|[^\\\\)])*)\)|(-?\d+(?:\.\d+)?)/s', $body, $tokens, PREG_SET_ORDER ) ) {
			return '';
		}

		foreach ( $tokens as $token ) {
			$raw = $token[0];

			if ( '' === $raw ) {
				continue;
			}

			if ( '<' === $raw[0] ) {
				$out .= $this->decode_show( $token[1] ?? '', true, $cmaps, $font );
			} elseif ( '(' === $raw[0] ) {
				$out .= $this->decode_show( $token[2] ?? '', false, $cmaps, $font );
			} else {
				// Numeric kerning adjustment. Large negative shifts are word spaces.
				if ( (float) $raw <= -200 ) {
					$out .= ' ';
				}
			}
		}

		return $out;
	}

	/**
	 * Decode a shown string using the current font's CMap when available.
	 *
	 * @param string                                  $raw    Raw string content (hex digits or literal body).
	 * @param bool                                    $is_hex Whether the source was a hex string.
	 * @param array<string, array{cid: bool, map: array<string, string>}> $cmaps Font CMaps.
	 * @param string                                  $font   Current font resource name.
	 * @return string
	 */
	private function decode_show( string $raw, bool $is_hex, array $cmaps, string $font ): string {
		$cmap = $cmaps[ $font ] ?? null;

		if ( null !== $cmap && ! empty( $cmap['cid'] ) ) {
			$hex = $is_hex
				? (string) preg_replace( '/\s+/', '', $raw )
				: bin2hex( $this->unescape_pdf_string( $raw ) );

			return $this->map_cid_hex( $hex, $cmap['map'] );
		}

		if ( $is_hex ) {
			return $this->hex_to_string( (string) preg_replace( '/\s+/', '', $raw ) );
		}

		return $this->unescape_pdf_string( $raw );
	}

	/**
	 * Map a hex string of 2-byte CID codes to Unicode using a ToUnicode map.
	 *
	 * @param string                $hex Hex digits.
	 * @param array<string, string> $map ToUnicode map (4-hex code => UTF-8).
	 * @return string
	 */
	private function map_cid_hex( string $hex, array $map ): string {
		$out = '';
		$len = strlen( $hex );

		for ( $i = 0; $i + 4 <= $len; $i += 4 ) {
			$code  = strtoupper( substr( $hex, $i, 4 ) );
			$out  .= $map[ $code ] ?? '';
		}

		return $out;
	}

	/**
	 * Build font CMaps for a page by resolving its resources.
	 *
	 * @param array<int, array{num: int, body: string}> $objects  Parsed objects.
	 * @param int                                        $page_obj Page object number.
	 * @return array<string, array{cid: bool, map: array<string, string>}>
	 */
	private function get_page_font_cmaps( array $objects, int $page_obj ): array {
		if ( ! isset( $objects[ $page_obj ] ) ) {
			return array();
		}

		$scope = $objects[ $page_obj ]['body'];

		if ( preg_match( '/\/Resources\s+(\d+)\s+\d+\s+R/', $scope, $rm ) && isset( $objects[ (int) $rm[1] ] ) ) {
			$scope .= ' ' . $objects[ (int) $rm[1] ]['body'];
		}

		$cmaps = array();

		if ( ! preg_match_all( '/\/([A-Za-z0-9][A-Za-z0-9._+-]*)\s+(\d+)\s+\d+\s+R/', $scope, $refs, PREG_SET_ORDER ) ) {
			return $cmaps;
		}

		foreach ( $refs as $ref ) {
			$name = $ref[1];
			$num  = (int) $ref[2];

			if ( isset( $cmaps[ $name ] ) || ! isset( $objects[ $num ] ) ) {
				continue;
			}

			$body = $objects[ $num ]['body'];

			if ( ! str_contains( $body, '/Font' ) && ! str_contains( $body, '/ToUnicode' ) && ! str_contains( $body, 'Type0' ) ) {
				continue;
			}

			$cmap = $this->resolve_font_cmap( $objects, $num );

			if ( null !== $cmap ) {
				$cmaps[ $name ] = $cmap;
			}
		}

		return $cmaps;
	}

	/**
	 * Resolve a font object's CMap (CID flag + ToUnicode map).
	 *
	 * @param array<int, array{num: int, body: string}> $objects Parsed objects.
	 * @param int                                        $num     Font object number.
	 * @return array{cid: bool, map: array<string, string>}|null
	 */
	private function resolve_font_cmap( array $objects, int $num ): ?array {
		if ( ! isset( $objects[ $num ] ) ) {
			return null;
		}

		$body   = $objects[ $num ]['body'];
		$is_cid = str_contains( $body, '/Type0' ) || str_contains( $body, '/Identity' ) || str_contains( $body, '/DescendantFonts' );
		$map    = array();

		if ( preg_match( '/\/ToUnicode\s+(\d+)\s+\d+\s+R/', $body, $tm ) && isset( $objects[ (int) $tm[1] ] ) ) {
			$cmap_text = $this->decode_stream( $objects[ (int) $tm[1] ]['body'] );
			$map       = $this->parse_tounicode( $cmap_text );
		}

		if ( empty( $map ) ) {
			return $is_cid ? array(
				'cid' => true,
				'map' => array(),
			) : null;
		}

		$two_byte = false;

		foreach ( $map as $code => $unused ) {
			$two_byte = ( 4 === strlen( (string) $code ) );
			break;
		}

		return array(
			'cid' => $is_cid || $two_byte,
			'map' => $map,
		);
	}

	/**
	 * Parse a ToUnicode CMap stream into a code => UTF-8 map.
	 *
	 * @param string $cmap_text Decoded CMap text.
	 * @return array<string, string>
	 */
	private function parse_tounicode( string $cmap_text ): array {
		$map = array();

		if ( preg_match_all( '/beginbfchar(.*?)endbfchar/s', $cmap_text, $bc ) ) {
			foreach ( $bc[1] as $block ) {
				if ( preg_match_all( '/<([0-9A-Fa-f]+)>\s*<([0-9A-Fa-f]+)>/', $block, $pairs, PREG_SET_ORDER ) ) {
					foreach ( $pairs as $pair ) {
						$map[ strtoupper( $pair[1] ) ] = $this->hex_to_utf16( $pair[2] );
					}
				}
			}
		}

		if ( preg_match_all( '/beginbfrange(.*?)endbfrange/s', $cmap_text, $br ) ) {
			foreach ( $br[1] as $block ) {
				// Range with array of destinations: <s> <e> [<d> <d> ...].
				if ( preg_match_all( '/<([0-9A-Fa-f]+)>\s*<([0-9A-Fa-f]+)>\s*\[(.*?)\]/s', $block, $arr, PREG_SET_ORDER ) ) {
					foreach ( $arr as $range ) {
						$start = hexdec( $range[1] );
						$width = strlen( $range[1] );

						if ( preg_match_all( '/<([0-9A-Fa-f]+)>/', $range[3], $dsts ) ) {
							foreach ( $dsts[1] as $offset => $dst ) {
								$code         = strtoupper( str_pad( dechex( $start + $offset ), $width, '0', STR_PAD_LEFT ) );
								$map[ $code ] = $this->hex_to_utf16( $dst );
							}
						}
					}
				}

				// Range with single destination: <s> <e> <d>.
				if ( preg_match_all( '/<([0-9A-Fa-f]+)>\s*<([0-9A-Fa-f]+)>\s*<([0-9A-Fa-f]+)>/', $block, $rng, PREG_SET_ORDER ) ) {
					foreach ( $rng as $range ) {
						$start = hexdec( $range[1] );
						$end   = hexdec( $range[2] );
						$dst   = hexdec( $range[3] );
						$width = strlen( $range[1] );

						for ( $code = $start; $code <= $end && ( $code - $start ) < 65536; $code++ ) {
							$key         = strtoupper( str_pad( dechex( $code ), $width, '0', STR_PAD_LEFT ) );
							$map[ $key ] = $this->code_point_to_utf8( $dst + ( $code - $start ) );
						}
					}
				}
			}
		}

		return $map;
	}

	/**
	 * Convert a UTF-16BE hex string (one or more code units) to UTF-8.
	 *
	 * @param string $hex Hex digits.
	 * @return string
	 */
	private function hex_to_utf16( string $hex ): string {
		$hex = preg_replace( '/[^0-9A-Fa-f]/', '', $hex ) ?? '';

		if ( '' === $hex || 0 !== strlen( $hex ) % 2 ) {
			return '';
		}

		$bytes = pack( 'H*', $hex );

		if ( false === $bytes ) {
			return '';
		}

		$utf8 = @mb_convert_encoding( $bytes, 'UTF-8', 'UTF-16BE' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

		return false !== $utf8 ? $utf8 : '';
	}

	/**
	 * Convert a Unicode code point to UTF-8.
	 *
	 * @param int $code_point Code point.
	 * @return string
	 */
	private function code_point_to_utf8( int $code_point ): string {
		if ( $code_point < 0 || $code_point > 0x10FFFF ) {
			return '';
		}

		$utf8 = @mb_convert_encoding( pack( 'N', $code_point ), 'UTF-8', 'UTF-32BE' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

		return false !== $utf8 ? $utf8 : '';
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
		// Octal escapes (\ddd) first.
		$value = preg_replace_callback(
			'/\\\\([0-7]{1,3})/',
			static function ( array $m ): string {
				return chr( octdec( $m[1] ) & 0xFF );
			},
			$value
		) ?? $value;

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
