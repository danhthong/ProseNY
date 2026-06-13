<?php
/**
 * WP-CLI command: build the Forms Repository from existing form data.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Forms;

use ProSe\Core\Routing\Workflow_Catalog;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Build_Repository_Command
 *
 * Registers `wp prose forms build-repository`.
 */
final class Build_Repository_Command {

	/**
	 * NYC counties supported by default.
	 *
	 * @var string[]
	 */
	private const NYC_COUNTIES = array(
		'New York',
		'Kings',
		'Queens',
		'Bronx',
		'Richmond',
	);

	/**
	 * Build or refresh canonical form records in docs/forms/.
	 *
	 * ## OPTIONS
	 *
	 * [--csv=<path>]
	 * : Path to forms_enriched.csv. Defaults to the project collector CSV.
	 *
	 * [--dry-run]
	 * : Report actions without writing JSON files.
	 *
	 * [--convert-wpd]
	 * : Attempt WPD to DOCX conversion when LibreOffice is available.
	 *
	 * ## EXAMPLES
	 *
	 *     wp prose forms build-repository
	 *     wp prose forms build-repository --dry-run
	 *     wp prose forms build-repository --convert-wpd
	 *
	 * @param array<int, string>    $args       Positional args (unused).
	 * @param array<string, string> $assoc_args Flags.
	 * @return void
	 */
	public function build_repository( array $args, array $assoc_args ): void {
		unset( $args );

		$dry_run     = isset( $assoc_args['dry-run'] );
		$convert_wpd = isset( $assoc_args['convert-wpd'] );
		$csv_path    = isset( $assoc_args['csv'] ) ? (string) $assoc_args['csv'] : $this->default_csv_path();

		if ( ! is_readable( $csv_path ) ) {
			$this->cli_error( sprintf( 'CSV not readable: %s', $csv_path ) );
		}

		$rows = $this->parse_csv( $csv_path );

		if ( empty( $rows ) ) {
			$this->cli_error( 'No rows found in CSV.' );
		}

		$repository     = new Form_Repository();
		$file_manager   = new Form_File_Manager();
		$forms_catalog  = new Forms_Catalog( new Workflow_Catalog() );
		$workflow_index = $forms_catalog->build_workflow_references_index();
		$base_dir       = PROSE_CORE_PATH . 'docs/forms';
		$records        = array();
		$skipped        = 0;

		foreach ( $rows as $row ) {
			$form_code = $this->resolve_form_code( $row );

			if ( '' === $form_code ) {
				++$skipped;
				continue;
			}

			if ( ! isset( $records[ $form_code ] ) ) {
				$records[ $form_code ] = $this->build_base_record( $row, $form_code );
			} else {
				$records[ $form_code ] = $this->merge_row_into_record( $records[ $form_code ], $row );
			}
		}

		$records = Form_Repository_Seeder::ensure_workflow_stubs( $records );

		$written = 0;
		$failed  = 0;

		foreach ( $records as $form_code => $record ) {
			$post = $repository->get_by_form_code( $form_code );

			if ( ! $post instanceof \WP_Post ) {
				$post = $repository->get_by_title( (string) ( $record['title'] ?? '' ) );
			}

			$record = $this->enrich_from_post( $record, $post, $file_manager );

			if ( $convert_wpd ) {
				$record = $this->maybe_convert_wpd( $record, $file_manager, $form_code );
			}

			$record['workflow_references'] = $workflow_index[ $form_code ] ?? array();

			$existing_path = $this->record_path( $base_dir, (string) $record['court'], $form_code );
			$record        = $this->preserve_field_mapping_status( $record, $existing_path );

			$computed = Form_Source_Selector::compute( (array) ( $record['source_files'] ?? array() ) );

			$record['preferred_source']  = $computed['preferred_source'];
			$record['editable_source']   = $computed['editable_source'];
			$record['fillable_strategy'] = $computed['fillable_strategy'];
			$record['generation_ready']  = $computed['generation_ready'];
			$record['import_status']     = $this->resolve_import_status( $record );

			$record['docx_available']          = $this->slot_available( $record, 'docx' ) || $this->slot_available( $record, 'converted_docx' );
			$record['fillable_pdf_available']  = $this->slot_available( $record, 'fillable_pdf' );
			$record['wpd_available']           = $this->slot_available( $record, 'wpd' );

			if ( $dry_run ) {
				$this->cli_log( sprintf( '[dry-run] Would write %s (%s)', $form_code, $existing_path ) );
				++$written;
				continue;
			}

			$dir = dirname( $existing_path );

			if ( ! wp_mkdir_p( $dir ) ) {
				++$failed;
				$this->cli_warning( sprintf( 'Failed to create directory for %s', $form_code ) );
				continue;
			}

			$json = wp_json_encode( $record, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );

			if ( false === $json || false === file_put_contents( $existing_path, $json . "\n" ) ) {
				++$failed;
				$this->cli_warning( sprintf( 'Failed to write %s', $form_code ) );
				continue;
			}

			++$written;
		}

		Forms_Catalog::reset_cache();

		if ( $dry_run ) {
			$this->cli_success(
				sprintf(
					'Dry run complete: %d records would be written, %d CSV rows skipped, %d failed.',
					$written,
					$skipped,
					$failed
				)
			);
			return;
		}

		$this->cli_success(
			sprintf(
				'Repository build complete: %d records written, %d CSV rows skipped, %d failed.',
				$written,
				$skipped,
				$failed
			)
		);
	}

