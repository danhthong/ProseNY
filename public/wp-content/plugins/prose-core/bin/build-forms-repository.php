#!/usr/bin/env php
<?php
/**
 * Build the Forms Repository from CSV (standalone, no WordPress required).
 *
 * Usage: php bin/build-forms-repository.php [--dry-run] [--csv=path]
 *
 * @package ProSeCore
 */

$plugin_root = dirname( __DIR__ );
$forms_base  = $plugin_root . '/docs/forms';
$workflow_base = $plugin_root . '/docs/workflows';

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', $plugin_root . '/' );
}

if ( ! defined( 'PROSE_CORE_PATH' ) ) {
	define( 'PROSE_CORE_PATH', $plugin_root . '/' );
}

require_once PROSE_CORE_PATH . 'modules/forms/class-form-source-selector.php';
require_once PROSE_CORE_PATH . 'modules/forms/class-form-repository-seeder.php';

$dry_run = in_array( '--dry-run', $argv, true );
$csv_path = '';

foreach ( array_slice( $argv, 1 ) as $arg ) {
	if ( str_starts_with( $arg, '--csv=' ) ) {
		$csv_path = substr( $arg, 6 );
	}
}

if ( '' === $csv_path ) {
	$candidates = array(
		PROSE_CORE_PATH . '../../../collect_forms/crawl4ai_forms/forms_enriched.csv',
		PROSE_CORE_PATH . '../../../../collect_forms/crawl4ai_forms/forms_enriched.csv',
	);

	foreach ( $candidates as $candidate ) {
		$resolved = realpath( $candidate );

		if ( false !== $resolved && is_readable( $resolved ) ) {
			$csv_path = $resolved;
			break;
		}
	}
}

if ( '' === $csv_path || ! is_readable( $csv_path ) ) {
	fwrite( STDERR, "CSV not readable.\n" );
	exit( 1 );
}

$nyc_counties = array( 'New York', 'Kings', 'Queens', 'Bronx', 'Richmond' );
$rows         = parse_csv( $csv_path );
$records      = array();
$skipped      = 0;

foreach ( $rows as $row ) {
	$form_code = resolve_form_code( $row );

	if ( '' === $form_code ) {
		++$skipped;
		continue;
	}

	if ( ! isset( $records[ $form_code ] ) ) {
		$records[ $form_code ] = build_base_record( $row, $form_code, $nyc_counties );
	} else {
		$records[ $form_code ] = merge_row_into_record( $records[ $form_code ], $row );
	}
}

$records = \ProSe\Core\Forms\Form_Repository_Seeder::ensure_workflow_stubs( $records );

$workflow_index = build_workflow_references_index( $workflow_base );
$written        = 0;
$failed         = 0;

foreach ( $records as $form_code => $record ) {
	$record['workflow_references'] = $workflow_index[ $form_code ] ?? array();

	$existing_path = record_path( $forms_base, (string) $record['court'], $form_code );
	$record        = preserve_field_mapping_status( $record, $existing_path );

	$computed = \ProSe\Core\Forms\Form_Source_Selector::compute( (array) ( $record['source_files'] ?? array() ) );

	$record['preferred_source']  = $computed['preferred_source'];
	$record['editable_source']   = $computed['editable_source'];
	$record['fillable_strategy'] = $computed['fillable_strategy'];
	$record['generation_ready']  = $computed['generation_ready'];
	$record['import_status']     = resolve_import_status( $record );
	$record['docx_available']          = slot_available( $record, 'docx' ) || slot_available( $record, 'converted_docx' );
	$record['fillable_pdf_available']  = slot_available( $record, 'fillable_pdf' );
	$record['wpd_available']           = slot_available( $record, 'wpd' );

	if ( $dry_run ) {
		echo "[dry-run] Would write $form_code ($existing_path)\n";
		++$written;
		continue;
	}

	$dir = dirname( $existing_path );

	if ( ! is_dir( $dir ) && ! mkdir( $dir, 0755, true ) && ! is_dir( $dir ) ) {
		++$failed;
		fwrite( STDERR, "Failed to create directory for $form_code\n" );
		continue;
	}

	$json = json_encode( $record, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );

	if ( false === $json || false === file_put_contents( $existing_path, $json . "\n" ) ) {
		++$failed;
		fwrite( STDERR, "Failed to write $form_code\n" );
		continue;
	}

	++$written;
}

if ( $dry_run ) {
	echo "Dry run complete: $written records would be written, $skipped skipped, $failed failed.\n";
	exit( 0 );
}

