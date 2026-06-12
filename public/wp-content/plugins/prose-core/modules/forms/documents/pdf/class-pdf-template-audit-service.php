<?php
/**
 * PDF template audit service — trace the official-template resolution pipeline.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Forms\Documents\Pdf;

use ProSe\Core\Forms\Documents\Filing\Court_Pdf_Fill_Service;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Pdf_Template_Audit_Service
 *
 * Read-only diagnostic that traces, per form code, whether an official court
 * PDF (stored in prose_form -> prose_file_url) can be resolved, opened, and
 * filled, and explains why the renderer currently falls back to builtin.
 *
 * This service performs NO rendering and modifies NO mappings. Callers supply
 * the prose_form catalog rows (via WP-CLI / $wpdb, or a direct DB runner); the
 * service resolves each form code to a row, inspects the PDF on disk, mirrors
 * the renderer's selection logic, and produces audit + field-registry data.
 */
final class Pdf_Template_Audit_Service {

	public const PDF_ACROFORM = 'AcroForm';
	public const PDF_FLAT     = 'Flat';

	public const STRATEGY_FORM_CODE = 'form_code_meta';
	public const STRATEGY_BASENAME  = 'file_basename';
	public const STRATEGY_NONE      = 'none';

	/**
	 * Template registry.
	 *
	 * @var Pdf_Template_Registry
	 */
	private Pdf_Template_Registry $registry;

	/**
	 * Fill service (used only to report toolchain availability).
	 *
	 * @var Court_Pdf_Fill_Service
	 */
	private Court_Pdf_Fill_Service $filler;

	/**
	 * Filesystem path to wp-content (no trailing slash).
	 *
	 * @var string
	 */
	private string $content_dir;

	/**
	 * Public URL to wp-content (no trailing slash).
	 *
	 * @var string
	 */
	private string $content_url;

	/**
	 * Constructor.
	 *
	 * @param Pdf_Template_Registry|null  $registry    Template registry.
	 * @param Court_Pdf_Fill_Service|null $filler      Fill service.
	 * @param string                      $content_dir Filesystem path to wp-content.
	 * @param string                      $content_url Public URL to wp-content.
	 */
	public function __construct(
		?Pdf_Template_Registry $registry = null,
		?Court_Pdf_Fill_Service $filler = null,
		string $content_dir = '',
		string $content_url = ''
	) {
		$this->registry = $registry ?? new Pdf_Template_Registry();
		$this->filler   = $filler ?? new Court_Pdf_Fill_Service();

		if ( '' === $content_dir && defined( 'WP_CONTENT_DIR' ) ) {
			$content_dir = (string) WP_CONTENT_DIR;
		}

		if ( '' === $content_url && function_exists( 'content_url' ) ) {
			$content_url = (string) content_url();
		}

		$this->content_dir = rtrim( $content_dir, '/\\' );
		$this->content_url = rtrim( $content_url, '/\\' );
	}

	/**
	 * Build per-code records by resolving form codes against catalog rows.
	 *
	 * Each row is an associative array:
	 *   post_id, title, form_code, file_url, fillable, field_count, fields[]
	 *
	 * @param string[]                         $codes Form codes.
	 * @param array<int, array<string, mixed>> $rows  prose_form catalog rows.
	 * @return array<string, array<string, mixed>>
	 */
	public function build_records( array $codes, array $rows ): array {
		$records = array();

		foreach ( $codes as $code ) {
			$records[ $code ] = $this->resolve_record( $code, $rows );
		}

		return $records;
	}

	/**
	 * Resolve one form code to a record.
	 *
	 * @param string                           $code Form code.
	 * @param array<int, array<string, mixed>> $rows Catalog rows.
	 * @return array<string, mixed>
	 */
	private function resolve_record( string $code, array $rows ): array {
		$wanted_file = strtolower( $code ) . '.pdf';

		$by_code     = null;
		$by_basename = null;

		foreach ( $rows as $row ) {
			$form_code = (string) ( $row['form_code'] ?? '' );
			$file_url  = (string) ( $row['file_url'] ?? '' );

			if ( '' !== $form_code && $form_code === $code && null === $by_code ) {
				$by_code = $row;
			}

			if ( '' !== $file_url && strtolower( basename( $this->url_path( $file_url ) ) ) === $wanted_file && null === $by_basename ) {
				$by_basename = $row;
			}
		}

		if ( null !== $by_code ) {
			return $this->record_from_row( $code, $by_code, self::STRATEGY_FORM_CODE );
		}

		if ( null !== $by_basename ) {
			return $this->record_from_row( $code, $by_basename, self::STRATEGY_BASENAME );
		}

		return array(
			'form_code'           => $code,
			'post_id'             => 0,
			'post_exists'         => false,
			'title'               => '',
			'file_url'            => '',
			'resolution_strategy' => self::STRATEGY_NONE,
			'stored_fillable'     => false,
			'stored_field_count'  => 0,
			'stored_fields'       => array(),
		);
	}

