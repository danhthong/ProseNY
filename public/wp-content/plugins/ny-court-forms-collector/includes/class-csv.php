<?php

namespace NYCourtFormsCollector\Includes;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * CSV parsing, validation, and storage.
 */
class CSV {

	public const OPTION_ROWS    = 'nycfc_csv_rows';
	public const OPTION_RESULTS = 'nycfc_crawl_results';
	public const OPTION_ERRORS  = 'nycfc_crawl_errors';
	public const OPTION_PROGRESS = 'nycfc_crawl_progress';
	public const OPTION_LOG     = 'nycfc_activity_log';
	public const OPTION_EXPORT  = 'nycfc_export_file';

	/**
	 * Required CSV columns.
	 *
	 * @var string[]
	 */
	private const REQUIRED_COLUMNS = [ 'Form Number', 'Form Title', 'Form URL' ];

	/**
	 * Handle uploaded CSV file.
	 *
	 * @param array $file Uploaded file from $_FILES.
	 * @return array|\WP_Error
	 */
	public static function handle_upload( array $file ) {
		if ( empty( $file['tmp_name'] ) || ! is_uploaded_file( $file['tmp_name'] ) ) {
			return new \WP_Error( 'upload_error', __( 'No CSV file uploaded.', 'ny-court-forms-collector' ) );
		}

		if ( UPLOAD_ERR_OK !== (int) $file['error'] ) {
			return new \WP_Error( 'upload_error', __( 'CSV upload failed.', 'ny-court-forms-collector' ) );
		}

		$rows = self::parse_csv( $file['tmp_name'] );

		if ( is_wp_error( $rows ) ) {
			return $rows;
		}

		if ( empty( $rows ) ) {
			return new \WP_Error( 'empty_csv', __( 'CSV contains no valid rows.', 'ny-court-forms-collector' ) );
		}

		self::reset_session_data();
		update_option( self::OPTION_ROWS, $rows, false );

		self::init_progress( count( $rows ) );
		self::add_log_entry( sprintf(
			/* translators: %d: number of rows */
			__( 'Uploaded CSV with %d rows.', 'ny-court-forms-collector' ),
			count( $rows )
		) );

		return [
			'total_rows' => count( $rows ),
			'message'    => __( 'CSV uploaded successfully.', 'ny-court-forms-collector' ),
		];
	}

	/**
	 * Parse CSV file.
	 *
	 * @param string $file_path File path.
	 * @return array|\WP_Error
	 */
	public static function parse_csv( string $file_path ) {
		if ( ! is_readable( $file_path ) ) {
			return new \WP_Error( 'file_error', __( 'CSV file is not readable.', 'ny-court-forms-collector' ) );
		}

		$handle = fopen( $file_path, 'rb' );

		if ( false === $handle ) {
			return new \WP_Error( 'file_open', __( 'Could not open CSV file.', 'ny-court-forms-collector' ) );
		}

		$headers_raw = fgetcsv( $handle, 0, ',' );

		if ( false === $headers_raw || empty( $headers_raw ) ) {
			fclose( $handle );
			return new \WP_Error( 'header_error', __( 'CSV headers are missing.', 'ny-court-forms-collector' ) );
		}

		$headers = array_map(
			static function ( $header ) {
				return trim( preg_replace( '/^\xEF\xBB\xBF/', '', (string) $header ) );
			},
			$headers_raw
		);

		$missing = array_diff( self::REQUIRED_COLUMNS, $headers );

		if ( ! empty( $missing ) ) {
			fclose( $handle );
			return new \WP_Error(
				'missing_columns',
				sprintf(
					/* translators: %s: comma-separated column names */
					__( 'Missing required columns: %s', 'ny-court-forms-collector' ),
					implode( ', ', $missing )
				)
			);
		}

		$header_map = array_flip( $headers );
		$rows       = [];

		while ( ( $data = fgetcsv( $handle, 0, ',' ) ) !== false ) {
			if ( empty( array_filter( $data, static fn( $value ) => '' !== trim( (string) $value ) ) ) ) {
				continue;
			}

			if ( count( $data ) !== count( $headers ) ) {
				continue;
			}

			$row = [
				'Form Number' => sanitize_text_field( trim( (string) $data[ $header_map['Form Number'] ] ) ),
				'Form Title'  => sanitize_text_field( trim( (string) $data[ $header_map['Form Title'] ] ) ),
				'Form URL'    => esc_url_raw( trim( (string) $data[ $header_map['Form URL'] ] ) ),
			];

			if ( empty( $row['Form URL'] ) || ! filter_var( $row['Form URL'], FILTER_VALIDATE_URL ) ) {
				continue;
			}

			$rows[] = $row;
		}

		fclose( $handle );

		return $rows;
	}

	/**
	 * Get stored CSV rows.
	 *
	 * @return array<int, array<string, string>>
	 */
	public static function get_rows(): array {
		$rows = get_option( self::OPTION_ROWS, [] );

		return is_array( $rows ) ? $rows : [];
	}

