<?php

namespace NYCourtFormsCollector\Includes;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Web crawler and HTML extractor.
 */
class Crawler {

	/**
	 * Process next batch of URLs.
	 *
	 * @return array|\WP_Error
	 */
	public static function process_batch() {
		$progress = CSV::get_progress();
		$status   = $progress['crawl_status'] ?? 'idle';

		if ( 'running' !== $status ) {
			return [
				'progress' => $progress,
				'complete' => in_array( $status, [ 'completed', 'failed' ], true ),
			];
		}

		$rows           = CSV::get_rows();
		$total_rows     = count( $rows );
		$processed_rows = (int) ( $progress['processed_rows'] ?? 0 );
		$success_rows   = (int) ( $progress['success_rows'] ?? 0 );
		$failed_rows    = (int) ( $progress['failed_rows'] ?? 0 );
		$batch_size     = defined( 'NYCFC_BATCH_SIZE' ) ? (int) NYCFC_BATCH_SIZE : 5;
		$end_index      = min( $processed_rows + $batch_size, $total_rows );

		if ( $processed_rows >= $total_rows ) {
			CSV::update_progress(
				[
					'crawl_status' => 'completed',
					'current_row'  => 0,
					'current_url'  => '',
				]
			);
			CSV::add_log_entry( __( 'Crawl completed.', 'ny-court-forms-collector' ) );
			Export::generate_file();

			return [
				'progress' => CSV::get_progress(),
				'complete' => true,
			];
		}

		for ( $index = $processed_rows; $index < $end_index; $index++ ) {
			$row     = $rows[ $index ];
			$row_num = $index + 1;
			$url     = $row['Form URL'] ?? '';

			CSV::update_progress(
				[
					'current_row' => $row_num,
					'current_url' => $url,
				]
			);

			CSV::add_log_entry(
				sprintf(
					/* translators: %d: row number */
					__( 'Processing row %d', 'ny-court-forms-collector' ),
					$row_num
				)
			);

			$result = self::crawl_url( $url );

			if ( is_wp_error( $result ) ) {
				$error_message = $result->get_error_message();
				$extracted     = [
					'form_number_detail' => 'ERROR',
					'case_type'          => 'ERROR',
					'legal_action'       => 'ERROR',
					'pdf_urls'           => 'ERROR',
				];

				CSV::store_result( $index, $row, $extracted, $error_message );
				CSV::add_log_entry( $error_message );
				$failed_rows++;
			} else {
				$extracted = $result;
				$pdf_count = empty( $extracted['pdf_urls'] ) ? 0 : count( explode( '|', $extracted['pdf_urls'] ) );

				if ( $pdf_count > 0 ) {
					CSV::add_log_entry(
						sprintf(
							/* translators: %d: number of PDF links */
							__( 'Extracted %d PDF links', 'ny-court-forms-collector' ),
							$pdf_count
						)
					);
				}

				CSV::store_result( $index, $row, $extracted );
				CSV::add_log_entry( __( 'Success', 'ny-court-forms-collector' ) );
				$success_rows++;
			}

			$processed_rows++;

			CSV::update_progress(
				[
					'processed_rows' => $processed_rows,
					'success_rows'   => $success_rows,
					'failed_rows'    => $failed_rows,
					'current_row'    => $row_num,
					'current_url'    => $url,
				]
			);
		}

		$progress = CSV::get_progress();

		if ( (int) $progress['processed_rows'] >= $total_rows ) {
			CSV::update_progress(
				[
					'crawl_status' => 'completed',
					'current_row'  => 0,
					'current_url'  => '',
				]
			);
			CSV::add_log_entry( __( 'Crawl completed.', 'ny-court-forms-collector' ) );
			Export::generate_file();
			$progress['crawl_status'] = 'completed';
		}

		return [
			'progress' => $progress,
			'complete' => 'completed' === ( $progress['crawl_status'] ?? '' ),
		];
	}

	/**
	 * Crawl a single URL and extract fields.
	 *
	 * @param string $url Form URL.
	 * @return array<string, string>|\WP_Error
	 */
	public static function crawl_url( string $url ) {
		if ( empty( $url ) || ! filter_var( $url, FILTER_VALIDATE_URL ) ) {
			return new \WP_Error( 'invalid_url', __( 'Invalid URL.', 'ny-court-forms-collector' ) );
		}

		$response = wp_remote_get(
			$url,
			[
				'timeout'     => 30,
				'redirection' => 5,
				'user-agent'  => 'Mozilla/5.0',
			]
		);

		if ( is_wp_error( $response ) ) {
			return new \WP_Error( 'request_failed', $response->get_error_message() );
		}

		$status_code = (int) wp_remote_retrieve_response_code( $response );

		if ( 200 !== $status_code ) {
			return new \WP_Error(
				'http_error',
				sprintf(
					/* translators: %d: HTTP status code */
					__( 'HTTP response code %d.', 'ny-court-forms-collector' ),
					$status_code
				)
			);
		}

		$html = wp_remote_retrieve_body( $response );

		if ( '' === trim( $html ) ) {
			return new \WP_Error( 'empty_body', __( 'Empty response body.', 'ny-court-forms-collector' ) );
		}

		return self::extract_fields( $html );
	}