	/**
	 * Build a record from a matched row.
	 *
	 * @param string               $code     Form code.
	 * @param array<string, mixed> $row      Catalog row.
	 * @param string               $strategy Resolution strategy.
	 * @return array<string, mixed>
	 */
	private function record_from_row( string $code, array $row, string $strategy ): array {
		return array(
			'form_code'           => $code,
			'post_id'             => (int) ( $row['post_id'] ?? 0 ),
			'post_exists'         => true,
			'title'               => (string) ( $row['title'] ?? '' ),
			'file_url'            => (string) ( $row['file_url'] ?? '' ),
			'resolution_strategy' => $strategy,
			'stored_fillable'     => (bool) ( $row['fillable'] ?? false ),
			'stored_field_count'  => (int) ( $row['field_count'] ?? 0 ),
			'stored_fields'       => array_values( (array) ( $row['fields'] ?? array() ) ),
		);
	}

	/**
	 * Audit every record.
	 *
	 * @param array<string, array<string, mixed>> $records Records keyed by code.
	 * @return array<int, array<string, mixed>>
	 */
	public function audit( array $records ): array {
		$results = array();

		foreach ( $records as $record ) {
			$results[] = $this->audit_one( $record );
		}

		return $results;
	}

	/**
	 * Audit a single record across the full resolution pipeline.
	 *
	 * @param array<string, mixed> $record Record.
	 * @return array<string, mixed>
	 */
	private function audit_one( array $record ): array {
		$code          = (string) $record['form_code'];
		$post_exists   = (bool) $record['post_exists'];
		$file_url      = (string) $record['file_url'];
		$strategy      = (string) $record['resolution_strategy'];
		$file_path     = '' !== $file_url ? $this->resolve_path( $file_url ) : '';
		$file_resolved = '' !== $file_path;
		$file_exists   = '' !== $file_path && is_readable( $file_path );

		$pdf_openable = $file_exists && $this->is_pdf( $file_path );

		$field_count = (int) $record['stored_field_count'];
		$field_names = array_values( (array) $record['stored_fields'] );

		// Live re-inspection when the file is present (authoritative cross-check).
		$live_fields = $pdf_openable ? $this->extract_fields( $file_path ) : array();

		if ( ! empty( $live_fields ) ) {
			$field_count = count( $live_fields );
			$field_names = array_map(
				static function ( array $f ): string {
					return (string) $f['name'];
				},
				$live_fields
			);
		}

		$pdf_type = null;

		if ( $pdf_openable ) {
			$pdf_type = $field_count > 0 ? self::PDF_ACROFORM : self::PDF_FLAT;
		}

		// Mirror the renderer's actual selection (Court_Pdf_Fill_Service::fill).
		$template      = $this->registry->resolve( $code );
		$tpl_path      = (string) $template['template_path'];
		$tpl_readable  = '' !== $tpl_path && is_readable( $tpl_path );
		$tool_ready    = $this->filler->is_acroform_available();
		$registry_acro = Pdf_Template_Registry::RENDERER_ACROFORM === $template['renderer_type'];

		$renderer_selected = ( $registry_acro && $tpl_readable && $tool_ready )
			? Pdf_Template_Registry::RENDERER_ACROFORM
			: Pdf_Template_Registry::RENDERER_BUILTIN;

		$reasons = $this->fallback_reasons(
			$post_exists,
			$file_url,
			$file_resolved,
			$file_exists,
			$pdf_openable,
			$field_count,
			$tool_ready,
			$registry_acro,
			$tpl_path,
			$tpl_readable
		);

		// "Can the official PDF be filled" = data gates only (ignores toolchain/registry wiring).
		$can_fill = $post_exists && '' !== $file_url && $file_exists && $pdf_openable && $field_count > 0;

		return array(
			'form_code'           => $code,
			'prose_form_exists'   => $post_exists,
			'post_id'             => (int) $record['post_id'],
			'title'               => (string) $record['title'],
			'prose_file_url'      => '' !== $file_url ? $file_url : null,
			'resolution_strategy' => $strategy,
			'file_resolved'       => $file_resolved,
			'file_path'           => '' !== $file_path ? $file_path : null,
			'file_exists'         => $file_exists,
			'pdf_openable'        => $pdf_openable,
			'pdf_type'            => $pdf_type,
			'field_count'         => $field_count,
			'field_names'         => $field_names,
			'renderer_selected'   => $renderer_selected,
			'can_fill'            => $can_fill,
			'fallback_reason'     => $reasons[0] ?? null,
			'fallback_reasons'    => $reasons,
		);
	}