	/**
	 * Initialize crawl progress.
	 *
	 * @param int $total_rows Total row count.
	 */
	public static function init_progress( int $total_rows ): void {
		$progress = [
			'total_rows'     => $total_rows,
			'processed_rows' => 0,
			'success_rows'   => 0,
			'failed_rows'    => 0,
			'current_row'    => 0,
			'current_url'    => '',
			'crawl_status'   => 'idle',
			'started_at'     => 0,
			'updated_at'     => time(),
		];

		update_option( self::OPTION_PROGRESS, $progress, false );
	}

	/**
	 * Get crawl progress.
	 *
	 * @return array<string, mixed>
	 */
	public static function get_progress(): array {
		$defaults = [
			'total_rows'     => 0,
			'processed_rows' => 0,
			'success_rows'   => 0,
			'failed_rows'    => 0,
			'current_row'    => 0,
			'current_url'    => '',
			'crawl_status'   => 'idle',
			'started_at'     => 0,
			'updated_at'     => 0,
		];

		$progress = get_option( self::OPTION_PROGRESS, $defaults );

		return wp_parse_args( is_array( $progress ) ? $progress : [], $defaults );
	}

	/**
	 * Update crawl progress.
	 *
	 * @param array<string, mixed> $data Progress data.
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
	 * @param int                  $row_index Row index.
	 * @param array<string, mixed> $row Original row.
	 * @param array<string, string> $extracted Extracted data.
	 * @param string               $error Error message.
	 */
	public static function store_result( int $row_index, array $row, array $extracted, string $error = '' ): void {
		$results = get_option( self::OPTION_RESULTS, [] );

		if ( ! is_array( $results ) ) {
			$results = [];
		}

		$results[ $row_index ] = [
			'original_form_number' => $row['Form Number'] ?? '',
			'original_form_title'  => $row['Form Title'] ?? '',
			'form_url'             => $row['Form URL'] ?? '',
			'form_number_detail'   => $extracted['form_number_detail'] ?? '',
			'case_type'            => $extracted['case_type'] ?? '',
			'legal_action'         => $extracted['legal_action'] ?? '',
			'pdf_urls'             => $extracted['pdf_urls'] ?? '',
			'error'                => $error,
		];

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
	 */
	public static function store_error( int $row_index, string $url, string $error ): void {
		$errors = get_option( self::OPTION_ERRORS, [] );

		if ( ! is_array( $errors ) ) {
			$errors = [];
		}

		$errors[] = [
			'row'   => $row_index,
			'url'   => $url,
			'error' => $error,
		];

		update_option( self::OPTION_ERRORS, $errors, false );
	}

	/**
	 * Get crawl errors.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function get_errors(): array {
		$errors = get_option( self::OPTION_ERRORS, [] );

		return is_array( $errors ) ? $errors : [];
	}

	/**
	 * Get combined export rows.
	 *
	 * @return array<int, array<string, string>>
	 */
	public static function get_export_rows(): array {
		$rows    = self::get_rows();
		$results = get_option( self::OPTION_RESULTS, [] );

		if ( ! is_array( $results ) ) {
			$results = [];
		}

		$export = [];

		foreach ( $rows as $index => $row ) {
			$result = $results[ $index ] ?? [
				'form_number_detail' => '',
				'case_type'          => '',
				'legal_action'       => '',
				'pdf_urls'           => '',
				'error'              => '',
			];

			$export[] = [
				'Original Form Number' => $row['Form Number'] ?? '',
				'Original Form Title'  => $row['Form Title'] ?? '',
				'Form URL'               => $row['Form URL'] ?? '',
				'Extracted Form Number'  => $result['form_number_detail'] ?? '',
				'Case Type'              => $result['case_type'] ?? '',
				'Legal Action'           => $result['legal_action'] ?? '',
				'PDF URLs'               => $result['pdf_urls'] ?? '',
				'Error'                  => $result['error'] ?? '',
			];
		}

		return $export;
	}

	/**
	 * Append activity log entry.
	 *
	 * @param string $message Log message.
	 */
	public static function add_log_entry( string $message ): void {
		$log = get_option( self::OPTION_LOG, [] );

		if ( ! is_array( $log ) ) {
			$log = [];
		}

		$log[] = [
			'time'    => current_time( 'H:i:s' ),
			'message' => sanitize_text_field( $message ),
		];

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
		$log = get_option( self::OPTION_LOG, [] );

		return is_array( $log ) ? $log : [];
	}

	/**
	 * Set export file path.
	 *
	 * @param string $path File path.
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
	 */
	public static function reset_session_data(): void {
		delete_option( self::OPTION_ROWS );
		delete_option( self::OPTION_RESULTS );
		delete_option( self::OPTION_ERRORS );
		delete_option( self::OPTION_PROGRESS );
		delete_option( self::OPTION_LOG );
		delete_option( self::OPTION_EXPORT );
	}
}