	/**
	 * Log a message to CLI or stdout.
	 *
	 * @param string $message Message.
	 * @return void
	 */
	private function cli_log( string $message ): void {
		if ( class_exists( '\WP_CLI' ) ) {
			\WP_CLI::log( $message );
			return;
		}

		echo $message . PHP_EOL;
	}

	/**
	 * Log a warning.
	 *
	 * @param string $message Message.
	 * @return void
	 */
	private function cli_warning( string $message ): void {
		if ( class_exists( '\WP_CLI' ) ) {
			\WP_CLI::warning( $message );
			return;
		}

		fwrite( STDERR, 'Warning: ' . $message . PHP_EOL );
	}

	/**
	 * Log success and exit on error paths.
	 *
	 * @param string $message Message.
	 * @return void
	 */
	private function cli_success( string $message ): void {
		if ( class_exists( '\WP_CLI' ) ) {
			\WP_CLI::success( $message );
			return;
		}

		echo 'Success: ' . $message . PHP_EOL;
	}

	/**
	 * Report a fatal error.
	 *
	 * @param string $message Message.
	 * @return void
	 */
	private function cli_error( string $message ): void {
		if ( class_exists( '\WP_CLI' ) ) {
			\WP_CLI::error( $message );
		}

		fwrite( STDERR, 'Error: ' . $message . PHP_EOL );
		exit( 1 );
	}

	/**
	 * Default CSV path relative to the plugin.
	 *
	 * @return string
	 */
	private function default_csv_path(): string {
		$candidates = array(
			PROSE_CORE_PATH . '../../../collect_forms/crawl4ai_forms/forms_enriched.csv',
			PROSE_CORE_PATH . '../../../../collect_forms/crawl4ai_forms/forms_enriched.csv',
		);

		foreach ( $candidates as $candidate ) {
			$resolved = realpath( $candidate );

			if ( false !== $resolved && is_readable( $resolved ) ) {
				return $resolved;
			}
		}

		return $candidates[0];
	}

	/**
	 * Parse CSV into associative rows.
	 *
	 * @param string $csv_path CSV file path.
	 * @return array<int, array<string, string>>
	 */
	private function parse_csv( string $csv_path ): array {
		$handle = fopen( $csv_path, 'r' );

		if ( false === $handle ) {
			return array();
		}

		$header = fgetcsv( $handle, 0, ',', '"', '\\' );

		if ( ! is_array( $header ) ) {
			fclose( $handle );
			return array();
		}

		$column_map = $this->build_column_map( $header );
		$rows       = array();

		while ( ( $data = fgetcsv( $handle, 0, ',', '"', '\\' ) ) !== false ) {
			if ( ! is_array( $data ) ) {
				continue;
			}

			$rows[] = array(
				'form_number'       => $this->get_column_value( $data, $column_map, 'form_number' ),
				'form_title'        => $this->get_column_value( $data, $column_map, 'form_title' ),
				'form_url'          => $this->get_column_value( $data, $column_map, 'form_url' ),
				'case_type'         => $this->get_column_value( $data, $column_map, 'case_type' ),
				'pdf_filenames'     => $this->get_column_value( $data, $column_map, 'pdf_filenames' ),
				'resolved_pdf_urls' => $this->get_column_value( $data, $column_map, 'resolved_pdf_urls' ),
				'local_pdf_paths'   => $this->get_column_value( $data, $column_map, 'local_pdf_paths' ),
			);
		}

		fclose( $handle );

		return $rows;
	}

