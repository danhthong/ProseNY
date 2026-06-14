<?php
/**
 * Zero-dependency PDF parser supporting classic xref tables, cross-reference
 * streams, object streams, and FlateDecode (with PNG/TIFF predictors).
 *
 * Used by Pdf_Merger to read blank source court forms for packet assembly.
 * Reads structure only; content streams are preserved verbatim (never decoded).
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Packet;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Pdf_Parser
 */
class Pdf_Parser {

	/**
	 * Raw PDF bytes.
	 *
	 * @var string
	 */
	private string $data;

	/**
	 * Length of raw data.
	 *
	 * @var int
	 */
	private int $length;

	/**
	 * objnum => byte offset for uncompressed objects.
	 *
	 * @var array<int, int>
	 */
	private array $xref = array();

	/**
	 * objnum => [objstm_num, index] for compressed objects.
	 *
	 * @var array<int, array{0:int,1:int}>
	 */
	private array $compressed = array();

	/**
	 * Parsed object cache (objnum => value).
	 *
	 * @var array<int, mixed>
	 */
	private array $cache = array();

	/**
	 * Decoded object-stream cache (objstm_num => [objnum => value]).
	 *
	 * @var array<int, array<int, mixed>>
	 */
	private array $objstm_cache = array();

	/**
	 * Trailer dictionary value.
	 *
	 * @var array<string, mixed>
	 */
	private array $trailer = array();

	/**
	 * Visited xref offsets (loop guard).
	 *
	 * @var array<int, bool>
	 */
	private array $visited_xref = array();

	/**
	 * Constructor.
	 *
	 * @param string $data Raw PDF bytes.
	 *
	 * @throws \RuntimeException When the PDF cannot be parsed.
	 */
	public function __construct( string $data ) {
		$this->data   = $data;
		$this->length = strlen( $data );
		$this->parse_xref();
	}

	/**
	 * Get the trailer dictionary.
	 *
	 * @return array<string, mixed>
	 */
	public function trailer(): array {
		return $this->trailer;
	}

	/**
	 * Resolve a value: if it is a reference, fetch the referenced object.
	 *
	 * @param mixed $value Value.
	 * @return mixed
	 */
	public function resolve( $value ) {
		if ( is_array( $value ) && 'ref' === ( $value['t'] ?? '' ) ) {
			return $this->get_object( (int) $value['num'] );
		}

		return $value;
	}

	/**
	 * Get an indirect object value by number.
	 *
	 * @param int $num Object number.
	 * @return mixed
	 */
	public function get_object( int $num ) {
		if ( array_key_exists( $num, $this->cache ) ) {
			return $this->cache[ $num ];
		}

		if ( isset( $this->compressed[ $num ] ) ) {
			$value              = $this->load_compressed_object( $num );
			$this->cache[ $num ] = $value;

			return $value;
		}

		if ( ! isset( $this->xref[ $num ] ) ) {
			$this->cache[ $num ] = null;

			return null;
		}

		$pos                = $this->xref[ $num ];
		$value              = $this->read_indirect_object_at( $pos );
		$this->cache[ $num ] = $value;

		return $value;
	}

	/**
	 * Collect all page object numbers in document order.
	 *
	 * @return array<int, int>
	 */
	public function get_page_numbers(): array {
		$root = $this->resolve( $this->trailer['Root'] ?? null );

		if ( ! $this->is_dict( $root ) ) {
			return array();
		}

		$pages_ref = $root['v']['Pages'] ?? null;

		if ( ! is_array( $pages_ref ) || 'ref' !== ( $pages_ref['t'] ?? '' ) ) {
			return array();
		}

		$pages = array();
		$this->walk_page_tree( (int) $pages_ref['num'], $pages, array() );

		return $pages;
	}

	/**
	 * Get the inheritable attributes (MediaBox, Resources, CropBox, Rotate) for
	 * a page, resolving inheritance up the page tree.
	 *
	 * @param int $page_num Page object number.
	 * @return array<string, mixed> Map of attribute name => value (refs or direct).
	 */
	public function inherited_attributes( int $page_num ): array {
		$inheritable = array( 'MediaBox', 'Resources', 'CropBox', 'Rotate' );
		$found       = array();
		$current     = $page_num;
		$guard       = 0;

		while ( $current > 0 && $guard < 64 ) {
			$guard++;
			$node = $this->get_object( $current );

			if ( ! $this->is_dict( $node ) ) {
				break;
			}

			foreach ( $inheritable as $attr ) {
				if ( ! array_key_exists( $attr, $found ) && array_key_exists( $attr, $node['v'] ) ) {
					$found[ $attr ] = $node['v'][ $attr ];
				}
			}

			$parent = $node['v']['Parent'] ?? null;

			if ( ! is_array( $parent ) || 'ref' !== ( $parent['t'] ?? '' ) ) {
				break;
			}

			$current = (int) $parent['num'];
		}

		return $found;
	}

