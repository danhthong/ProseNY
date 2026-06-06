<?php
/**
 * CSV session state and export row storage.
 *
 * @package NYCourtFormsCollector
 */

namespace NYCourtFormsCollector\Includes;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class CSV
 */
class CSV {

	public const OPTION_ROWS     = 'nycfc_csv_rows';
	public const OPTION_RESULTS  = 'nycfc_crawl_results';
	public const OPTION_ERRORS   = 'nycfc_crawl_errors';
	public const OPTION_PROGRESS = 'nycfc_crawl_progress';
	public const OPTION_LOG      = 'nycfc_activity_log';
	public const OPTION_EXPORT   = 'nycfc_export_file';
	public const OPTION_VISITED  = 'nycfc_visited_pages';

	/**
	 * Export columns matching forms_enriched.csv.
	 *
	 * @var string[]
	 */
	public const EXPORT_COLUMNS = array(
		'Original Form Number',
		'Original Form Title',
		'Form URL',
		'Extracted Form Number',
		'Case Type',
		'Legal Action',
		'Original PDF URLs',
		'Resolved PDF URLs',
		'PDF Filenames',
	);

	/**
	 * Start a new crawl session from a listing URL.
	 *
	 * @param string $listing_url Listing page URL.
	 * @return array|\WP_Error
	 */
	public static function start_session( string $listing_url ) {
		$listing_url = esc_url_raw( trim( $listing_url ) );

		if ( '' === $listing_url || ! filter_var( $listing_url, FILTER_VALIDATE_URL ) ) {
			return new \WP_Error( 'invalid_url', __( 'Please enter a valid listing URL.', 'ny-court-forms-collector' ) );
		}

		$host = wp_parse_url( $listing_url, PHP_URL_HOST );

		if ( ! is_string( $host ) || ! str_contains( strtolower( $host ), 'nycourts.gov' ) ) {
			return new \WP_Error(
				'invalid_host',
				__( 'Listing URL must be on nycourts.gov.', 'ny-court-forms-collector' )
			);
		}

		self::reset_session_data();

		update_option( self::OPTION_ROWS, array(), false );
		update_option( self::OPTION_VISITED, array(), false );

		self::init_progress(
			array(
				'total_rows'     => 0,
				'processed_rows' => 0,
				'success_rows'   => 0,
				'failed_rows'    => 0,
				'current_row'    => 0,
				'current_url'    => $listing_url,
				'crawl_status'   => 'running',
				'phase'          => 'collect',
				'listing_url'    => $listing_url,
				'next_url'       => $listing_url,
				'pages_crawled'  => 0,
				'started_at'     => time(),
			)
		);

		self::add_log_entry(
			sprintf(
				/* translators: %s: listing URL */
				__( 'Started collection from %s', 'ny-court-forms-collector' ),
				$listing_url
			)
		);

		return array(
			'listing_url' => $listing_url,
			'message'     => __( 'Collection started.', 'ny-court-forms-collector' ),
		);
	}

	/**
	 * Append collected form links, deduping by Form URL.
	 *
	 * @param array<int, array<string, string>> $links Form link rows.
	 * @return int Number of new rows added.
	 */
	public static function append_links( array $links ): int {
		$rows     = self::get_rows();
		$existing = array();

		foreach ( $rows as $row ) {
			$url = $row['Form URL'] ?? '';

			if ( '' !== $url ) {
				$existing[ $url ] = true;
			}
		}

		$added = 0;

		foreach ( $links as $link ) {
			$url = $link['Form URL'] ?? '';

			if ( '' === $url || isset( $existing[ $url ] ) ) {
				continue;
			}

			$rows[]           = array(
				'Form Number' => sanitize_text_field( $link['Form Number'] ?? '' ),
				'Form Title'  => sanitize_text_field( $link['Form Title'] ?? '' ),
				'Form URL'    => esc_url_raw( $url ),
			);
			$existing[ $url ] = true;
			++$added;
		}

		update_option( self::OPTION_ROWS, $rows, false );

		return $added;
	}