	/**
	 * Build column map from CSV header.
	 *
	 * @param array<int, string|null> $header Header row.
	 * @return array<string, int[]>
	 */
	private function build_column_map( array $header ): array {
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
	 * Get a column value from a row.
	 *
	 * @param array<int, string|null> $row        CSV row.
	 * @param array<string, int[]>    $column_map Column map.
	 * @param string                  $key        Column key.
	 * @return string
	 */
	private function get_column_value( array $row, array $column_map, string $key ): string {
		if ( ! isset( $column_map[ $key ] ) ) {
			return '';
		}

		foreach ( $column_map[ $key ] as $index ) {
			if ( ! isset( $row[ $index ] ) ) {
				continue;
			}

			$value = trim( (string) $row[ $index ] );

			if ( 'form_number' === $key ) {
				$value = $this->normalize_form_number( $value );
			}

			if ( '' !== $value ) {
				return $value;
			}
		}

		return '';
	}

	/**
	 * Normalize a form number.
	 *
	 * @param string $value Raw value.
	 * @return string
	 */
	private function normalize_form_number( string $value ): string {
		$value = trim( $value );

		if ( '' === $value || '--' === $value ) {
			return '';
		}

		return $value;
	}

	/**
	 * Resolve canonical form code for a CSV row.
	 *
	 * @param array<string, string> $row CSV row.
	 * @return string
	 */
	private function resolve_form_code( array $row ): string {
		$code = $row['form_number'] ?? '';

		if ( '' !== $code ) {
			return $code;
		}

		$title = trim( (string) ( $row['form_title'] ?? '' ) );

		if ( '' === $title ) {
			return '';
		}

		return strtoupper( sanitize_title( $title ) );
	}

	/**
	 * Build a base record from a CSV row.
	 *
	 * @param array<string, string> $row       CSV row.
	 * @param string                $form_code Form code.
	 * @return array<string, mixed>
	 */
	private function build_base_record( array $row, string $form_code ): array {
		list( $court, $category ) = $this->classify_court_category( $form_code, (string) ( $row['case_type'] ?? '' ) );

		$case_types = $this->parse_case_types( (string) ( $row['case_type'] ?? '' ) );
		$source_files = $this->build_source_files_from_csv( $row );

		$wpd_conversion = array();

		if ( isset( $source_files['wpd'] ) ) {
			$wpd_conversion['original_wpd'] = (string) ( $source_files['wpd']['path'] ?? '' );
			$wpd_conversion['converted_docx'] = null;
		}

		return array(
			'form_code'                => $form_code,
			'internal_code'            => $form_code,
			'title'                    => (string) ( $row['form_title'] ?? '' ),
			'court'                    => $court,
			'category'                 => $category,
			'county_specific'          => false,
			'counties_supported'       => self::NYC_COUNTIES,
			'source_files'             => $source_files,
			'preferred_source'         => '',
			'editable_source'          => '',
			'wpd_conversion'           => $wpd_conversion,
			'fillable_pdf_available'   => false,
			'docx_available'           => false,
			'wpd_available'            => isset( $source_files['wpd'] ),
			'import_status'            => 'pending',
			'official_url'             => esc_url_raw( (string) ( $row['form_url'] ?? '' ) ),
			'case_types'               => $case_types,
			'aliases'                  => array(),
			'workflow_references'      => array(),
			'fillable_strategy'        => 'none',
			'field_mapping_status'     => 'unmapped',
			'generation_ready'         => false,
		);
	}

	/**
	 * Merge an additional CSV row into an existing record.
	 *
	 * @param array<string, mixed>  $record Existing record.
	 * @param array<string, string> $row    CSV row.
	 * @return array<string, mixed>
	 */
	private function merge_row_into_record( array $record, array $row ): array {
		$new_files = $this->build_source_files_from_csv( $row );
		$merged    = (array) ( $record['source_files'] ?? array() );

		foreach ( $new_files as $slot => $entry ) {
			if ( ! isset( $merged[ $slot ] ) || '' === (string) ( $merged[ $slot ]['path'] ?? '' ) ) {
				$merged[ $slot ] = $entry;
			}
		}

		$record['source_files'] = $merged;

		if ( '' === (string) ( $record['official_url'] ?? '' ) && '' !== (string) ( $row['form_url'] ?? '' ) ) {
			$record['official_url'] = esc_url_raw( (string) $row['form_url'] );
		}

		if ( isset( $merged['wpd'] ) ) {
			$record['wpd_conversion'] = array(
				'original_wpd'     => (string) ( $merged['wpd']['path'] ?? '' ),
				'converted_docx'   => $record['wpd_conversion']['converted_docx'] ?? null,
			);
			$record['wpd_available'] = true;
		}

		return $record;
	}

	/**
	 * Build source file slots from a CSV row.
	 *
	 * @param array<string, string> $row CSV row.
	 * @return array<string, array<string, string>>
	 */
	private function build_source_files_from_csv( array $row ): array {
		$urls      = $this->parse_list( (string) ( $row['resolved_pdf_urls'] ?? '' ), '|' );
		$filenames = $this->parse_list( (string) ( $row['pdf_filenames'] ?? '' ), '|' );
		$locals    = $this->parse_list( (string) ( $row['local_pdf_paths'] ?? '' ), '|' );
		$pairs     = $this->build_source_file_pairs( $urls, $filenames, $locals );
		$slots     = array();

		foreach ( $pairs as $pair ) {
			$filename  = (string) ( $pair['filename'] ?? '' );
			$url       = (string) ( $pair['url'] ?? '' );
			$local     = (string) ( $pair['local_path'] ?? '' );
			$extension = strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) );
			$slot      = $this->classify_slot( $filename, $extension );

			if ( null === $slot ) {
				continue;
			}

			$path = '';

			if ( '' !== $local && is_readable( $local ) ) {
				$path = $local;
			}

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
	 * Classify a filename into a source slot.
	 *
	 * @param string $filename  Filename.
	 * @param string $extension Extension.
	 * @return string|null
	 */
	private function classify_slot( string $filename, string $extension ): ?string {
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
			if ( str_contains( $lower_name, 'fillable' ) ) {
				return 'fillable_pdf';
			}

			return 'pdf';
		}

		return null;
	}

