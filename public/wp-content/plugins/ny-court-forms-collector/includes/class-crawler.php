<?php
/**
 * Crawler Class for extracting data from web pages using WordPress HTTP API
 *
 * @package NYCFC
 */

declare( strict_types = 1 );

namespace NYCFC;

/**
 * Class to handle web page crawling and data extraction.
 */
class Crawler {

	/**
	 * Timeout for HTTP requests in seconds.
	 *
	 * @var int
	 */
	const TIMEOUT = 30;

	/**
	 * User agent string.
	 *
	 * @var string
	 */
	const USER_AGENT = 'Mozilla/5.0';

	/**
	 * Extract data from a single URL.
	 *
	 * @param string $url Page URL to crawl.
	 * @return array Extracted data or error data.
	 */
	public function crawl_url( string $url ): array {
		if ( empty( $url ) || ! filter_var( $url, FILTER_VALIDATE_URL ) ) {
			return array(
				'error'              => true,
				'form_number_detail' => 'ERROR',
				'case_type'          => 'ERROR',
				'legal_action'       => 'ERROR',
				'pdf_urls'           => 'ERROR',
				'message'            => __( 'Invalid URL', 'ny-court-forms-collector' ),
			);
		}

		// Use WordPress HTTP API.
		$response = wp_remote_get( esc_url_raw( $url ), array(
			'timeout'     => self::TIMEOUT,
			'user-agent'  => self::USER_AGENT,
			'httpversion' => '1.1',
		) );

		if ( is_wp_error( $response ) ) {
			return array(
				'error'              => true,
				'form_number_detail' => 'ERROR',
				'case_type'          => 'ERROR',
				'legal_action'       => 'ERROR',
				'pdf_urls'           => 'ERROR',
				'message'            => $response->get_error_message(),
			);
		}

		$http_code = (int) wp_remote_retrieve_response_code( $response );

		if ( 200 !== $http_code ) {
			return array(
				'error'              => true,
				'form_number_detail' => 'ERROR',
				'case_type'          => 'ERROR',
				'legal_action'       => 'ERROR',
				'pdf_urls'           => 'ERROR',
				'message'            => sprintf( __( 'HTTP Error: %d', 'ny-court-forms-collector' ), $http_code ),
			);
		}

		$body = wp_remote_retrieve_body( $response );

		return $this->extract_data_from_html( $body, $url );
	}

	/**
	 * Extract data from HTML content.
	 *
	 * @param string $html HTML content.
	 * @param string $url Page URL (for error context).
	 * @return array Extracted data.
	 */
	private function extract_data_from_html( string $html, string $url ): array {
		if ( empty( $html ) ) {
			return array(
				'error'              => true,
				'form_number_detail' => 'ERROR',
				'case_type'          => 'ERROR',
				'legal_action'       => 'ERROR',
				'pdf_urls'           => 'ERROR',
				'message'            => __( 'Empty response', 'ny-court-forms-collector' ),
			);
		}

		libxml_use_internal_errors( true );

		$dom = new \DOMDocument();
		if ( ! $dom->loadHTML( mb_convert_encoding( $html, 'HTML-ENTITIES', 'UTF-8' ) ) ) {
			return array(
				'error'              => true,
				'form_number_detail' => 'ERROR',
				'case_type'          => 'ERROR',
				'legal_action'       => 'ERROR',
				'pdf_urls'           => 'ERROR',
				'message'            => __( 'Failed to parse HTML', 'ny-court-forms-collector' ),
			);
		}

		$xpath = new \DOMXPath( $dom );

		$data = array(
			'error'              => false,
			'form_number_detail' => self::get_text_by_selector( $xpath, '.form-details-sidebar .field--name-field-form-number .field__item' ),
			'case_type'          => self::get_multiple_texts( $xpath, '.form-details-sidebar .field--name-field-case-type .field__items .field__item' ),
			'legal_action'       => self::get_multiple_texts( $xpath, '.form-details-sidebar .field--name-field-legal-action .field__items .field__item' ),
			'pdf_urls'           => self::get_multiple_links( $xpath, '.field--name-field-file-set .field--name-field-files .field__item a' ),
		);

		return $data;
	}

	/**
	 * Get text content by CSS selector.
	 *
	 * @param \DOMXPath $xpath DOMXPath instance.
	 * @param string    $selector CSS selector.
	 * @return string Extracted text or empty string.
	 */
	private static function get_text_by_selector( \DOMXPath $xpath, string $selector ): string {
		$xpath_query = self::css_to_xpath( $selector );
		$nodes = $xpath->query( $xpath_query );

		if ( ! $nodes || $nodes->length === 0 ) {
			return '';
		}

		return trim( $nodes->item( 0 )->textContent );
	}

	/**
	 * Get multiple text values by CSS selector.
	 *
	 * @param \DOMXPath $xpath DOMXPath instance.
	 * @param string    $selector CSS selector.
	 * @return string Comma-separated values or empty string.
	 */
	private static function get_multiple_texts( \DOMXPath $xpath, string $selector ): string {
		$xpath_query = self::css_to_xpath( $selector );
		$nodes = $xpath->query( $xpath_query );

		if ( ! $nodes || $nodes->length === 0 ) {
			return '';
		}

		$values = array();
		foreach ( $nodes as $node ) {
			$text = trim( $node->textContent );
			if ( ! empty( $text ) ) {
				$values[] = $text;
			}
		}

		return ! empty( $values ) ? implode( ', ', $values ) : '';
	}

	/**
	 * Get multiple links by CSS selector.
	 *
	 * @param \DOMXPath $xpath DOMXPath instance.
	 * @param string    $selector CSS selector.
	 * @return string Pipe-separated URLs or empty string.
	 */
	private static function get_multiple_links( \DOMXPath $xpath, string $selector ): string {
		$xpath_query = self::css_to_xpath( $selector );
		$nodes = $xpath->query( $xpath_query );

		if ( ! $nodes || $nodes->length === 0 ) {
			return '';
		}

		$urls = array();
		foreach ( $nodes as $node ) {
			$url = trim( $node->getAttribute( 'href' ) );
			if ( ! empty( $url ) ) {
				$urls[] = esc_url_raw( $url );
			}
		}

		return ! empty( $urls ) ? implode( '|', $urls ) : '';
	}

	/**
	 * Convert CSS selector to XPath.
	 *
	 * @param string $selector CSS selector.
	 * @return string XPath query.
	 */
	private static function css_to_xpath( string $selector ): string {
		$selector = trim( $selector );

		if ( empty( $selector ) ) {
			return '';
		}

		// Split by spaces for descendant selectors.
		$parts = preg_split( '/\s+/', $selector );
		$xpath_parts = array();

		foreach ( $parts as $part ) {
			if ( empty( $part ) ) {
				continue;
			}

			// Handle class selectors: .class-name
			if ( strpos( $part, '.' ) === 0 ) {
				$class_name = substr( $part, 1 );
				$xpath_parts[] = "//*[contains(concat(' ', normalize-space(@class), ' '), ' {$class_name} ')]";
			}
			// Handle ID selectors: #id-name
			elseif ( strpos( $part, '#' ) === 0 ) {
				$id_name = substr( $part, 1 );
				$xpath_parts[] = "//*[@id='{$id_name}']";
			}
			// Handle element names
			else {
				$xpath_parts[] = strtolower( $part );
			}
		}

		return implode( '//', $xpath_parts );
	}
}