	/**
	 * Walk the page tree collecting leaf page object numbers.
	 *
	 * @param int             $num     Node object number.
	 * @param array<int, int> $pages   Accumulator (by reference).
	 * @param array<int, bool> $visited Visited guard.
	 * @return void
	 */
	private function walk_page_tree( int $num, array &$pages, array $visited ): void {
		if ( isset( $visited[ $num ] ) || count( $pages ) > 5000 ) {
			return;
		}

		$visited[ $num ] = true;
		$node            = $this->get_object( $num );

		if ( ! $this->is_dict( $node ) ) {
			return;
		}

		$type = $this->name_value( $node['v']['Type'] ?? null );

		if ( 'Page' === $type ) {
			$pages[] = $num;

			return;
		}

		$kids = $this->resolve( $node['v']['Kids'] ?? null );

		if ( ! is_array( $kids ) || 'array' !== ( $kids['t'] ?? '' ) ) {
			return;
		}

		foreach ( $kids['v'] as $kid ) {
			if ( is_array( $kid ) && 'ref' === ( $kid['t'] ?? '' ) ) {
				$this->walk_page_tree( (int) $kid['num'], $pages, $visited );
			}
		}
	}

	/**
	 * Parse the cross-reference data starting from the last startxref.
	 *
	 * @return void
	 *
	 * @throws \RuntimeException When startxref cannot be located.
	 */
	private function parse_xref(): void {
		$pos = strrpos( $this->data, 'startxref' );

		if ( false === $pos ) {
			throw new \RuntimeException( 'startxref not found' );
		}

		$pos += strlen( 'startxref' );
		$this->skip_ws( $pos );
		$offset = (int) $this->read_integer_token( $pos );

		$this->read_xref_section( $offset );

		if ( empty( $this->xref ) && empty( $this->compressed ) ) {
			// Fallback: brute-force scan for "N G obj".
			$this->rebuild_xref_by_scan();
		}
	}

	/**
	 * Read one cross-reference section (classic table or xref stream), following
	 * /Prev and /XRefStm chains.
	 *
	 * @param int $offset Byte offset of the section.
	 * @return void
	 */
	private function read_xref_section( int $offset ): void {
		if ( $offset < 0 || $offset >= $this->length || isset( $this->visited_xref[ $offset ] ) ) {
			return;
		}

		$this->visited_xref[ $offset ] = true;

		$pos = $offset;
		$this->skip_ws( $pos );

		if ( 'xref' === substr( $this->data, $pos, 4 ) ) {
			$this->read_classic_xref( $pos );

			return;
		}

		$this->read_xref_stream( $offset );
	}

	/**
	 * Read a classic xref table and its trailer.
	 *
	 * @param int $pos Position at the "xref" keyword.
	 * @return void
	 */
	private function read_classic_xref( int $pos ): void {
		$pos += 4;
		$this->skip_ws( $pos );

		while ( $pos < $this->length && ctype_digit( $this->data[ $pos ] ) ) {
			$start = (int) $this->read_integer_token( $pos );
			$this->skip_ws( $pos );
			$count = (int) $this->read_integer_token( $pos );
			$this->skip_ws( $pos );

			for ( $i = 0; $i < $count; $i++ ) {
				$entry = substr( $this->data, $pos, 20 );
				$pos  += 20;

				$off  = (int) substr( $entry, 0, 10 );
				$type = substr( $entry, 17, 1 );
				$num  = $start + $i;

				if ( 'n' === $type && ! isset( $this->xref[ $num ] ) && ! isset( $this->compressed[ $num ] ) ) {
					$this->xref[ $num ] = $off;
				}
			}

			$this->skip_ws( $pos );
		}

		if ( 'trailer' === substr( $this->data, $pos, 7 ) ) {
			$pos += 7;
			$this->skip_ws( $pos );
			$trailer = $this->read_value( $pos );

			if ( $this->is_dict( $trailer ) ) {
				$this->merge_trailer( $trailer['v'] );

				if ( isset( $trailer['v']['XRefStm'] ) ) {
					$xref_stm = $this->resolve_int( $trailer['v']['XRefStm'] );

					if ( null !== $xref_stm ) {
						$this->read_xref_section( $xref_stm );
					}
				}

				if ( isset( $trailer['v']['Prev'] ) ) {
					$prev = $this->resolve_int( $trailer['v']['Prev'] );

					if ( null !== $prev ) {
						$this->read_xref_section( $prev );
					}
				}
			}
		}
	}

	/**
	 * Read a cross-reference stream.
	 *
	 * @param int $offset Byte offset of the xref stream object.
	 * @return void
	 */
	private function read_xref_stream( int $offset ): void {
		$obj = $this->read_indirect_object_at( $offset );

		if ( ! is_array( $obj ) || 'stream' !== ( $obj['t'] ?? '' ) ) {
			return;
		}

		$dict = $obj['dict'];
		$this->merge_trailer( $dict );

		$decoded = $this->decode_stream( $dict, $obj['raw'] );

		if ( '' === $decoded ) {
			return;
		}

		$w = $this->resolve( $dict['W'] ?? null );

		if ( ! is_array( $w ) || 'array' !== ( $w['t'] ?? '' ) ) {
			return;
		}

		$w0 = (int) $this->scalar_num( $w['v'][0] ?? null );
		$w1 = (int) $this->scalar_num( $w['v'][1] ?? null );
		$w2 = (int) $this->scalar_num( $w['v'][2] ?? null );

		$size  = (int) ( $this->resolve_int( $dict['Size'] ?? null ) ?? 0 );
		$index = $this->resolve( $dict['Index'] ?? null );

		if ( is_array( $index ) && 'array' === ( $index['t'] ?? '' ) ) {
			$pairs = array();

			foreach ( $index['v'] as $entry ) {
				$pairs[] = (int) $this->scalar_num( $entry );
			}
		} else {
			$pairs = array( 0, $size );
		}

		$row_len = $w0 + $w1 + $w2;

		if ( $row_len <= 0 ) {
			return;
		}

		$pos = 0;

		for ( $p = 0; $p + 1 < count( $pairs ); $p += 2 ) {
			$start = $pairs[ $p ];
			$count = $pairs[ $p + 1 ];

			for ( $i = 0; $i < $count; $i++ ) {
				if ( $pos + $row_len > strlen( $decoded ) ) {
					break 2;
				}

				$f0 = $w0 > 0 ? $this->read_bytes_int( $decoded, $pos, $w0 ) : 1;
				$f1 = $this->read_bytes_int( $decoded, $pos + $w0, $w1 );
				$f2 = $this->read_bytes_int( $decoded, $pos + $w0 + $w1, $w2 );
				$pos += $row_len;

				$num = $start + $i;

				if ( isset( $this->xref[ $num ] ) || isset( $this->compressed[ $num ] ) ) {
					continue;
				}

				if ( 1 === $f0 ) {
					$this->xref[ $num ] = $f1;
				} elseif ( 2 === $f0 ) {
					$this->compressed[ $num ] = array( $f1, $f2 );
				}
			}
		}

		if ( isset( $dict['Prev'] ) ) {
			$prev = $this->resolve_int( $dict['Prev'] );

			if ( null !== $prev ) {
				$this->read_xref_section( $prev );
			}
		}
	}