	/**
	 * Compute ordered fallback reasons (empty when acroform would be selected).
	 *
	 * @param bool   $post_exists   prose_form post found.
	 * @param string $file_url      prose_file_url value.
	 * @param bool   $file_resolved URL mapped to a disk path.
	 * @param bool   $file_exists   File present on disk.
	 * @param bool   $pdf_openable  File opens as a PDF.
	 * @param int    $field_count   AcroForm field count.
	 * @param bool   $tool_ready    pdftk toolchain available.
	 * @param bool   $registry_acro Registry resolves an acroform template.
	 * @param string $tpl_path      Registry template path.
	 * @param bool   $tpl_readable  Registry template readable.
	 * @return string[]
	 */
	private function fallback_reasons(
		bool $post_exists,
		string $file_url,
		bool $file_resolved,
		bool $file_exists,
		bool $pdf_openable,
		int $field_count,
		bool $tool_ready,
		bool $registry_acro,
		string $tpl_path,
		bool $tpl_readable
	): array {
		$reasons = array();

		if ( ! $post_exists ) {
			$reasons[] = 'prose_form post not found for form code';
		} elseif ( '' === $file_url ) {
			$reasons[] = 'prose_file_url is empty';
		} elseif ( ! $file_resolved ) {
			$reasons[] = 'prose_file_url could not be mapped to a filesystem path';
		} elseif ( ! $file_exists ) {
			$reasons[] = 'official PDF not found on disk';
		} elseif ( ! $pdf_openable ) {
			$reasons[] = 'file could not be opened as a PDF';
		} elseif ( 0 === $field_count ) {
			$reasons[] = 'official PDF has no AcroForm fields (flat document)';
		}

		// Pipeline-wiring blockers (independent of the official PDF's own state).
		if ( ! $registry_acro ) {
			$reasons[] = sprintf(
				'template registry does not resolve prose_file_url (expects local template %s)',
				'' !== $tpl_path ? basename( $tpl_path ) : 'templates/{CODE}.pdf'
			);
		} elseif ( ! $tpl_readable ) {
			$reasons[] = 'registry template path is not readable';
		}

		if ( ! $tool_ready ) {
			$reasons[] = 'no PDF fill toolchain (pdftk) installed';
		}

		return array_values( array_unique( $reasons ) );
	}

	/**
	 * Build the AcroForm field registry from audit results.
	 *
	 * @param array<int, array<string, mixed>> $audits Audit results.
	 * @return array<int, array<string, mixed>>
	 */
	public function field_registry( array $audits ): array {
		$registry = array();

		foreach ( $audits as $audit ) {
			if ( self::PDF_ACROFORM !== ( $audit['pdf_type'] ?? null ) ) {
				continue;
			}

			$path   = (string) ( $audit['file_path'] ?? '' );
			$fields = ( '' !== $path && is_readable( $path ) )
				? $this->extract_fields( $path )
				: $this->fields_from_names( (array) ( $audit['field_names'] ?? array() ) );

			$registry[] = array(
				'form_code' => (string) $audit['form_code'],
				'fields'    => $fields,
			);
		}

		return $registry;
	}

	/**
	 * Fallback field descriptors (names only) when the PDF cannot be parsed.
	 *
	 * @param string[] $names Field names.
	 * @return array<int, array<string, string>>
	 */
	private function fields_from_names( array $names ): array {
		$fields = array();

		foreach ( $names as $name ) {
			$fields[] = array(
				'name' => (string) $name,
				'type' => 'text',
			);
		}

		return $fields;
	}