	/**
	 * Parse pipe-delimited list.
	 *
	 * @param string $value     Raw value.
	 * @param string $delimiter Delimiter.
	 * @return string[]
	 */
	private function parse_list( string $value, string $delimiter ): array {
		if ( '' === trim( $value ) ) {
			return array();
		}

		$parts = explode( $delimiter, $value );

		return array_values(
			array_filter(
				array_map( 'trim', $parts ),
				static fn( $part ) => '' !== $part
			)
		);
	}

	/**
	 * Build aligned source file pairs.
	 *
	 * @param string[] $urls      URLs.
	 * @param string[] $filenames Filenames.
	 * @param string[] $locals    Local paths.
	 * @return array<int, array{url: string, filename: string, local_path: string}>
	 */
	private function build_source_file_pairs( array $urls, array $filenames, array $locals = array() ): array {
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
				$filename = basename( (string) wp_parse_url( $url, PHP_URL_PATH ) );
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
	 * Classify court and category from form code and case type.
	 *
	 * @param string $form_code Form code.
	 * @param string $case_type Case type string.
	 * @return array{0: string, 1: string}
	 */
	private function classify_court_category( string $form_code, string $case_type ): array {
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
	 * Parse case type string into array.
	 *
	 * @param string $case_type Case type CSV value.
	 * @return string[]
	 */
	private function parse_case_types( string $case_type ): array {
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
	 * Enrich record from a prose_form post.
	 *
	 * @param array<string, mixed>  $record       Record.
	 * @param \WP_Post|null       $post         Form post.
	 * @param Form_File_Manager   $file_manager File manager.
	 * @return array<string, mixed>
	 */
	private function enrich_from_post( array $record, ?\WP_Post $post, Form_File_Manager $file_manager ): array {
		if ( ! $post instanceof \WP_Post ) {
			return $record;
		}

		$stored_code = (string) get_post_meta( $post->ID, Form_Meta::META_FORM_CODE, true );

		if ( '' !== $stored_code ) {
			$record['internal_code'] = $stored_code;
		}

		$aliases = get_post_meta( $post->ID, Form_Meta::META_ALIASES, true );

		if ( is_array( $aliases ) && ! empty( $aliases ) ) {
			$record['aliases'] = array_values( array_map( 'strval', $aliases ) );
		}

		$official_url = (string) get_post_meta( $post->ID, Form_Meta::META_OFFICIAL_URL, true );

		if ( '' !== $official_url ) {
			$record['official_url'] = esc_url_raw( $official_url );
		}

		$source_files_meta = get_post_meta( $post->ID, Form_Meta::META_SOURCE_FILES, true );
		$slots             = (array) ( $record['source_files'] ?? array() );

		if ( is_array( $source_files_meta ) && ! empty( $source_files_meta['files'] ) ) {
			foreach ( (array) $source_files_meta['files'] as $entry ) {
				if ( ! is_array( $entry ) ) {
					continue;
				}

				$filename  = (string) ( $entry['filename'] ?? '' );
				$extension = strtolower( (string) ( $entry['extension'] ?? pathinfo( $filename, PATHINFO_EXTENSION ) ) );
				$slot      = $this->classify_slot( $filename, $extension );

				if ( null === $slot ) {
					continue;
				}

				$local_path = (string) ( $entry['local_path'] ?? '' );
				$status     = (string) ( $entry['download_status'] ?? 'unknown' );

				$slots[ $slot ] = array(
					'filename'        => $filename,
					'path'            => $local_path,
					'source_url'      => (string) ( $entry['source_url'] ?? '' ),
					'download_status' => $status,
				);
			}
		}

		$is_fillable = (bool) get_post_meta( $post->ID, Form_Meta::META_PDF_FILLABLE, true );

		if ( $is_fillable ) {
			if ( isset( $slots['fillable_pdf'] ) ) {
				$slots['fillable_pdf']['download_status'] = 'success';
			} elseif ( isset( $slots['pdf'] ) ) {
				$slots['fillable_pdf'] = $slots['pdf'];
				unset( $slots['pdf'] );
			}
		}

		$record['source_files'] = $slots;

		if ( isset( $slots['wpd'] ) ) {
			$record['wpd_conversion'] = array(
				'original_wpd'   => (string) ( $slots['wpd']['path'] ?? '' ),
				'converted_docx' => $record['wpd_conversion']['converted_docx'] ?? null,
			);
		}

		if ( isset( $slots['converted_docx'] ) ) {
			$record['wpd_conversion']['converted_docx'] = (string) ( $slots['converted_docx']['path'] ?? '' );
		}

		unset( $file_manager );

		return $record;
	}

	/**
	 * Attempt WPD to DOCX conversion when LibreOffice is available.
	 *
	 * @param array<string, mixed> $record       Record.
	 * @param Form_File_Manager    $file_manager File manager.
	 * @param string               $form_code    Form code.
	 * @return array<string, mixed>
	 */
	private function maybe_convert_wpd( array $record, Form_File_Manager $file_manager, string $form_code ): array {
		$source_files = (array) ( $record['source_files'] ?? array() );
		$wpd          = $source_files['wpd'] ?? null;

		if ( ! is_array( $wpd ) ) {
			return $record;
		}

		$wpd_path = (string) ( $wpd['path'] ?? '' );

		if ( '' === $wpd_path || ! is_readable( $wpd_path ) ) {
			return $record;
		}

		if ( ! empty( $record['wpd_conversion']['converted_docx'] ) && is_readable( (string) $record['wpd_conversion']['converted_docx'] ) ) {
			return $record;
		}

		$soffice = $this->find_soffice_binary();

		if ( '' === $soffice ) {
			return $record;
		}

		$form_slug  = sanitize_title( $form_code );
		$source_dir = $file_manager->get_form_source_dir( $form_slug );

		if ( is_wp_error( $source_dir ) ) {
			return $record;
		}

		$output_dir = $source_dir['path'];
		$command    = sprintf(
			'%s --headless --convert-to docx --outdir %s %s 2>&1',
			escapeshellarg( $soffice ),
			escapeshellarg( $output_dir ),
			escapeshellarg( $wpd_path )
		);

		exec( $command, $output, $exit_code );

		if ( 0 !== $exit_code ) {
			return $record;
		}

		$converted_name = pathinfo( $wpd_path, PATHINFO_FILENAME ) . '.docx';
		$converted_path = $output_dir . $converted_name;

		if ( ! is_readable( $converted_path ) ) {
			return $record;
		}

		$source_files['converted_docx'] = array(
			'filename'        => $converted_name,
			'path'            => $converted_path,
			'source_url'      => '',
			'download_status' => 'success',
		);

		$record['source_files'] = $source_files;
		$record['wpd_conversion'] = array(
			'original_wpd'   => $wpd_path,
			'converted_docx' => $converted_path,
		);

		return $record;
	}

	/**
	 * Find LibreOffice soffice binary.
	 *
	 * @return string
	 */
	private function find_soffice_binary(): string {
		$candidates = array( 'soffice', '/usr/bin/soffice', '/Applications/LibreOffice.app/Contents/MacOS/soffice' );

		foreach ( $candidates as $candidate ) {
			$path = trim( (string) shell_exec( 'command -v ' . escapeshellarg( $candidate ) . ' 2>/dev/null' ) );

			if ( '' !== $path && is_executable( $path ) ) {
				return $path;
			}

			if ( is_executable( $candidate ) ) {
				return $candidate;
			}
		}

		return '';
	}

	/**
	 * Preserve field_mapping_status from an existing record file.
	 *
	 * @param array<string, mixed> $record Record.
	 * @param string               $path   Existing record path.
	 * @return array<string, mixed>
	 */
	private function preserve_field_mapping_status( array $record, string $path ): array {
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
	 * Resolve import status from record state.
	 *
	 * @param array<string, mixed> $record Record.
	 * @return string
	 */
	private function resolve_import_status( array $record ): string {
		$preferred = (string) ( $record['preferred_source'] ?? '' );
		$files     = (array) ( $record['source_files'] ?? array() );

		if ( '' === $preferred ) {
			return empty( $files ) ? 'pending' : 'partial';
		}

		$slot = $files[ $preferred ] ?? null;

		if ( is_array( $slot ) && '' !== (string) ( $slot['path'] ?? '' ) && is_readable( (string) $slot['path'] ) ) {
			return 'complete';
		}

		$has_any_local = false;

		foreach ( $files as $entry ) {
			if ( is_array( $entry ) && '' !== (string) ( $entry['path'] ?? '' ) && is_readable( (string) $entry['path'] ) ) {
				$has_any_local = true;
				break;
			}
		}

		return $has_any_local ? 'partial' : 'pending';
	}

	/**
	 * Whether a source slot is available.
	 *
	 * @param array<string, mixed> $record Record.
	 * @param string               $slot   Slot key.
	 * @return bool
	 */
	private function slot_available( array $record, string $slot ): bool {
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
	 * Get output path for a form record.
	 *
	 * @param string $base_dir  Repository base directory.
	 * @param string $court     Court key.
	 * @param string $form_code Form code.
	 * @return string
	 */
	private function record_path( string $base_dir, string $court, string $form_code ): string {
		$court_dir = in_array( $court, array( 'supreme_court', 'family_court' ), true ) ? $court : 'family_court';

		return trailingslashit( $base_dir ) . $court_dir . '/' . $this->form_filename( $form_code );
	}

	/**
	 * Build a safe JSON filename for a form code.
	 *
	 * @param string $form_code Form code.
	 * @return string
	 */
	private function form_filename( string $form_code ): string {
		$slug = strtolower( $form_code );
		$slug = preg_replace( '/[^a-z0-9]+/', '-', $slug );
		$slug = trim( (string) $slug, '-' );

		return ( '' !== $slug ? $slug : 'form' ) . '.json';
	}
}
