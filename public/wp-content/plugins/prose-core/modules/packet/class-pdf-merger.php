<?php
/**
 * Pure-PHP PDF merger — concatenates blank source court-form PDFs into one
 * packet PDF without any system tooling (no pdftk/ghostscript) or third-party
 * libraries.
 *
 * Pages are imported by deep-copying each page's object graph into a fresh
 * document with a classic cross-reference table. Content streams are preserved
 * verbatim; the merger never fills or modifies form fields.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Packet;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Pdf_Merger
 */
final class Pdf_Merger {

	/**
	 * Output objects: object number => serialized body string.
	 *
	 * @var array<int, string>
	 */
	private array $objects = array();

	/**
	 * Next available output object number.
	 *
	 * @var int
	 */
	private int $next_num = 3;

	/**
	 * Imported page object numbers (output numbering).
	 *
	 * @var array<int, int>
	 */
	private array $page_nums = array();

	/**
	 * Merge a list of PDF file paths into a single packet PDF.
	 *
	 * @param array<int, string> $paths Absolute PDF file paths in order.
	 * @return string|null Merged PDF bytes, or null on failure.
	 */
	public function merge( array $paths ): ?string {
		$this->objects   = array();
		$this->next_num  = 3;
		$this->page_nums = array();

		try {
			foreach ( $paths as $path ) {
				if ( ! is_string( $path ) || '' === $path || ! is_readable( $path ) ) {
					return null;
				}

				$data = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

				if ( false === $data || '' === $data ) {
					return null;
				}

				$this->import_document( $data );
			}

			if ( empty( $this->page_nums ) ) {
				return null;
			}

			return $this->assemble();
		} catch ( \Throwable $e ) {
			return null;
		}
	}

	/**
	 * Import all pages from one source document.
	 *
	 * @param string $data Raw PDF bytes.
	 * @return void
	 */
	private function import_document( string $data ): void {
		$parser = new Pdf_Parser( $data );
		$pages  = $parser->get_page_numbers();

		foreach ( $pages as $src_page_num ) {
			$map               = array();
			$new_page_num      = $this->import_page( $parser, $src_page_num, $map );
			$this->page_nums[] = $new_page_num;
		}
	}

	/**
	 * Import a single page and its object graph.
	 *
	 * @param Pdf_Parser       $parser       Source parser.
	 * @param int              $src_page_num Source page object number.
	 * @param array<int, int>  $map          Source->output number map (by reference).
	 * @return int Output page object number.
	 */
	private function import_page( Pdf_Parser $parser, int $src_page_num, array &$map ): int {
		$page = $parser->get_object( $src_page_num );

		if ( ! is_array( $page ) || 'dict' !== ( $page['t'] ?? '' ) ) {
			$dict = array();
		} else {
			$dict = $page['v'];
		}

		// Bake inherited attributes onto the page, then drop Parent.
		$inherited = $parser->inherited_attributes( $src_page_num );

		foreach ( $inherited as $attr => $value ) {
			if ( ! array_key_exists( $attr, $dict ) ) {
				$dict[ $attr ] = $value;
			}
		}

		unset( $dict['Parent'] );

		$page_num               = $this->next_num++;
		$map[ $src_page_num ]   = $page_num;

		$imported = array();

		foreach ( $dict as $key => $value ) {
			$imported[ $key ] = $this->import_value( $parser, $value, $map );
		}

		$imported['Type']   = array(
			't' => 'name',
			'v' => 'Page',
		);
		$imported['Parent'] = array(
			't'   => 'ref',
			'num' => 2,
			'gen' => 0,
		);

		$this->objects[ $page_num ] = $this->serialize_dict( $imported );

		return $page_num;
	}

	/**
	 * Import an indirect object by source number (deduped via map).
	 *
	 * @param Pdf_Parser      $parser  Source parser.
	 * @param int             $src_num Source object number.
	 * @param array<int, int> $map     Source->output number map (by reference).
	 * @return int Output object number.
	 */
	private function import_object( Pdf_Parser $parser, int $src_num, array &$map ): int {
		if ( isset( $map[ $src_num ] ) ) {
			return $map[ $src_num ];
		}

		$new_num          = $this->next_num++;
		$map[ $src_num ]  = $new_num;

		$value = $parser->get_object( $src_num );

		if ( is_array( $value ) && 'stream' === ( $value['t'] ?? '' ) ) {
			$dict = array();

			foreach ( $value['dict'] as $key => $val ) {
				$dict[ $key ] = $this->import_value( $parser, $val, $map );
			}

			$this->objects[ $new_num ] = $this->serialize_stream( $dict, $value['raw'] );

			return $new_num;
		}

		$imported                  = $this->import_value( $parser, $value, $map );
		$this->objects[ $new_num ] = $this->serialize_value( $imported );

		return $new_num;
	}

	/**
	 * Recursively import a value, rewriting references to output numbers.
	 *
	 * @param Pdf_Parser      $parser Source parser.
	 * @param mixed           $value  Value.
	 * @param array<int, int> $map    Source->output number map (by reference).
	 * @return mixed
	 */
	private function import_value( Pdf_Parser $parser, $value, array &$map ) {
		if ( ! is_array( $value ) ) {
			return $value;
		}

		$type = $value['t'] ?? '';

		if ( 'ref' === $type ) {
			$new_num = $this->import_object( $parser, (int) $value['num'], $map );

			return array(
				't'   => 'ref',
				'num' => $new_num,
				'gen' => 0,
			);
		}

		if ( 'array' === $type ) {
			$items = array();

			foreach ( $value['v'] as $item ) {
				$items[] = $this->import_value( $parser, $item, $map );
			}

			return array(
				't' => 'array',
				'v' => $items,
			);
		}

		if ( 'dict' === $type ) {
			$dict = array();

			foreach ( $value['v'] as $key => $item ) {
				$dict[ $key ] = $this->import_value( $parser, $item, $map );
			}

			return array(
				't' => 'dict',
				'v' => $dict,
			);
		}

		if ( 'stream' === $type ) {
			$dict = array();

			foreach ( $value['dict'] as $key => $item ) {
				$dict[ $key ] = $this->import_value( $parser, $item, $map );
			}

			return array(
				't'    => 'stream',
				'dict' => $dict,
				'raw'  => $value['raw'],
			);
		}

		return $value;
	}