	/**
	 * Load an object that is stored inside an object stream.
	 *
	 * @param int $num Object number.
	 * @return mixed
	 */
	private function load_compressed_object( int $num ) {
		list( $stm_num, $index ) = $this->compressed[ $num ];

		if ( ! isset( $this->objstm_cache[ $stm_num ] ) ) {
			$this->objstm_cache[ $stm_num ] = $this->parse_object_stream( $stm_num );
		}

		return $this->objstm_cache[ $stm_num ][ $num ] ?? null;
	}

	/**
	 * Parse an object stream into a map of objnum => value.
	 *
	 * @param int $stm_num Object-stream object number.
	 * @return array<int, mixed>
	 */
	private function parse_object_stream( int $stm_num ): array {
		$result = array();

		if ( ! isset( $this->xref[ $stm_num ] ) ) {
			return $result;
		}

		$obj = $this->read_indirect_object_at( $this->xref[ $stm_num ] );

		if ( ! is_array( $obj ) || 'stream' !== ( $obj['t'] ?? '' ) ) {
			return $result;
		}

		$dict    = $obj['dict'];
		$decoded = $this->decode_stream( $dict, $obj['raw'] );
		$n       = (int) ( $this->resolve_int( $dict['N'] ?? null ) ?? 0 );
		$first   = (int) ( $this->resolve_int( $dict['First'] ?? null ) ?? 0 );

		$header_pos = 0;
		$entries    = array();

		for ( $i = 0; $i < $n; $i++ ) {
			$this->skip_ws_in( $decoded, $header_pos );
			$onum = (int) $this->read_integer_token_in( $decoded, $header_pos );
			$this->skip_ws_in( $decoded, $header_pos );
			$ooff = (int) $this->read_integer_token_in( $decoded, $header_pos );
			$entries[] = array( $onum, $ooff );
		}

		foreach ( $entries as $entry ) {
			list( $onum, $ooff ) = $entry;
			$value_pos           = $first + $ooff;
			$value               = $this->read_value_in( $decoded, $value_pos );
			$result[ $onum ]     = $value;
		}

		return $result;
	}

	/**
	 * Read an indirect object ("N G obj ... endobj") at a byte offset.
	 *
	 * @param int $offset Byte offset.
	 * @return mixed
	 */
	private function read_indirect_object_at( int $offset ) {
		$pos = $offset;
		$this->skip_ws( $pos );
		$this->read_integer_token( $pos ); // object number.
		$this->skip_ws( $pos );
		$this->read_integer_token( $pos ); // generation.
		$this->skip_ws( $pos );

		if ( 'obj' === substr( $this->data, $pos, 3 ) ) {
			$pos += 3;
		}

		$this->skip_ws( $pos );
		$value = $this->read_value( $pos );

		$this->skip_ws( $pos );

		if ( $this->is_dict( $value ) && 'stream' === substr( $this->data, $pos, 6 ) ) {
			$pos += 6;

			if ( "\r" === ( $this->data[ $pos ] ?? '' ) ) {
				$pos++;
			}

			if ( "\n" === ( $this->data[ $pos ] ?? '' ) ) {
				$pos++;
			}

			$len = $this->resolve_int( $value['v']['Length'] ?? null );
			$raw = $this->extract_stream_raw( $pos, $len );

			return array(
				't'    => 'stream',
				'dict' => $value['v'],
				'raw'  => $raw,
			);
		}

		return $value;
	}

	/**
	 * Extract raw stream bytes using Length when reliable, else by searching
	 * for the endstream keyword.
	 *
	 * @param int      $start Start of stream data.
	 * @param int|null $len   Declared length or null.
	 * @return string
	 */
	private function extract_stream_raw( int $start, ?int $len ): string {
		if ( null !== $len && $len >= 0 && $start + $len <= $this->length ) {
			$candidate = substr( $this->data, $start, $len );
			$after     = $start + $len;
			$probe     = $after;
			$this->skip_ws( $probe );

			if ( 'endstream' === substr( $this->data, $probe, 9 ) ) {
				return $candidate;
			}
		}

		$end = strpos( $this->data, 'endstream', $start );

		if ( false === $end ) {
			return '';
		}

		$raw = substr( $this->data, $start, $end - $start );

		// Trim a single trailing EOL that belongs to the keyword, not the data.
		if ( "\n" === substr( $raw, -1 ) ) {
			$raw = substr( $raw, 0, -1 );

			if ( "\r" === substr( $raw, -1 ) ) {
				$raw = substr( $raw, 0, -1 );
			}
		} elseif ( "\r" === substr( $raw, -1 ) ) {
			$raw = substr( $raw, 0, -1 );
		}

		return $raw;
	}