echo "Repository build complete: $written records written, $skipped skipped, $failed failed.\n";
exit( 0 );

/**
 * Parse CSV rows.
 *
 * @param string $csv_path CSV path.
 * @return array<int, array<string, string>>
 */
function parse_csv( string $csv_path ): array {
	$handle = fopen( $csv_path, 'r' );

	if ( false === $handle ) {
		return array();
	}

	$header = fgetcsv( $handle, 0, ',', '"', '\\' );

	if ( ! is_array( $header ) ) {
		fclose( $handle );
		return array();
	}

	$column_map = build_column_map( $header );
	$rows       = array();

	while ( ( $data = fgetcsv( $handle, 0, ',', '"', '\\' ) ) !== false ) {
		if ( ! is_array( $data ) ) {
			continue;
		}

		$rows[] = array(
			'form_number'       => get_column_value( $data, $column_map, 'form_number' ),
			'form_title'        => get_column_value( $data, $column_map, 'form_title' ),
			'form_url'          => get_column_value( $data, $column_map, 'form_url' ),
			'case_type'         => get_column_value( $data, $column_map, 'case_type' ),
			'pdf_filenames'     => get_column_value( $data, $column_map, 'pdf_filenames' ),
			'resolved_pdf_urls' => get_column_value( $data, $column_map, 'resolved_pdf_urls' ),
			'local_pdf_paths'   => get_column_value( $data, $column_map, 'local_pdf_paths' ),
		);
	}

	fclose( $handle );

	return $rows;
}

/**
 * Build column map.
 *
 * @param array<int, string|null> $header Header.
 * @return array<string, int[]>
 */
function build_column_map( array $header ): array {
	$aliases = array(
		'form_number'       => array( 'form number', 'extracted form number', 'original form number' ),
		'form_title'        => array( 'form title', 'original form title' ),
		'form_url'          => array( 'form url' ),
		'case_type'         => array( 'case type' ),
		'pdf_filenames'     => array( 'pdf filenames' ),
		'resolved_pdf_urls' => array( 'resolved pdf urls' ),
		'local_pdf_paths'   => array( 'local pdf path', 'local pdf paths' ),
	);

	$normalized_header = array();

	foreach ( $header as $index => $column ) {
		$normalized_header[ (int) $index ] = strtolower( trim( (string) $column ) );
	}

	$map = array();

	foreach ( $aliases as $key => $names ) {
		$map[ $key ] = array();

		foreach ( $names as $alias ) {
			foreach ( $normalized_header as $index => $normalized ) {
				if ( $alias === $normalized ) {
					$map[ $key ][] = $index;
					break;
				}
			}
		}
	}

	return $map;
}

/**
 * Get column value.
 *
 * @param array<int, string|null> $row        Row.
 * @param array<string, int[]>    $column_map Map.
 * @param string                  $key        Key.
 * @return string
 */
function get_column_value( array $row, array $column_map, string $key ): string {
	if ( ! isset( $column_map[ $key ] ) ) {
		return '';
	}

	foreach ( $column_map[ $key ] as $index ) {
		if ( ! isset( $row[ $index ] ) ) {
			continue;
		}

		$value = trim( (string) $row[ $index ] );

		if ( 'form_number' === $key ) {
			$value = normalize_form_number( $value );
		}

		if ( '' !== $value ) {
			return $value;
		}
	}

	return '';
}

/**
 * Normalize form number.
 *
 * @param string $value Value.
 * @return string
 */
function normalize_form_number( string $value ): string {
	$value = trim( $value );

	if ( '' === $value || '--' === $value ) {
		return '';
	}

	return $value;
}

/**
 * Resolve form code.
 *
 * @param array<string, string> $row Row.
 * @return string
 */
function resolve_form_code( array $row ): string {
	$code = $row['form_number'] ?? '';

	if ( '' !== $code ) {
		return $code;
	}

	$title = trim( (string) ( $row['form_title'] ?? '' ) );

	if ( '' === $title ) {
		return '';
	}

	$slug = strtolower( preg_replace( '/[^a-z0-9]+/', '-', $title ) );
	$slug = trim( $slug, '-' );

	return strtoupper( $slug );
}

/**
 * Build base record.
 *
 * @param array<string, string> $row          Row.
 * @param string                $form_code    Form code.
 * @param string[]              $nyc_counties Counties.
 * @return array<string, mixed>
 */