	/**
	 * Extract required fields from HTML.
	 *
	 * @param string $html HTML content.
	 * @return array<string, string>
	 */
	public static function extract_fields( string $html ): array {
		$dom = new \DOMDocument();

		libxml_use_internal_errors( true );
		$dom->loadHTML( '<?xml encoding="UTF-8">' . $html );
		libxml_clear_errors();

		$xpath = new \DOMXPath( $dom );

		$form_number = self::get_text_by_selector(
			$xpath,
			'.form-details-sidebar .field--name-field-form-number .field__item'
		);

		$case_types = self::get_multiple_texts(
			$xpath,
			'.form-details-sidebar .field--name-field-case-type .field__items .field__item'
		);

		$legal_actions = self::get_multiple_texts(
			$xpath,
			'.form-details-sidebar .field--name-field-legal-action .field__items .field__item'
		);

		$pdf_urls = self::get_multiple_links(
			$xpath,
			'.field--name-field-file-set .field--name-field-files .field__item .field--name-field-file a'
		);

		return [
			'form_number_detail' => $form_number,
			'case_type'          => implode( ', ', $case_types ),
			'legal_action'       => implode( ', ', $legal_actions ),
			'pdf_urls'           => implode( '|', $pdf_urls ),
		];
	}

	/**
	 * Get text content for a CSS selector.
	 *
	 * @param \DOMXPath $xpath XPath instance.
	 * @param string    $selector CSS selector.
	 * @return string
	 */
	public static function get_text_by_selector( \DOMXPath $xpath, string $selector ): string {
		$query = self::css_to_xpath( $selector );
		$nodes = $xpath->query( $query );

		if ( false === $nodes || 0 === $nodes->length ) {
			return '';
		}

		return trim( preg_replace( '/\s+/', ' ', $nodes->item( 0 )->textContent ?? '' ) );
	}

	/**
	 * Get multiple text values for a CSS selector.
	 *
	 * @param \DOMXPath $xpath XPath instance.
	 * @param string    $selector CSS selector.
	 * @return string[]
	 */
	public static function get_multiple_texts( \DOMXPath $xpath, string $selector ): array {
		$query = self::css_to_xpath( $selector );
		$nodes = $xpath->query( $query );
		$texts = [];

		if ( false === $nodes ) {
			return $texts;
		}

		foreach ( $nodes as $node ) {
			$text = trim( preg_replace( '/\s+/', ' ', $node->textContent ?? '' ) );

			if ( '' !== $text ) {
				$texts[] = $text;
			}
		}

		return $texts;
	}

	/**
	 * Get multiple link href values for a CSS selector.
	 *
	 * @param \DOMXPath $xpath XPath instance.
	 * @param string    $selector CSS selector.
	 * @return string[]
	 */
	public static function get_multiple_links( \DOMXPath $xpath, string $selector ): array {
		$query = self::css_to_xpath( $selector );
		$nodes = $xpath->query( $query );
		$links = [];

		if ( false === $nodes ) {
			return $links;
		}

		foreach ( $nodes as $node ) {
			if ( ! $node instanceof \DOMElement ) {
				continue;
			}

			$href = trim( $node->getAttribute( 'href' ) );

			if ( '' !== $href ) {
				$links[] = esc_url_raw( $href );
			}
		}

		return array_values( array_unique( array_filter( $links ) ) );
	}

	/**
	 * Convert a limited CSS selector to XPath.
	 *
	 * Supports tag, .class, #id, and descendant selectors.
	 *
	 * @param string $selector CSS selector.
	 * @return string
	 */
	public static function css_to_xpath( string $selector ): string {
		$parts  = preg_split( '/\s+/', trim( $selector ) ) ?: [];
		$xpaths = [];

		foreach ( $parts as $part ) {
			$xpaths[] = self::css_part_to_xpath( $part );
		}

		return '//' . implode( '//', $xpaths );
	}

	/**
	 * Convert a single CSS selector part to XPath.
	 *
	 * @param string $part CSS selector part.
	 * @return string
	 */
	private static function css_part_to_xpath( string $part ): string {
		$part = trim( $part );

		if ( str_starts_with( $part, '#' ) ) {
			$id = substr( $part, 1 );
			return "*[@id='" . self::escape_xpath_literal( $id ) . "']";
		}

		if ( str_starts_with( $part, '.' ) ) {
			$class = substr( $part, 1 );
			return "*[contains(concat(' ', normalize-space(@class), ' '), ' " . self::escape_xpath_literal( $class ) . " ')]";
		}

		if ( preg_match( '/^[a-zA-Z][a-zA-Z0-9\-_]*/', $part, $matches ) ) {
			return $matches[0];
		}

		return '*';
	}

	/**
	 * Escape XPath literal values.
	 *
	 * @param string $value Value to escape.
	 * @return string
	 */
	private static function escape_xpath_literal( string $value ): string {
		if ( ! str_contains( $value, "'" ) ) {
			return $value;
		}

		if ( ! str_contains( $value, '"' ) ) {
			return str_replace( "'", "\\'", $value );
		}

		return str_replace( [ "'", '"' ], '', $value );
	}
}