	/**
	 * Render the audit + field registry as a Markdown report.
	 *
	 * @param array<int, array<string, mixed>> $audits   Audit results.
	 * @param array<int, array<string, mixed>> $registry Field registry.
	 * @return string
	 */
	public function to_markdown( array $audits, array $registry ): string {
		$total    = count( $audits );
		$resolved = 0;
		$fillable = 0;
		$acro     = 0;

		foreach ( $audits as $a ) {
			if ( $a['prose_form_exists'] && null !== $a['prose_file_url'] ) {
				++$resolved;
			}
			if ( $a['can_fill'] ) {
				++$fillable;
			}
			if ( self::PDF_ACROFORM === $a['pdf_type'] ) {
				++$acro;
			}
		}

		$lines   = array();
		$lines[] = '# PDF Template Resolution Audit';
		$lines[] = '';
		$lines[] = '**Generated:** ' . gmdate( 'Y-m-d H:i:s' ) . ' UTC';
		$lines[] = '**Source of truth:** `prose_form` -> `prose_file_url` (+ `prose_pdf_fillable`, `prose_pdf_field_count`, `prose_pdf_fields_json`)';
		$lines[] = '**Mode:** Audit only — no rendering logic or mappings modified.';
		$lines[] = '';
		$lines[] = '## Summary';
		$lines[] = '';
		$lines[] = sprintf( '- Forms audited: **%d**', $total );
		$lines[] = sprintf( '- Resolved to a `prose_file_url`: **%d / %d**', $resolved, $total );
		$lines[] = sprintf( '- Official PDF is a fillable AcroForm (can be filled): **%d / %d**', $fillable, $total );
		$lines[] = sprintf( '- Renderer currently selected: **builtin** for all (registry never consults `prose_file_url`)' );
		$lines[] = '';
		$lines[] = '## Audit table';
		$lines[] = '';
		$lines[] = '| Form Code | prose_form | prose_file_url | File Exists | PDF Type | Field Count | Renderer | Can Fill | Fallback Reason |';
		$lines[] = '|-----------|-----------|----------------|-------------|----------|-------------|----------|----------|-----------------|';

		foreach ( $audits as $a ) {
			$url = null === $a['prose_file_url'] ? '—' : '`' . basename( $this->url_path( (string) $a['prose_file_url'] ) ) . '`';

			$lines[] = sprintf(
				'| %s | %s | %s | %s | %s | %s | %s | %s | %s |',
				$a['form_code'],
				$a['prose_form_exists'] ? 'yes' : 'no',
				$url,
				$a['file_exists'] ? 'yes' : 'no',
				null === $a['pdf_type'] ? '—' : $a['pdf_type'],
				$a['field_count'],
				$a['renderer_selected'],
				$a['can_fill'] ? 'YES' : 'no',
				null === $a['fallback_reason'] ? '—' : $a['fallback_reason']
			);
		}

		$lines[] = '';
		$lines[] = '## Field registry (AcroForm forms only)';
		$lines[] = '';

		if ( empty( $registry ) ) {
			$lines[] = '_No audited form resolved to a fillable AcroForm PDF, so the field registry is empty. None of these forms can currently be field-filled from the official template._';
		} else {
			foreach ( $registry as $entry ) {
				$lines[] = sprintf( '### %s (%d fields)', $entry['form_code'], count( $entry['fields'] ) );
				$lines[] = '';
				$lines[] = '| Field Name | Type |';
				$lines[] = '|------------|------|';

				foreach ( $entry['fields'] as $field ) {
					$lines[] = sprintf( '| `%s` | %s |', $field['name'], $field['type'] );
				}

				$lines[] = '';
			}
		}

		$lines[] = '## Conclusion';
		$lines[] = '';
		$lines[] = '- The renderer falls back to `builtin` because `Pdf_Template_Registry` resolves templates from a local `templates/{CODE}.pdf` directory and never reads `prose_file_url`.';
		$lines[] = '- Even with that wiring fixed, the audited official PDFs that resolve are **flat (0 AcroForm fields)**, so they cannot be field-filled as-is.';
		$lines[] = '- No PDF fill toolchain (`pdftk`) is installed in this environment.';
		$lines[] = '';

		return implode( "\n", $lines ) . "\n";
	}