function build_base_record( array $row, string $form_code, array $nyc_counties ): array {
	list( $court, $category ) = classify_court_category( $form_code, (string) ( $row['case_type'] ?? '' ) );
	$source_files = build_source_files_from_csv( $row );
	$wpd_conversion = array();

	if ( isset( $source_files['wpd'] ) ) {
		$wpd_conversion = array(
			'original_wpd'   => (string) ( $source_files['wpd']['path'] ?? '' ),
			'converted_docx' => null,
		);
	}

	return array(
		'form_code'                => $form_code,
		'internal_code'            => $form_code,
		'title'                    => (string) ( $row['form_title'] ?? '' ),
		'court'                    => $court,
		'category'                 => $category,
		'county_specific'          => false,
		'counties_supported'       => $nyc_counties,
		'source_files'             => $source_files,
		'preferred_source'         => '',
		'editable_source'          => '',
		'wpd_conversion'           => $wpd_conversion,
		'fillable_pdf_available'   => false,
		'docx_available'           => false,
		'wpd_available'            => isset( $source_files['wpd'] ),
		'import_status'            => 'pending',
		'official_url'             => (string) ( $row['form_url'] ?? '' ),
		'case_types'               => parse_case_types( (string) ( $row['case_type'] ?? '' ) ),
		'aliases'                  => array(),
		'workflow_references'      => array(),
		'fillable_strategy'        => 'none',
		'field_mapping_status'     => 'unmapped',
		'generation_ready'         => false,
	);
}

/**
 * Merge CSV row into record.
 *
 * @param array<string, mixed>  $record Record.
 * @param array<string, string> $row    Row.
 * @return array<string, mixed>
 */
function merge_row_into_record( array $record, array $row ): array {
	$new_files = build_source_files_from_csv( $row );
	$merged    = (array) ( $record['source_files'] ?? array() );

	foreach ( $new_files as $slot => $entry ) {
		if ( ! isset( $merged[ $slot ] ) || '' === (string) ( $merged[ $slot ]['path'] ?? '' ) ) {
			$merged[ $slot ] = $entry;
		}
	}

	$record['source_files'] = $merged;

	if ( '' === (string) ( $record['official_url'] ?? '' ) && '' !== (string) ( $row['form_url'] ?? '' ) ) {
		$record['official_url'] = (string) $row['form_url'];
	}

	if ( isset( $merged['wpd'] ) ) {
		$record['wpd_conversion'] = array(
			'original_wpd'   => (string) ( $merged['wpd']['path'] ?? '' ),
			'converted_docx' => $record['wpd_conversion']['converted_docx'] ?? null,
		);
		$record['wpd_available'] = true;
	}

	return $record;
}

/**
 * Build source files from CSV row.
 *
 * @param array<string, string> $row Row.
 * @return array<string, array<string, string>>
 */
function build_source_files_from_csv( array $row ): array {
	$urls      = parse_list( (string) ( $row['resolved_pdf_urls'] ?? '' ), '|' );
	$filenames = parse_list( (string) ( $row['pdf_filenames'] ?? '' ), '|' );
	$locals    = parse_list( (string) ( $row['local_pdf_paths'] ?? '' ), '|' );
	$pairs     = build_source_file_pairs( $urls, $filenames, $locals );
	$slots     = array();

	foreach ( $pairs as $pair ) {
		$filename  = (string) ( $pair['filename'] ?? '' );
		$url       = (string) ( $pair['url'] ?? '' );
		$local     = (string) ( $pair['local_path'] ?? '' );
		$extension = strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) );
		$slot      = classify_slot( $filename, $extension );

		if ( null === $slot ) {
			continue;
		}

		$path = ( '' !== $local && is_readable( $local ) ) ? $local : '';

		$slots[ $slot ] = array(
			'filename'        => $filename,
			'path'            => $path,
			'source_url'      => $url,
			'download_status' => '' !== $path ? 'success' : 'pending',
		);
	}

	return $slots;
}

/**
 * Classify slot from filename.
 *
 * @param string $filename  Filename.
 * @param string $extension Extension.
 * @return string|null
 */
function classify_slot( string $filename, string $extension ): ?string {
	$lower_name = strtolower( $filename );

	if ( 'docx' === $extension ) {
		return 'docx';
	}

	if ( 'doc' === $extension ) {
		return 'doc';
	}

	if ( 'wpd' === $extension ) {
		return 'wpd';
	}

	if ( 'rtf' === $extension ) {
		return 'rtf';
	}

	if ( 'pdf' === $extension ) {
		return str_contains( $lower_name, 'fillable' ) ? 'fillable_pdf' : 'pdf';
	}

	return null;
}

