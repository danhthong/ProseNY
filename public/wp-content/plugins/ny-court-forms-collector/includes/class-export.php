<?php
/**
 * Export Utility Class for generating output CSV files
 *
 * @package NYCFC
 */

declare( strict_types = 1 );

namespace NYCFC;

/**
 * Class to handle export functionality.
 */
class Export {

	/**
	 * Generate output CSV from processed data.
	 *
	 * @param array $data Array of processed form data.
	 * @return string File path to generated CSV.
	 */
	public function generate_csv( array $data ): string {
		if ( empty( $data ) ) {
			return '';
		}

		$upload_dir = wp_upload_dir();
		$target_dir = $upload_dir['path'];
		
		if ( ! file_exists( $target_dir ) ) {
			wp_mkdir_p( $target_dir );
		}

		$filename = 'ny-court-forms-export-' . gmdate( 'Ymd-His' ) . '.csv';
		$filepath = trailingslashit( $target_dir ) . $filename;

		// Open file handle.
		$handle = fopen( $filepath, 'w' );

		if ( ! $handle ) {
			return '';
		}

		// Write header row.
		fputcsv( $handle, array(
			__( 'Original Form Number', 'ny-court-forms-collector' ),
			__( 'Original Form Title', 'ny-court-forms-collector' ),
			__( 'Form URL', 'ny-court-forms-collector' ),
			__( 'Extracted Form Number', 'ny-court-forms-collector' ),
			__( 'Case Type', 'ny-court-forms-collector' ),
			__( 'Legal Action', 'ny-court-forms-collector' ),
			__( 'PDF URLs', 'ny-court-forms-collector' ),
		) );

		// Write data rows.
		foreach ( $data as $row ) {
			fputcsv( $handle, array(
				$row['original_form_number'],
				$row['original_form_title'],
				$row['form_url'],
				$row['form_number_detail'] ?? '',
				$row['case_type'] ?? '',
				$row['legal_action'] ?? '',
				$row['pdf_urls'] ?? '',
			) );
		}

		fclose( $handle );

		return $filepath;
	}

	/**
	 * Send file for download.
	 *
	 * @param string $filepath File path.
	 * @return void
	 */
	public function send_download( string $filepath ): void {
		if ( ! file_exists( $filepath ) ) {
			wp_send_json_error( array(
				'message' => __( 'Export file not found.', 'ny-court-forms-collector' ),
			) );
		}

		$filename = basename( $filepath );

		header( 'Content-Description: File Transfer' );
		header( 'Content-Type: text/csv' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'Expires: 0' );
		header( 'Cache-Control: must-revalidate' );
		header( 'Pragma: public' );

		readfile( $filepath );
		exit;
	}
}