	/**
	 * Decode a stream applying its filters (FlateDecode + predictors, ASCII85,
	 * ASCIIHex). Returns raw bytes unchanged when no supported filter applies.
	 *
	 * @param array<string, mixed> $dict Stream dictionary.
	 * @param string               $raw  Raw stream bytes.
	 * @return string
	 */
	private function decode_stream( array $dict, string $raw ): string {
		$filter = $this->resolve( $dict['Filter'] ?? null );

		if ( null === $filter ) {
			return $raw;
		}

		$filters = array();

		if ( is_array( $filter ) && 'name' === ( $filter['t'] ?? '' ) ) {
			$filters[] = $filter['v'];
		} elseif ( is_array( $filter ) && 'array' === ( $filter['t'] ?? '' ) ) {
			foreach ( $filter['v'] as $f ) {
				$filters[] = $this->name_value( $f );
			}
		}

		$parms     = $this->resolve( $dict['DecodeParms'] ?? ( $dict['DP'] ?? null ) );
		$parm_list = array();

		if ( is_array( $parms ) && 'dict' === ( $parms['t'] ?? '' ) ) {
			$parm_list[] = $parms['v'];
		} elseif ( is_array( $parms ) && 'array' === ( $parms['t'] ?? '' ) ) {
			foreach ( $parms['v'] as $p ) {
				$resolved    = $this->resolve( $p );
				$parm_list[] = $this->is_dict( $resolved ) ? $resolved['v'] : array();
			}
		}

		$data = $raw;

		foreach ( $filters as $i => $name ) {
			$parm = $parm_list[ $i ] ?? array();

			if ( in_array( $name, array( 'FlateDecode', 'Fl' ), true ) ) {
				$data = $this->inflate( $data );
				$data = $this->apply_predictor( $data, $parm );
			} elseif ( in_array( $name, array( 'ASCII85Decode', 'A85' ), true ) ) {
				$data = $this->ascii85_decode( $data );
			} elseif ( in_array( $name, array( 'ASCIIHexDecode', 'AHx' ), true ) ) {
				$data = $this->asciihex_decode( $data );
			} else {
				// Unsupported filter (e.g. content-stream specific); stop decoding.
				break;
			}
		}

		return $data;
	}