/**
 * Parse delimited list.
 *
 * @param string $value     Value.
 * @param string $delimiter Delimiter.
 * @return string[]
 */
function parse_list( string $value, string $delimiter ): array {
	if ( '' === trim( $value ) ) {
		return array();
	}

	return array_values(
		array_filter(
			array_map( 'trim', explode( $delimiter, $value ) ),
			static fn( $part ) => '' !== $part
		)
	);
}

/**
 * Build source file pairs.
 *
 * @param string[] $urls      URLs.
 * @param string[] $filenames Filenames.
 * @param string[] $locals    Local paths.
 * @return array<int, array{url: string, filename: string, local_path: string}>
 */
function build_source_file_pairs( array $urls, array $filenames, array $locals = array() ): array {
	$pairs = array();
	$seen  = array();
	$count = max( count( $urls ), count( $locals ) );

	for ( $index = 0; $index < $count; ++$index ) {
		$url        = trim( (string) ( $urls[ $index ] ?? '' ) );
		$local_path = trim( (string) ( $locals[ $index ] ?? '' ) );

		if ( '' === $url && '' === $local_path ) {
			continue;
		}

		$normalized = '' !== $url ? $url : 'local:' . $local_path;

		if ( isset( $seen[ $normalized ] ) ) {
			continue;
		}

		$filename = $filenames[ $index ] ?? '';

		if ( '' === $filename && '' !== $url ) {
			$filename = basename( (string) parse_url( $url, PHP_URL_PATH ) );
		} elseif ( '' === $filename && '' !== $local_path ) {
			$filename = basename( $local_path );
		}

		$pairs[] = array(
			'url'        => $url,
			'filename'   => trim( (string) $filename ),
			'local_path' => $local_path,
		);
		$seen[ $normalized ] = true;
	}

	return $pairs;
}

/**
 * Classify court and category.
 *
 * @param string $form_code Form code.
 * @param string $case_type Case type.
 * @return array{0: string, 1: string}
 */
function classify_court_category( string $form_code, string $case_type ): array {
	$case_lower = strtolower( $case_type );
	$code_upper = strtoupper( $form_code );

	if ( preg_match( '/^UD-/', $code_upper ) || preg_match( '/^DRL/', $code_upper ) ) {
		return array( 'supreme_court', 'divorce' );
	}

	if ( str_contains( $case_lower, 'divorce' ) || str_contains( $case_lower, 'matrimonial' ) ) {
		return array( 'supreme_court', 'divorce' );
	}

	if ( str_contains( $case_lower, 'supreme' ) ) {
		return array( 'supreme_court', 'matrimonial' );
	}

	$category_map = array(
		'child custody or visitation' => array( 'family_court', 'custody' ),
		'custody'                     => array( 'family_court', 'custody' ),
		'visitation'                  => array( 'family_court', 'visitation' ),
		'child support'               => array( 'family_court', 'child_support' ),
		'paternity'                   => array( 'family_court', 'paternity' ),
		'guardianship'                => array( 'family_court', 'guardianship' ),
		'adoption'                    => array( 'family_court', 'adoption' ),
		'family offense'              => array( 'family_court', 'family_offense' ),
		'order of protection'         => array( 'family_court', 'family_offense' ),
	);

	foreach ( $category_map as $needle => $result ) {
		if ( str_contains( $case_lower, $needle ) ) {
			return $result;
		}
	}

	if ( str_contains( $case_lower, 'family' ) ) {
		return array( 'family_court', 'general' );
	}

	return array( 'family_court', 'general' );
}

/**
 * Parse case types.
 *
 * @param string $case_type Case type.
 * @return string[]
 */
function parse_case_types( string $case_type ): array {
	if ( '' === trim( $case_type ) ) {
		return array();
	}

	return array_values(
		array_filter(
			array_map( 'trim', explode( ',', $case_type ) ),
			static fn( $part ) => '' !== $part
		)
	);
}

/**
 * Build workflow references reverse index.
 *
 * @param string $workflow_base Workflow directory.
 * @return array<string, array<int, array{workflow: string, stage: string, requirement: string}>>
 */
