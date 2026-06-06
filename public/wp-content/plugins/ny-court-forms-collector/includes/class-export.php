<?php

namespace NYCourtFormsCollector\Includes;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Export crawl results to CSV file.
 */
class Export {

	/**
	 * Generate export CSV file on disk.
	 *
	 * @return string|\WP_Error File path.
	 */
	public static function generate_file() {
		$rows = CSV::get_export_rows();

		if ( empty( $rows ) ) {
			return new \WP_Error( 'no_data', __( 'No export data available.', 'ny-court-forms-collector' ) );
		}

		$upload_dir = wp_upload_dir();

		if ( ! empty( $upload_dir['error'] ) ) {
			return new \WP_Error( 'upload_dir', $upload_dir['error'] );
		}

		$export_dir = trailingslashit( $upload_dir['basedir'] ) . 'nycfc-exports';

		if ( ! wp_mkdir_p( $export_dir ) ) {
			return new \WP_Error( 'mkdir_failed', __( 'Could not create export directory.', 'ny-court-forms-collector' ) );
		}

		$filename = 'ny-court-forms-export-' . gmdate( 'Y-m-d-His' ) . '.csv';
		$filepath = trailingslashit( $export_dir ) . $filename;

		$handle = fopen( $filepath, 'wb' );

		if ( false === $handle ) {
			return new \WP_Error( 'file_open', __( 'Could not create export file.', 'ny-court-forms-collector' ) );
		}

		fwrite( $handle, "\xEF\xBB\xBF" );

		$headers = array_keys( $rows[0] );
		fputcsv( $handle, $headers );

		foreach ( $rows as $row ) {
			fputcsv( $handle, array_values( $row ) );
		}

		fclose( $handle );

		CSV::set_export_file( $filepath );
		CSV::add_log_entry( __( 'Export CSV generated.', 'ny-court-forms-collector' ) );

		return $filepath;
	}

	/**
	 * Stream export file to browser.
	 */
	public static function download_file(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'ny-court-forms-collector' ) );
		}

		check_admin_referer( 'nycfc_download_export' );

		$filepath = CSV::get_export_file();

		if ( empty( $filepath ) || ! file_exists( $filepath ) ) {
			$generated = self::generate_file();

			if ( is_wp_error( $generated ) ) {
				wp_die( esc_html( $generated->get_error_message() ) );
			}

			$filepath = $generated;
		}

		$filename = basename( $filepath );

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'Content-Length: ' . filesize( $filepath ) );

		$handle = fopen( $filepath, 'rb' );

		if ( false === $handle ) {
			wp_die( esc_html__( 'Could not read export file.', 'ny-court-forms-collector' ) );
		}

		while ( ! feof( $handle ) ) {
			echo fread( $handle, 8192 );

			if ( ob_get_level() > 0 ) {
				ob_flush();
			}

			flush();
		}

		fclose( $handle );
		exit;
	}

	/**
	 * Get export download URL.
	 *
	 * @return string
	 */
	public static function get_download_url(): string {
		return wp_nonce_url(
			admin_url( 'admin-post.php?action=nycfc_download_export' ),
			'nycfc_download_export'
		);
	}
}