	/**
	 * Assemble the final PDF byte string.
	 *
	 * @return string
	 */
	private function assemble(): string {
		// Object 1: Catalog. Object 2: Pages.
		$kids = array();

		foreach ( $this->page_nums as $num ) {
			$kids[] = array(
				't'   => 'ref',
				'num' => $num,
				'gen' => 0,
			);
		}

		$catalog = array(
			'Type'  => array(
				't' => 'name',
				'v' => 'Catalog',
			),
			'Pages' => array(
				't'   => 'ref',
				'num' => 2,
				'gen' => 0,
			),
		);

		$pages = array(
			'Type'  => array(
				't' => 'name',
				'v' => 'Pages',
			),
			'Kids'  => array(
				't' => 'array',
				'v' => $kids,
			),
			'Count' => array(
				't' => 'num',
				'v' => (string) count( $this->page_nums ),
			),
		);

		$this->objects[1] = $this->serialize_dict( $catalog );
		$this->objects[2] = $this->serialize_dict( $pages );

		ksort( $this->objects );

		$max_num = max( array_keys( $this->objects ) );
		$out     = "%PDF-1.7\n%\xE2\xE3\xCF\xD3\n";
		$offsets = array();

		for ( $num = 1; $num <= $max_num; $num++ ) {
			if ( ! isset( $this->objects[ $num ] ) ) {
				continue;
			}

			$offsets[ $num ] = strlen( $out );
			$out            .= $num . " 0 obj\n" . $this->objects[ $num ] . "\nendobj\n";
		}

		$xref_offset = strlen( $out );
		$size        = $max_num + 1;

		$out .= "xref\n0 " . $size . "\n";
		$out .= "0000000000 65535 f \n";

		for ( $num = 1; $num <= $max_num; $num++ ) {
			if ( isset( $offsets[ $num ] ) ) {
				$out .= sprintf( "%010d 00000 n \n", $offsets[ $num ] );
			} else {
				$out .= "0000000000 65535 f \n";
			}
		}

		$out .= "trailer\n";
		$out .= '<< /Size ' . $size . ' /Root 1 0 R >>' . "\n";
		$out .= "startxref\n" . $xref_offset . "\n%%EOF\n";

		return $out;
	}

	/* ---------------------------------------------------------------------
	 * Serialization.
	 * ------------------------------------------------------------------- */

	/**
	 * Serialize a value to PDF syntax.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	private function serialize_value( $value ): string {
		if ( ! is_array( $value ) ) {
			return 'null';
		}

		$type = $value['t'] ?? '';

		switch ( $type ) {
			case 'null':
				return 'null';
			case 'bool':
				return $value['v'] ? 'true' : 'false';
			case 'num':
				return (string) $value['v'];
			case 'name':
				return '/' . $this->encode_name( (string) $value['v'] );
			case 'str':
				return '<' . strtoupper( bin2hex( (string) $value['v'] ) ) . '>';
			case 'ref':
				return $value['num'] . ' ' . ( $value['gen'] ?? 0 ) . ' R';
			case 'array':
				$parts = array();

				foreach ( $value['v'] as $item ) {
					$parts[] = $this->serialize_value( $item );
				}

				return '[' . implode( ' ', $parts ) . ']';
			case 'dict':
				return $this->serialize_dict( $value['v'] );
			case 'stream':
				return $this->serialize_stream( $value['dict'], $value['raw'] );
		}

		return 'null';
	}

	/**
	 * Serialize a dictionary.
	 *
	 * @param array<string, mixed> $dict Dictionary.
	 * @return string
	 */
	private function serialize_dict( array $dict ): string {
		$out = '<<';

		foreach ( $dict as $key => $value ) {
			$out .= ' /' . $this->encode_name( (string) $key ) . ' ' . $this->serialize_value( $value );
		}

		$out .= ' >>';

		return $out;
	}

	/**
	 * Serialize a stream object body.
	 *
	 * @param array<string, mixed> $dict Stream dictionary.
	 * @param string               $raw  Raw stream bytes.
	 * @return string
	 */
	private function serialize_stream( array $dict, string $raw ): string {
		$dict['Length'] = array(
			't' => 'num',
			'v' => (string) strlen( $raw ),
		);

		return $this->serialize_dict( $dict ) . "\nstream\n" . $raw . "\nendstream";
	}

	/**
	 * Encode a name token's special characters.
	 *
	 * @param string $name Name.
	 * @return string
	 */
	private function encode_name( string $name ): string {
		$out = '';
		$len = strlen( $name );

		for ( $i = 0; $i < $len; $i++ ) {
			$ch  = $name[ $i ];
			$ord = ord( $ch );

			if ( $ord < 0x21 || $ord > 0x7E || in_array( $ch, array( '(', ')', '<', '>', '[', ']', '{', '}', '/', '%', '#' ), true ) ) {
				$out .= '#' . strtoupper( str_pad( dechex( $ord ), 2, '0', STR_PAD_LEFT ) );
				continue;
			}

			$out .= $ch;
		}

		return $out;
	}
}