function build_workflow_references_index( string $workflow_base ): array {
	$files = array_merge(
		glob( $workflow_base . '/divorce/*.json' ) ?: array(),
		glob( $workflow_base . '/family_court/*.json' ) ?: array()
	);

	$index = array();

	foreach ( $files as $file ) {
		$raw = file_get_contents( $file );

		if ( false === $raw ) {
			continue;
		}

		$workflow = json_decode( $raw, true );

		if ( ! is_array( $workflow ) || empty( $workflow['workflow'] ) ) {
			continue;
		}

		$workflow_key = (string) $workflow['workflow'];

		foreach ( array( 'required_forms' => 'required', 'optional_forms' => 'optional' ) as $form_key => $requirement ) {
			foreach ( (array) ( $workflow[ $form_key ] ?? array() ) as $stage_block ) {
				$stage = (string) ( $stage_block['stage'] ?? '' );

				foreach ( (array) ( $stage_block['forms'] ?? array() ) as $form ) {
					$code = (string) ( $form['code'] ?? '' );

					if ( '' === $code ) {
						continue;
					}

					if ( ! isset( $index[ $code ] ) ) {
						$index[ $code ] = array();
					}

					$index[ $code ][] = array(
						'workflow'    => $workflow_key,
						'stage'       => $stage,
						'requirement' => $requirement,
					);
				}
			}
		}
	}

	return $index;
}

/**
 * Preserve field mapping status.
 *
 * @param array<string, mixed> $record Record.
 * @param string               $path   Path.
 * @return array<string, mixed>
 */
function preserve_field_mapping_status( array $record, string $path ): array {
	if ( ! is_readable( $path ) ) {
		return $record;
	}

	$raw = file_get_contents( $path );

	if ( false === $raw ) {
		return $record;
	}

	$existing = json_decode( $raw, true );

	if ( ! is_array( $existing ) ) {
		return $record;
	}

	$status = (string) ( $existing['field_mapping_status'] ?? '' );

	if ( in_array( $status, array( 'unmapped', 'partial', 'mapped', 'not_required' ), true ) ) {
		$record['field_mapping_status'] = $status;
	}

	return $record;
}

/**
 * Resolve import status.
 *
 * @param array<string, mixed> $record Record.
 * @return string
 */
function resolve_import_status( array $record ): string {
	$preferred = (string) ( $record['preferred_source'] ?? '' );
	$files     = (array) ( $record['source_files'] ?? array() );

	if ( '' === $preferred ) {
		return empty( $files ) ? 'pending' : 'partial';
	}

	$slot = $files[ $preferred ] ?? null;

	if ( is_array( $slot ) && '' !== (string) ( $slot['path'] ?? '' ) && is_readable( (string) $slot['path'] ) ) {
		return 'complete';
	}

	foreach ( $files as $entry ) {
		if ( is_array( $entry ) && '' !== (string) ( $entry['path'] ?? '' ) && is_readable( (string) $entry['path'] ) ) {
			return 'partial';
		}
	}

	return 'pending';
}

/**
 * Whether slot is available.
 *
 * @param array<string, mixed> $record Record.
 * @param string               $slot   Slot.
 * @return bool
 */
function slot_available( array $record, string $slot ): bool {
	$files = (array) ( $record['source_files'] ?? array() );
	$entry = $files[ $slot ] ?? null;

	if ( ! is_array( $entry ) ) {
		return false;
	}

	$status = (string) ( $entry['download_status'] ?? '' );

	if ( in_array( $status, array( 'failed', 'unsupported' ), true ) ) {
		return false;
	}

	$path = (string) ( $entry['path'] ?? '' );

	if ( '' !== $path && is_readable( $path ) ) {
		return true;
	}

	return '' !== (string) ( $entry['source_url'] ?? '' );
}

/**
 * Record output path.
 *
 * @param string $base_dir  Base dir.
 * @param string $court     Court.
 * @param string $form_code Form code.
 * @return string
 */
function record_path( string $base_dir, string $court, string $form_code ): string {
	$court_dir = in_array( $court, array( 'supreme_court', 'family_court' ), true ) ? $court : 'family_court';

	return rtrim( $base_dir, '/' ) . '/' . $court_dir . '/' . form_filename( $form_code );
}

/**
 * Safe JSON filename.
 *
 * @param string $form_code Form code.
 * @return string
 */
function form_filename( string $form_code ): string {
	$slug = strtolower( $form_code );
	$slug = preg_replace( '/[^a-z0-9]+/', '-', $slug );
	$slug = trim( (string) $slug, '-' );

	return ( '' !== $slug ? $slug : 'form' ) . '.json';
}