	/**
	 * Get stored form rows.
	 *
	 * @return array<int, array<string, string>>
	 */
	public static function get_rows(): array {
		$rows = get_option( self::OPTION_ROWS, array() );

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Get visited listing page URLs.
	 *
	 * @return string[]
	 */
	public static function get_visited_pages(): array {
		$visited = get_option( self::OPTION_VISITED, array() );

		return is_array( $visited ) ? $visited : array();
	}

	/**
	 * Mark a listing page URL as visited.
	 *
	 * @param string $url Page URL.
	 * @return void
	 */
	public static function mark_page_visited( string $url ): void {
		$visited   = self::get_visited_pages();
		$visited[] = esc_url_raw( $url );
		$visited   = array_values( array_unique( array_filter( $visited ) ) );

		update_option( self::OPTION_VISITED, $visited, false );
	}

	/**
	 * Initialize crawl progress.
	 *
	 * @param array<string, mixed> $data Progress data.
	 * @return void
	 */
	public static function init_progress( array $data ): void {
		$defaults = self::get_progress_defaults();
		$progress = array_merge( $defaults, $data );
		$progress['updated_at'] = time();

		update_option( self::OPTION_PROGRESS, $progress, false );
	}

	/**
	 * Default progress structure.
	 *
	 * @return array<string, mixed>
	 */
	private static function get_progress_defaults(): array {
		return array(
			'total_rows'     => 0,
			'processed_rows' => 0,
			'success_rows'   => 0,
			'failed_rows'    => 0,
			'current_row'    => 0,
			'current_url'    => '',
			'crawl_status'   => 'idle',
			'phase'          => 'collect',
			'listing_url'    => '',
			'next_url'       => '',
			'pages_crawled'  => 0,
			'started_at'     => 0,
			'updated_at'     => 0,
		);
	}

	/**
	 * Get crawl progress.
	 *
	 * @return array<string, mixed>
	 */
	public static function get_progress(): array {
		$progress = get_option( self::OPTION_PROGRESS, self::get_progress_defaults() );

		return wp_parse_args( is_array( $progress ) ? $progress : array(), self::get_progress_defaults() );
	}

	/**
	 * Update crawl progress.
	 *
	 * @param array<string, mixed> $data Progress data.
	 * @return void
	 */
	public static function update_progress( array $data ): void {
		$progress = self::get_progress();
		$progress = array_merge( $progress, $data );
		$progress['updated_at'] = time();

		update_option( self::OPTION_PROGRESS, $progress, false );
	}

	/**
	 * Store crawl result for a row.
	 *
	 * @param int                   $row_index Row index.
	 * @param array<string, mixed>  $row Original row.
	 * @param array<string, string> $extracted Extracted data.
	 * @param string                $error Error message.
	 * @return void
	 */
	public static function store_result( int $row_index, array $row, array $extracted, string $error = '' ): void {
		$results = get_option( self::OPTION_RESULTS, array() );

		if ( ! is_array( $results ) ) {
			$results = array();
		}

		$results[ $row_index ] = array(
			'original_form_number'  => $row['Form Number'] ?? '',
			'original_form_title'   => $row['Form Title'] ?? '',
			'form_url'              => $row['Form URL'] ?? '',
			'extracted_form_number' => $extracted['extracted_form_number'] ?? '',
			'case_type'             => $extracted['case_type'] ?? '',
			'legal_action'          => $extracted['legal_action'] ?? '',
			'original_pdf_urls'     => $extracted['original_pdf_urls'] ?? '',
			'resolved_pdf_urls'     => $extracted['resolved_pdf_urls'] ?? '',
			'pdf_filenames'         => $extracted['pdf_filenames'] ?? '',
			'error'                 => $error,
		);

		update_option( self::OPTION_RESULTS, $results, false );

		if ( '' !== $error ) {
			self::store_error( $row_index, $row['Form URL'] ?? '', $error );
		}
	}

	/**
	 * Store crawl error.
	 *
	 * @param int    $row_index Row index.
	 * @param string $url URL.
	 * @param string $error Error message.
	 * @return void
	 */
	public static function store_error( int $row_index, string $url, string $error ): void {
		$errors = get_option( self::OPTION_ERRORS, array() );

		if ( ! is_array( $errors ) ) {
			$errors = array();
		}

		$errors[] = array(
			'row'   => $row_index,
			'url'   => $url,
			'error' => $error,
		);

		update_option( self::OPTION_ERRORS, $errors, false );
	}

	/**
	 * Get crawl errors.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function get_errors(): array {
		$errors = get_option( self::OPTION_ERRORS, array() );

		return is_array( $errors ) ? $errors : array();
	}

	/**
	 * Get combined export rows.
	 *
	 * @return array<int, array<string, string>>
	 */
	public static function get_export_rows(): array {
		$rows    = self::get_rows();
		$results = get_option( self::OPTION_RESULTS, array() );

		if ( ! is_array( $results ) ) {
			$results = array();
		}

		$export = array();

		foreach ( $rows as $index => $row ) {
			$result = $results[ $index ] ?? array();

			$export[] = array(
				'Original Form Number'  => $row['Form Number'] ?? '',
				'Original Form Title'   => $row['Form Title'] ?? '',
				'Form URL'              => $row['Form URL'] ?? '',
				'Extracted Form Number' => $result['extracted_form_number'] ?? '',
				'Case Type'             => $result['case_type'] ?? '',
				'Legal Action'          => $result['legal_action'] ?? '',
				'Original PDF URLs'     => $result['original_pdf_urls'] ?? '',
				'Resolved PDF URLs'     => $result['resolved_pdf_urls'] ?? '',
				'PDF Filenames'         => $result['pdf_filenames'] ?? '',
			);
		}

		return $export;
	}

	/**
	 * Append activity log entry.
	 *
	 * @param string $message Log message.
	 * @return void
	 */
	public static function add_log_entry( string $message ): void {
		$log = get_option( self::OPTION_LOG, array() );

		if ( ! is_array( $log ) ) {
			$log = array();
		}

		$log[] = array(
			'time'    => current_time( 'H:i:s' ),
			'message' => sanitize_text_field( $message ),
		);

		if ( count( $log ) > 100 ) {
			$log = array_slice( $log, -100 );
		}

		update_option( self::OPTION_LOG, $log, false );
	}

	/**
	 * Get activity log entries.
	 *
	 * @return array<int, array<string, string>>
	 */
	public static function get_log_entries(): array {
		$log = get_option( self::OPTION_LOG, array() );

		return is_array( $log ) ? $log : array();
	}

	/**
	 * Set export file path.
	 *
	 * @param string $path File path.
	 * @return void
	 */
	public static function set_export_file( string $path ): void {
		update_option( self::OPTION_EXPORT, $path, false );
	}

	/**
	 * Get export file path.
	 *
	 * @return string
	 */
	public static function get_export_file(): string {
		return (string) get_option( self::OPTION_EXPORT, '' );
	}

	/**
	 * Reset crawl session data.
	 *
	 * @return void
	 */
	public static function reset_session_data(): void {
		delete_option( self::OPTION_ROWS );
		delete_option( self::OPTION_RESULTS );
		delete_option( self::OPTION_ERRORS );
		delete_option( self::OPTION_PROGRESS );
		delete_option( self::OPTION_LOG );
		delete_option( self::OPTION_EXPORT );
		delete_option( self::OPTION_VISITED );
	}
}