	/**
	 * Inflate zlib/raw-deflate data.
	 *
	 * @param string $data Compressed data.
	 * @return string
	 */
	private function inflate( string $data ): string {
		$out = @gzuncompress( $data ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

		if ( false !== $out ) {
			return $out;
		}

		$out = @gzinflate( $data ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

		if ( false !== $out ) {
			return $out;
		}

		$out = @gzinflate( substr( $data, 2 ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

		return false === $out ? '' : $out;
	}

	/**
	 * Apply a PDF predictor (TIFF 2 or PNG 10-15) to decoded data.
	 *
	 * @param string               $data Decoded data.
	 * @param array<string, mixed> $parm Decode parameters.
	 * @return string
	 */
	private function apply_predictor( string $data, array $parm ): string {
		$predictor = (int) ( $this->resolve_int( $parm['Predictor'] ?? null ) ?? 1 );

		if ( $predictor <= 1 ) {
			return $data;
		}

		$colors  = (int) ( $this->resolve_int( $parm['Colors'] ?? null ) ?? 1 );
		$bpc     = (int) ( $this->resolve_int( $parm['BitsPerComponent'] ?? null ) ?? 8 );
		$columns = (int) ( $this->resolve_int( $parm['Columns'] ?? null ) ?? 1 );

		$bpp     = (int) ceil( $colors * $bpc / 8 );
		$bpp     = max( 1, $bpp );
		$row_len = (int) ceil( $colors * $bpc / 8 * $columns );

		if ( $row_len <= 0 ) {
			return $data;
		}

		if ( 2 === $predictor ) {
			return $this->tiff_predictor( $data, $row_len, $bpp );
		}

		return $this->png_predictor( $data, $row_len, $bpp );
	}

	/**
	 * Apply a PNG predictor.
	 *
	 * @param string $data    Filtered data.
	 * @param int    $row_len Bytes per row (excluding filter tag).
	 * @param int    $bpp     Bytes per pixel.
	 * @return string
	 */
	private function png_predictor( string $data, int $row_len, int $bpp ): string {
		$out  = '';
		$prev = str_repeat( "\x00", $row_len );
		$len  = strlen( $data );
		$pos  = 0;

		while ( $pos + 1 + $row_len <= $len ) {
			$ft  = ord( $data[ $pos ] );
			$pos++;
			$row = substr( $data, $pos, $row_len );
			$pos += $row_len;

			$decoded = $this->png_unfilter_row( $ft, $row, $prev, $bpp, $row_len );
			$out    .= $decoded;
			$prev    = $decoded;
		}

		return $out;
	}

	/**
	 * Reverse a single PNG row filter.
	 *
	 * @param int    $ft      Filter type.
	 * @param string $row     Raw row bytes.
	 * @param string $prev    Previous decoded row.
	 * @param int    $bpp     Bytes per pixel.
	 * @param int    $row_len Row length.
	 * @return string
	 */
	private function png_unfilter_row( int $ft, string $row, string $prev, int $bpp, int $row_len ): string {
		$out = '';

		for ( $i = 0; $i < $row_len; $i++ ) {
			$x = ord( $row[ $i ] );
			$a = $i >= $bpp ? ord( $out[ $i - $bpp ] ) : 0;
			$b = ord( $prev[ $i ] );
			$c = $i >= $bpp ? ord( $prev[ $i - $bpp ] ) : 0;

			switch ( $ft ) {
				case 1:
					$val = $x + $a;
					break;
				case 2:
					$val = $x + $b;
					break;
				case 3:
					$val = $x + intdiv( $a + $b, 2 );
					break;
				case 4:
					$val = $x + $this->paeth( $a, $b, $c );
					break;
				default:
					$val = $x;
					break;
			}

			$out .= chr( $val & 0xFF );
		}

		return $out;
	}

	/**
	 * Paeth predictor.
	 *
	 * @param int $a Left.
	 * @param int $b Above.
	 * @param int $c Upper-left.
	 * @return int
	 */
	private function paeth( int $a, int $b, int $c ): int {
		$p  = $a + $b - $c;
		$pa = abs( $p - $a );
		$pb = abs( $p - $b );
		$pc = abs( $p - $c );

		if ( $pa <= $pb && $pa <= $pc ) {
			return $a;
		}

		return $pb <= $pc ? $b : $c;
	}

	/**
	 * Apply a TIFF predictor (horizontal differencing, 8-bit).
	 *
	 * @param string $data    Filtered data.
	 * @param int    $row_len Bytes per row.
	 * @param int    $bpp     Bytes per pixel.
	 * @return string
	 */
	private function tiff_predictor( string $data, int $row_len, int $bpp ): string {
		$out  = '';
		$len  = strlen( $data );
		$rows = (int) floor( $len / $row_len );

		for ( $r = 0; $r < $rows; $r++ ) {
			$row = substr( $data, $r * $row_len, $row_len );

			for ( $i = 0; $i < $row_len; $i++ ) {
				$x   = ord( $row[ $i ] );
				$a   = $i >= $bpp ? ord( $out[ strlen( $out ) - $bpp ] ) : 0;
				$val = ( $x + $a ) & 0xFF;
				$out .= chr( $val );
			}
		}

		return $out;
	}

	/**
	 * Decode ASCII85.
	 *
	 * @param string $data Encoded data.
	 * @return string
	 */
	private function ascii85_decode( string $data ): string {
		$data = preg_replace( '/\s/', '', $data );
		$data = (string) $data;

		if ( 0 === strpos( $data, '<~' ) ) {
			$data = substr( $data, 2 );
		}

		$end = strpos( $data, '~>' );

		if ( false !== $end ) {
			$data = substr( $data, 0, $end );
		}

		$out   = '';
		$chunk = array();

		$len = strlen( $data );

		for ( $i = 0; $i < $len; $i++ ) {
			$ch = $data[ $i ];

			if ( 'z' === $ch && empty( $chunk ) ) {
				$out .= "\x00\x00\x00\x00";
				continue;
			}

			$chunk[] = ord( $ch ) - 33;

			if ( 5 === count( $chunk ) ) {
				$value = 0;

				foreach ( $chunk as $c ) {
					$value = $value * 85 + $c;
				}

				$out  .= chr( ( $value >> 24 ) & 0xFF ) . chr( ( $value >> 16 ) & 0xFF ) . chr( ( $value >> 8 ) & 0xFF ) . chr( $value & 0xFF );
				$chunk = array();
			}
		}

		$count = count( $chunk );

		if ( $count > 0 ) {
			for ( $i = $count; $i < 5; $i++ ) {
				$chunk[] = 84;
			}

			$value = 0;

			foreach ( $chunk as $c ) {
				$value = $value * 85 + $c;
			}

			$bytes = chr( ( $value >> 24 ) & 0xFF ) . chr( ( $value >> 16 ) & 0xFF ) . chr( ( $value >> 8 ) & 0xFF ) . chr( $value & 0xFF );
			$out  .= substr( $bytes, 0, $count - 1 );
		}

		return $out;
	}

	/**
	 * Decode ASCIIHex.
	 *
	 * @param string $data Encoded data.
	 * @return string
	 */
	private function asciihex_decode( string $data ): string {
		$end = strpos( $data, '>' );

		if ( false !== $end ) {
			$data = substr( $data, 0, $end );
		}

		$data = preg_replace( '/[^0-9A-Fa-f]/', '', $data );
		$data = (string) $data;

		if ( 0 !== strlen( $data ) % 2 ) {
			$data .= '0';
		}

		return (string) hex2bin( $data );
	}

	/* ---------------------------------------------------------------------
	 * Value reader (operates on $this->data via a cursor).
	 * ------------------------------------------------------------------- */

	/**
	 * Read a value at the cursor in the main document.
	 *
	 * @param int $pos Cursor (by reference).
	 * @return mixed
	 */
	private function read_value( int &$pos ) {
		return $this->read_value_from( $this->data, $pos );
	}

	/**
	 * Read a value from an arbitrary buffer.
	 *
	 * @param string $buf Buffer.
	 * @param int    $pos Cursor (by reference).
	 * @return mixed
	 */
	private function read_value_from( string $buf, int &$pos ) {
		$this->skip_ws_in( $buf, $pos );

		if ( $pos >= strlen( $buf ) ) {
			return null;
		}

		$ch = $buf[ $pos ];

		if ( '/' === $ch ) {
			return $this->read_name_in( $buf, $pos );
		}

		if ( '(' === $ch ) {
			return $this->read_literal_string_in( $buf, $pos );
		}

		if ( '<' === $ch ) {
			if ( '<' === ( $buf[ $pos + 1 ] ?? '' ) ) {
				return $this->read_dict_in( $buf, $pos );
			}

			return $this->read_hex_string_in( $buf, $pos );
		}

		if ( '[' === $ch ) {
			return $this->read_array_in( $buf, $pos );
		}

		if ( ctype_digit( $ch ) || '+' === $ch || '-' === $ch || '.' === $ch ) {
			return $this->read_number_or_ref_in( $buf, $pos );
		}

		if ( 't' === $ch && 'true' === substr( $buf, $pos, 4 ) ) {
			$pos += 4;

			return array(
				't' => 'bool',
				'v' => true,
			);
		}

		if ( 'f' === $ch && 'false' === substr( $buf, $pos, 5 ) ) {
			$pos += 5;

			return array(
				't' => 'bool',
				'v' => false,
			);
		}

		if ( 'n' === $ch && 'null' === substr( $buf, $pos, 4 ) ) {
			$pos += 4;

			return array( 't' => 'null' );
		}

		$pos++;

		return null;
	}

	/**
	 * Read a name token.
	 *
	 * @param string $buf Buffer.
	 * @param int    $pos Cursor (by reference).
	 * @return array{t:string,v:string}
	 */
	private function read_name_in( string $buf, int &$pos ): array {
		$pos++;
		$name = '';
		$len  = strlen( $buf );

		while ( $pos < $len ) {
			$ch = $buf[ $pos ];

			if ( $this->is_delimiter( $ch ) || $this->is_ws( $ch ) ) {
				break;
			}

			if ( '#' === $ch && $pos + 2 < $len && ctype_xdigit( $buf[ $pos + 1 ] ) && ctype_xdigit( $buf[ $pos + 2 ] ) ) {
				$name .= chr( (int) hexdec( substr( $buf, $pos + 1, 2 ) ) );
				$pos  += 3;
				continue;
			}

			$name .= $ch;
			$pos++;
		}

		return array(
			't' => 'name',
			'v' => $name,
		);
	}

	/**
	 * Read a literal string token.
	 *
	 * @param string $buf Buffer.
	 * @param int    $pos Cursor (by reference).
	 * @return array{t:string,v:string}
	 */
	private function read_literal_string_in( string $buf, int &$pos ): array {
		$pos++;
		$depth = 1;
		$out   = '';
		$len   = strlen( $buf );

		while ( $pos < $len && $depth > 0 ) {
			$ch = $buf[ $pos ];

			if ( '\\' === $ch ) {
				$next = $buf[ $pos + 1 ] ?? '';

				switch ( $next ) {
					case 'n':
						$out .= "\n";
						$pos += 2;
						break;
					case 'r':
						$out .= "\r";
						$pos += 2;
						break;
					case 't':
						$out .= "\t";
						$pos += 2;
						break;
					case 'b':
						$out .= "\x08";
						$pos += 2;
						break;
					case 'f':
						$out .= "\x0C";
						$pos += 2;
						break;
					case '(':
						$out .= '(';
						$pos += 2;
						break;
					case ')':
						$out .= ')';
						$pos += 2;
						break;
					case '\\':
						$out .= '\\';
						$pos += 2;
						break;
					default:
						if ( ctype_digit( $next ) ) {
							$oct = $next;
							$pos += 2;

							for ( $k = 0; $k < 2 && isset( $buf[ $pos ] ) && ctype_digit( $buf[ $pos ] ); $k++ ) {
								$oct .= $buf[ $pos ];
								$pos++;
							}

							$out .= chr( (int) octdec( $oct ) & 0xFF );
						} elseif ( "\n" === $next ) {
							$pos += 2;
						} elseif ( "\r" === $next ) {
							$pos += 2;

							if ( "\n" === ( $buf[ $pos ] ?? '' ) ) {
								$pos++;
							}
						} else {
							$out .= $next;
							$pos += 2;
						}
						break;
				}

				continue;
			}

			if ( '(' === $ch ) {
				$depth++;
				$out .= $ch;
				$pos++;
				continue;
			}

			if ( ')' === $ch ) {
				$depth--;

				if ( 0 === $depth ) {
					$pos++;
					break;
				}

				$out .= $ch;
				$pos++;
				continue;
			}

			$out .= $ch;
			$pos++;
		}

		return array(
			't'   => 'str',
			'v'   => $out,
			'hex' => false,
		);
	}

	/**
	 * Read a hex string token.
	 *
	 * @param string $buf Buffer.
	 * @param int    $pos Cursor (by reference).
	 * @return array{t:string,v:string}
	 */
	private function read_hex_string_in( string $buf, int &$pos ): array {
		$pos++;
		$hex = '';
		$len = strlen( $buf );

		while ( $pos < $len && '>' !== $buf[ $pos ] ) {
			$ch = $buf[ $pos ];

			if ( ctype_xdigit( $ch ) ) {
				$hex .= $ch;
			}

			$pos++;
		}

		$pos++;

		if ( 0 !== strlen( $hex ) % 2 ) {
			$hex .= '0';
		}

		return array(
			't'   => 'str',
			'v'   => (string) hex2bin( $hex ),
			'hex' => true,
		);
	}

	/**
	 * Read a dictionary (and possibly nothing more; stream handled by caller).
	 *
	 * @param string $buf Buffer.
	 * @param int    $pos Cursor (by reference).
	 * @return array{t:string,v:array<string,mixed>}
	 */
	private function read_dict_in( string $buf, int &$pos ): array {
		$pos += 2;
		$dict = array();
		$len  = strlen( $buf );

		while ( $pos < $len ) {
			$this->skip_ws_in( $buf, $pos );

			if ( '>' === ( $buf[ $pos ] ?? '' ) && '>' === ( $buf[ $pos + 1 ] ?? '' ) ) {
				$pos += 2;
				break;
			}

			if ( '/' !== ( $buf[ $pos ] ?? '' ) ) {
				// Malformed; bail to avoid infinite loop.
				$pos++;
				continue;
			}

			$key   = $this->read_name_in( $buf, $pos );
			$value = $this->read_value_from( $buf, $pos );

			$dict[ $key['v'] ] = $value;
		}

		return array(
			't' => 'dict',
			'v' => $dict,
		);
	}

	/**
	 * Read an array token.
	 *
	 * @param string $buf Buffer.
	 * @param int    $pos Cursor (by reference).
	 * @return array{t:string,v:array<int,mixed>}
	 */
	private function read_array_in( string $buf, int &$pos ): array {
		$pos++;
		$items = array();
		$len   = strlen( $buf );

		while ( $pos < $len ) {
			$this->skip_ws_in( $buf, $pos );

			if ( ']' === ( $buf[ $pos ] ?? '' ) ) {
				$pos++;
				break;
			}

			$before  = $pos;
			$items[] = $this->read_value_from( $buf, $pos );

			if ( $pos <= $before ) {
				$pos++;
			}
		}

		return array(
			't' => 'array',
			'v' => $items,
		);
	}

	/**
	 * Read a number, or a reference ("N G R").
	 *
	 * @param string $buf Buffer.
	 * @param int    $pos Cursor (by reference).
	 * @return array<string,mixed>
	 */
	private function read_number_or_ref_in( string $buf, int &$pos ) {
		$num = $this->read_number_token_in( $buf, $pos );

		// Lookahead for "<int> R".
		if ( ctype_digit( ltrim( $num, '+-' ) ) && false === strpos( $num, '.' ) ) {
			$save = $pos;
			$this->skip_ws_in( $buf, $pos );

			if ( $pos < strlen( $buf ) && ctype_digit( $buf[ $pos ] ) ) {
				$gen = $this->read_number_token_in( $buf, $pos );
				$this->skip_ws_in( $buf, $pos );

				if ( 'R' === ( $buf[ $pos ] ?? '' ) && ! $this->is_regular( $buf[ $pos + 1 ] ?? ' ' ) ) {
					$pos++;

					return array(
						't'   => 'ref',
						'num' => (int) $num,
						'gen' => (int) $gen,
					);
				}
			}

			$pos = $save;
		}

		return array(
			't' => 'num',
			'v' => $num,
		);
	}

	/* ---------------------------------------------------------------------
	 * Buffer-relative helpers (used for object streams).
	 * ------------------------------------------------------------------- */

	/**
	 * Read a value from a decoded buffer at a cursor.
	 *
	 * @param string $buf Buffer.
	 * @param int    $pos Cursor (by reference).
	 * @return mixed
	 */
	private function read_value_in( string $buf, int &$pos ) {
		return $this->read_value_from( $buf, $pos );
	}

	/**
	 * Read a number token.
	 *
	 * @param string $buf Buffer.
	 * @param int    $pos Cursor (by reference).
	 * @return string
	 */
	private function read_number_token_in( string $buf, int &$pos ): string {
		$start = $pos;
		$len   = strlen( $buf );

		while ( $pos < $len ) {
			$ch = $buf[ $pos ];

			if ( ctype_digit( $ch ) || '+' === $ch || '-' === $ch || '.' === $ch || 'e' === $ch || 'E' === $ch ) {
				$pos++;
				continue;
			}

			break;
		}

		return substr( $buf, $start, $pos - $start );
	}

	/**
	 * Read an integer token from the main document.
	 *
	 * @param int $pos Cursor (by reference).
	 * @return string
	 */
	private function read_integer_token( int &$pos ): string {
		return $this->read_integer_token_in( $this->data, $pos );
	}

	/**
	 * Read an integer token from a buffer.
	 *
	 * @param string $buf Buffer.
	 * @param int    $pos Cursor (by reference).
	 * @return string
	 */
	private function read_integer_token_in( string $buf, int &$pos ): string {
		$start = $pos;
		$len   = strlen( $buf );

		if ( $pos < $len && ( '+' === $buf[ $pos ] || '-' === $buf[ $pos ] ) ) {
			$pos++;
		}

		while ( $pos < $len && ctype_digit( $buf[ $pos ] ) ) {
			$pos++;
		}

		return substr( $buf, $start, $pos - $start );
	}

	/**
	 * Skip whitespace and comments in the main document.
	 *
	 * @param int $pos Cursor (by reference).
	 * @return void
	 */
	private function skip_ws( int &$pos ): void {
		$this->skip_ws_in( $this->data, $pos );
	}

	/**
	 * Skip whitespace and comments in a buffer.
	 *
	 * @param string $buf Buffer.
	 * @param int    $pos Cursor (by reference).
	 * @return void
	 */
	private function skip_ws_in( string $buf, int &$pos ): void {
		$len = strlen( $buf );

		while ( $pos < $len ) {
			$ch = $buf[ $pos ];

			if ( $this->is_ws( $ch ) ) {
				$pos++;
				continue;
			}

			if ( '%' === $ch ) {
				while ( $pos < $len && "\n" !== $buf[ $pos ] && "\r" !== $buf[ $pos ] ) {
					$pos++;
				}

				continue;
			}

			break;
		}
	}

	/**
	 * Brute-force rebuild of the xref by scanning for "N G obj".
	 *
	 * @return void
	 */
	private function rebuild_xref_by_scan(): void {
		if ( preg_match_all( '/(\d+)\s+(\d+)\s+obj\b/', $this->data, $matches, PREG_OFFSET_CAPTURE ) ) {
			foreach ( $matches[1] as $i => $m ) {
				$num                = (int) $m[0];
				$this->xref[ $num ] = (int) $matches[0][ $i ][1];
			}
		}

		if ( empty( $this->trailer ) && preg_match( '/trailer\b/', $this->data, $tm, PREG_OFFSET_CAPTURE ) ) {
			$pos = $tm[0][1] + strlen( 'trailer' );
			$this->skip_ws( $pos );
			$trailer = $this->read_value( $pos );

			if ( $this->is_dict( $trailer ) ) {
				$this->merge_trailer( $trailer['v'] );
			}
		}

		if ( empty( $this->trailer['Root'] ) ) {
			// Find a Catalog object directly.
			foreach ( array_keys( $this->xref ) as $num ) {
				$obj = $this->get_object( $num );

				if ( $this->is_dict( $obj ) && 'Catalog' === $this->name_value( $obj['v']['Type'] ?? null ) ) {
					$this->trailer['Root'] = array(
						't'   => 'ref',
						'num' => $num,
						'gen' => 0,
					);
					break;
				}
			}
		}
	}

	/* ---------------------------------------------------------------------
	 * Small helpers.
	 * ------------------------------------------------------------------- */

	/**
	 * Merge trailer keys without overwriting existing values.
	 *
	 * @param array<string, mixed> $dict Trailer dictionary.
	 * @return void
	 */
	private function merge_trailer( array $dict ): void {
		foreach ( $dict as $key => $value ) {
			if ( ! array_key_exists( $key, $this->trailer ) ) {
				$this->trailer[ $key ] = $value;
			}
		}
	}

	/**
	 * Whether a value is a dictionary node.
	 *
	 * @param mixed $value Value.
	 * @return bool
	 */
	private function is_dict( $value ): bool {
		return is_array( $value ) && 'dict' === ( $value['t'] ?? '' );
	}

	/**
	 * Get a name value's string, resolving refs.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	private function name_value( $value ): string {
		$value = $this->resolve( $value );

		if ( is_array( $value ) && 'name' === ( $value['t'] ?? '' ) ) {
			return (string) $value['v'];
		}

		return '';
	}

	/**
	 * Extract a numeric scalar from a value.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	private function scalar_num( $value ): string {
		$value = $this->resolve( $value );

		if ( is_array( $value ) && 'num' === ( $value['t'] ?? '' ) ) {
			return (string) $value['v'];
		}

		return '0';
	}

	/**
	 * Resolve a value to an integer when possible.
	 *
	 * @param mixed $value Value.
	 * @return int|null
	 */
	private function resolve_int( $value ): ?int {
		$value = $this->resolve( $value );

		if ( is_array( $value ) && 'num' === ( $value['t'] ?? '' ) ) {
			return (int) $value['v'];
		}

		return null;
	}

	/**
	 * Read a big-endian integer of N bytes from a buffer.
	 *
	 * @param string $buf   Buffer.
	 * @param int    $start Start offset.
	 * @param int    $n     Number of bytes.
	 * @return int
	 */
	private function read_bytes_int( string $buf, int $start, int $n ): int {
		$value = 0;

		for ( $i = 0; $i < $n; $i++ ) {
			$value = ( $value << 8 ) | ord( $buf[ $start + $i ] );
		}

		return $value;
	}

	/**
	 * Whether a character is PDF whitespace.
	 *
	 * @param string $ch Character.
	 * @return bool
	 */
	private function is_ws( string $ch ): bool {
		return "\x00" === $ch || "\t" === $ch || "\n" === $ch || "\x0C" === $ch || "\r" === $ch || ' ' === $ch;
	}

	/**
	 * Whether a character is a PDF delimiter.
	 *
	 * @param string $ch Character.
	 * @return bool
	 */
	private function is_delimiter( string $ch ): bool {
		return in_array( $ch, array( '(', ')', '<', '>', '[', ']', '{', '}', '/', '%' ), true );
	}

	/**
	 * Whether a character is a regular PDF character (not ws/delimiter).
	 *
	 * @param string $ch Character.
	 * @return bool
	 */
	private function is_regular( string $ch ): bool {
		return '' !== $ch && ! $this->is_ws( $ch ) && ! $this->is_delimiter( $ch );
	}
}