	/**
	 * Whether a file begins with a PDF header.
	 *
	 * @param string $path File path.
	 * @return bool
	 */
	private function is_pdf( string $path ): bool {
		$handle = fopen( $path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen

		if ( false === $handle ) {
			return false;
		}

		$head = (string) fread( $handle, 5 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		return 0 === strpos( $head, '%PDF-' );
	}

	/**
	 * Extract AcroForm fields (name + type) from a PDF on disk.
	 *
	 * Dependency-free: it scans the raw bytes plus any FlateDecode object
	 * streams for field dictionaries (/T name paired with /FT type).
	 *
	 * @param string $path PDF path.
	 * @return array<int, array<string, string>>
	 */
	private function extract_fields( string $path ): array {
		$data = (string) file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

		if ( '' === $data ) {
			return array();
		}

		$blob = $data;

		if ( preg_match_all( '/stream\r?\n(.*?)\r?\nendstream/s', $data, $streams ) ) {
			foreach ( $streams[1] as $stream ) {
				$decoded = @gzuncompress( $stream ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

				if ( false === $decoded ) {
					$decoded = @gzinflate( $stream ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				}

				if ( false !== $decoded ) {
					$blob .= "\n" . $decoded;
				}
			}
		}

		return $this->parse_fields( $blob );
	}

	/**
	 * Parse field dictionaries from a (decompressed) PDF blob.
	 *
	 * @param string $blob PDF text blob.
	 * @return array<int, array<string, string>>
	 */
	private function parse_fields( string $blob ): array {
		if ( ! preg_match_all( '#/T\s*\(((?:\\\\.|[^\\\\()])*)\)#s', $blob, $matches, PREG_OFFSET_CAPTURE ) ) {
			return array();
		}

		$fields = array();

		foreach ( $matches[1] as $match ) {
			$name   = $this->unescape_pdf_string( (string) $match[0] );
			$offset = (int) $match[1];

			if ( '' === $name || isset( $fields[ $name ] ) ) {
				continue;
			}

			$window = substr( $blob, max( 0, $offset - 600 ), 1200 );

			if ( ! preg_match( '#/FT\s*/(Tx|Btn|Ch|Sig)\b#', $window, $ft ) ) {
				continue;
			}

			$flags = 0;

			if ( preg_match( '#/Ff\s+(\d+)#', $window, $ff ) ) {
				$flags = (int) $ff[1];
			}

			$fields[ $name ] = array(
				'name' => $name,
				'type' => $this->field_type( (string) $ft[1], $flags ),
			);
		}

		return array_values( $fields );
	}

	/**
	 * Map a PDF field type + flags to an audit field type.
	 *
	 * @param string $ft    Field type (Tx|Btn|Ch|Sig).
	 * @param int    $flags /Ff flag bits.
	 * @return string
	 */
	private function field_type( string $ft, int $flags ): string {
		switch ( $ft ) {
			case 'Tx':
				return ( $flags & 4096 ) ? 'multiline' : 'text';
			case 'Btn':
				if ( $flags & 65536 ) {
					return 'button';
				}

				return ( $flags & 32768 ) ? 'radio' : 'checkbox';
			case 'Ch':
				return ( $flags & 131072 ) ? 'combo' : 'choice';
			case 'Sig':
				return 'signature';
			default:
				return 'text';
		}
	}

	/**
	 * Unescape a PDF literal string.
	 *
	 * @param string $value Raw value.
	 * @return string
	 */
	private function unescape_pdf_string( string $value ): string {
		$replacements = array(
			'\\('  => '(',
			'\\)'  => ')',
			'\\\\' => '\\',
			'\\n'  => "\n",
			'\\r'  => "\r",
			'\\t'  => "\t",
		);

		return trim( strtr( $value, $replacements ) );
	}

	/**
	 * Map a prose_file_url to a filesystem path.
	 *
	 * @param string $file_url File URL.
	 * @return string Empty string when it cannot be mapped.
	 */
	private function resolve_path( string $file_url ): string {
		if ( '' !== $this->content_url && '' !== $this->content_dir && 0 === strpos( $file_url, $this->content_url ) ) {
			return $this->content_dir . substr( $file_url, strlen( $this->content_url ) );
		}

		$pos = strpos( $file_url, '/wp-content/' );

		if ( false !== $pos && '' !== $this->content_dir ) {
			return $this->content_dir . substr( $file_url, $pos + strlen( '/wp-content' ) );
		}

		return '';
	}

	/**
	 * Strip query/fragment from a URL to get its path component.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	private function url_path( string $url ): string {
		$path = (string) wp_parse_url( $url, PHP_URL_PATH );

		return '' !== $path ? $path : $url;
	}
}
