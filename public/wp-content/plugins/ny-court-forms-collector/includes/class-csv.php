<?php
/**
 * CSV Utility Class for handling CSV operations
 *
 * @package NYCFC
 */

declare( strict_types = 1 );

namespace NYCFC;

/**
 * Class to handle CSV file upload, reading, and processing.
 */
class CSV {

	/**
	 * Validate uploaded CSV file.
	 *
	 * @param array $file Upload file array from $_FILES.
	 * @return array
	 */
	public function validate_upload( array $file ): array {
		if ( empty( $file ) || ! is_array( $file ) ) {
			return array(
				'success' => false,
				'error'   => __( 'Invalid file upload.', 'ny-court-forms-collector' ),
			);
		}

		if ( isset( $file['error'] ) && $file['error'] !== UPLOAD_ERR_OK ) {
			switch ( $file['error'] ) {
				case UPLOAD_ERR_INI_SIZE:
					$error = __( 'File exceeds upload_max_filesize directive.', 'ny-court-forms-collector' );
					break;
				case UPLOAD_ERR_FORM_SIZE:
					$error = __( 'File exceeds MAX_FILE_SIZE directive.', 'ny-court-forms-collector' );
					break;
				case UPLOAD_ERR_PARTIAL:
					$error = __( 'File was only partially uploaded.', 'ny-court-forms-collector' );
					break;
				case UPLOAD_ERR_NO_FILE:
					$error = __( 'No file was uploaded.', 'ny-court-forms-collector' );
					break;
				case UPLOAD_ERR_NO_TMP_DIR:
					$error = __( 'Missing temporary directory.', 'ny-court-forms-collector' );
					break;
				case UPLOAD_ERR_CANT_WRITE:
					$error = __( 'Failed to write file to disk.', 'ny-court-forms-collector' );
					break;
				case UPLOAD_ERR_EXTENSION:
					$error = __( 'File upload stopped by extension.', 'ny-court-forms-collector' );
					break;
				default:
					$error = __( 'Unknown upload error.', 'ny-court-forms-collector' );
					break;
			}

			return array(
				'success' => false,
				'error'   => $error,
			);
		}

		$allowed_types = array( 'text/csv', 'text/plain', 'application/csv' );
		if ( ! in_array( $file['type'], $allowed_types, true ) ) {
			return array(
				'success' => false,
				'error'   => __( 'Invalid file type. Please upload a CSV file.', 'ny-court-forms-collector' ),
			);
		}

		if ( ! isset( $file['tmp_name'] ) || ! is_uploaded_file( $file['tmp_name'] ) ) {
			return array(
				'success' => false,
				'error'   => __( 'Invalid file upload.', 'ny-court-forms-collector' ),
			);
		}

		if ( ! $this->is_valid_csv( $file['tmp_name'] ) ) {
			return array(
				'success' => false,
				'error'   => __( 'File is not a valid CSV.', 'ny-court-forms-collector' ),
			);
		}

		return array(
			'success' => true,
			'file'    => $file,
		);
	}

	/**
	 * Check if file is a valid CSV.
	 *
	 * @param string $filepath File path.
	 * @return bool
	 */
	public function is_valid_csv( string $filepath ): bool {
		if ( ! file_exists( $filepath ) || ! is_readable( $filepath ) ) {
			return false;
		}

		$handle = fopen( $filepath, 'r' );
		if ( ! $handle ) {
			return false;
		}

		$header = fgetcsv( $handle, 1000, ',' );
		fclose( $handle );

		$required_columns = array( 'Form Number', 'Form Title', 'Form URL' );
		
		if ( empty( $header ) || ! is_array( $header ) ) {
			return false;
		}

		$header_lower = array_map( 'strtolower', $header );
		foreach ( $required_columns as $col ) {
			if ( ! in_array( strtolower( $col ), $header_lower, true ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Read CSV file and return rows.
	 *
	 * @param string $filepath File path.
	 * @return array
	 */
	public function read_csv( string $filepath ): array {
		$rows = array();
		
		if ( ! file_exists( $filepath ) || ! is_readable( $filepath ) ) {
			return $rows;
		}

		$handle = fopen( $filepath, 'r' );
		if ( ! $handle ) {
			return $rows;
		}

		$header = fgetcsv( $handle, 1000, ',' );
		if ( empty( $header ) ) {
			fclose( $handle );
			return $rows;
		}

		$column_map = array();
		foreach ( $header as $index => $col ) {
			$column_map[ strtolower( trim( $col ) ) ] = $index;
		}

		$form_number_idx = $column_map['form number'] ?? null;
		$form_title_idx  = $column_map['form title'] ?? null;
		$form_url_idx    = $column_map['form url'] ?? null;

		while ( ! feof( $handle ) ) {
			$row = fgetcsv( $handle, 1000, ',' );
			if ( empty( $row ) || count( $row ) < 3 ) {
				continue;
			}

			$row_data = array(
				'form_number' => isset( $row[ $form_number_idx ] ) ? trim( $row[ $form_number_idx ] ) : '',
				'form_title'  => isset( $row[ $form_title_idx ] ) ? trim( $row[ $form_title_idx ] ) : '',
				'form_url'    => isset( $row[ $form_url_idx ] ) ? trim( $row[ $form_url_idx ] ) : '',
			);

			if ( ! empty( $row_data['form_url'] ) ) {
				$rows[] = $row_data;
			}
		}

		fclose( $handle );
		return $rows;
	}

	/**
	 * Get column count from CSV.
	 *
	 * @param string $filepath File path.
	 * @return int
	 */
	public function get_column_count( string $filepath ): int {
		if ( ! file_exists( $filepath ) || ! is_readable( $filepath ) ) {
			return 0;
		}

		$handle = fopen( $filepath, 'r' );
		if ( ! $handle ) {
			return 0;
		}

		$header = fgetcsv( $handle, 1000, ',' );
		fclose( $handle );

		return empty( $header ) ? 0 : count( $header );
	}
}
