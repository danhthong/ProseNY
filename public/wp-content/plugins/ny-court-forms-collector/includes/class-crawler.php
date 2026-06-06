<?php
/**
 * Web crawler and HTML extractor (collect + enrich phases).
 *
 * @package NYCourtFormsCollector
 */

namespace NYCourtFormsCollector\Includes;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Crawler
 */
class Crawler {

	/**
	 * Maximum listing pages to crawl.
	 */
	private const MAX_PAGES = 100;

	/**
	 * Process next batch (collect or enrich depending on phase).
	 *
	 * @return array|\WP_Error
	 */
	public static function process_batch() {
		$progress = CSV::get_progress();
		$status   = $progress['crawl_status'] ?? 'idle';

		if ( 'running' !== $status ) {
			return array(
				'progress' => $progress,
				'complete' => in_array( $status, array( 'completed', 'failed' ), true ),
			);
		}

		$phase = $progress['phase'] ?? 'collect';

		if ( 'collect' === $phase ) {
			return self::collect_batch();
		}

		return self::enrich_batch();
	}

	/**
	 * Collect form links from listing pages.
	 *
	 * @return array|\WP_Error
	 */
	private static function collect_batch() {
		$progress = CSV::get_progress();
		$next_url = (string) ( $progress['next_url'] ?? '' );

		if ( '' === $next_url ) {
			return self::finalize_collect_phase();
		}

		$visited = CSV::get_visited_pages();

		if ( in_array( $next_url, $visited, true ) ) {
			CSV::add_log_entry( __( 'Already visited listing page; finishing collection.', 'ny-court-forms-collector' ) );
			return self::finalize_collect_phase();
		}

		if ( (int) ( $progress['pages_crawled'] ?? 0 ) >= self::MAX_PAGES ) {
			CSV::add_log_entry( __( 'Reached maximum page limit; finishing collection.', 'ny-court-forms-collector' ) );
			return self::finalize_collect_phase();
		}

		CSV::update_progress(
			array(
				'current_url' => $next_url,
			)
		);

		CSV::add_log_entry(
			sprintf(
				/* translators: %s: page URL */
				__( 'Collecting listing page: %s', 'ny-court-forms-collector' ),
				$next_url
			)
		);

		$html = Http::get_html( $next_url );

		if ( is_wp_error( $html ) ) {
			CSV::add_log_entry( $html->get_error_message() );
			CSV::update_progress(
				array(
					'crawl_status' => 'failed',
				)
			);

			return new \WP_Error( 'collect_failed', $html->get_error_message() );
		}

		$links    = self::extract_form_links( $html, $next_url );
		$added    = CSV::append_links( $links );
		$next     = self::find_next_page_url( $html, $next_url );
		$pages    = (int) ( $progress['pages_crawled'] ?? 0 ) + 1;

		CSV::mark_page_visited( $next_url );

		CSV::add_log_entry(
			sprintf(
				/* translators: 1: forms found, 2: new forms added */
				__( 'Found %1$d forms (%2$d new).', 'ny-court-forms-collector' ),
				count( $links ),
				$added
			)
		);

		if ( '' === $next || in_array( $next, CSV::get_visited_pages(), true ) ) {
			return self::finalize_collect_phase(
				array(
					'pages_crawled' => $pages,
				)
			);
		}

		CSV::update_progress(
			array(
				'pages_crawled' => $pages,
				'next_url'      => $next,
			)
		);

		return array(
			'progress' => CSV::get_progress(),
			'complete' => false,
		);
	}

	/**
	 * Switch from collect to enrich phase.
	 *
	 * @param array<string, mixed> $extra Extra progress fields.
	 * @return array
	 */
	private static function finalize_collect_phase( array $extra = array() ): array {
		$rows = CSV::get_rows();
		$total = count( $rows );

		CSV::update_progress(
			array_merge(
				array(
					'phase'          => 'enrich',
					'next_url'       => '',
					'total_rows'     => $total,
					'processed_rows' => 0,
					'success_rows'   => 0,
					'failed_rows'    => 0,
					'current_row'    => 0,
					'current_url'    => '',
				),
				$extra
			)
		);

		CSV::add_log_entry(
			sprintf(
				/* translators: %d: number of forms */
				__( 'Collection complete. %d forms ready for enrichment.', 'ny-court-forms-collector' ),
				$total
			)
		);

		if ( 0 === $total ) {
			CSV::update_progress(
				array(
					'crawl_status' => 'completed',
				)
			);
			Export::generate_file();
		}

		return array(
			'progress' => CSV::get_progress(),
			'complete' => 0 === $total,
		);
	}

	/**
	 * Enrich collected form rows.
	 *
	 * @return array|\WP_Error
	 */
	private static function enrich_batch() {
		$rows           = CSV::get_rows();
		$total_rows     = count( $rows );
		$progress       = CSV::get_progress();
		$processed_rows = (int) ( $progress['processed_rows'] ?? 0 );
		$success_rows   = (int) ( $progress['success_rows'] ?? 0 );
		$failed_rows    = (int) ( $progress['failed_rows'] ?? 0 );
		$batch_size     = defined( 'NYCFC_BATCH_SIZE' ) ? (int) NYCFC_BATCH_SIZE : 5;
		$end_index      = min( $processed_rows + $batch_size, $total_rows );

		if ( $processed_rows >= $total_rows ) {
			CSV::update_progress(
				array(
					'crawl_status' => 'completed',
					'current_row'  => 0,
					'current_url'  => '',
				)
			);
			CSV::add_log_entry( __( 'Enrichment completed.', 'ny-court-forms-collector' ) );
			Export::generate_file();

			return array(
				'progress' => CSV::get_progress(),
				'complete' => true,
			);
		}

		for ( $index = $processed_rows; $index < $end_index; $index++ ) {
			$row     = $rows[ $index ];
			$row_num = $index + 1;
			$url     = $row['Form URL'] ?? '';

			CSV::update_progress(
				array(
					'current_row' => $row_num,
					'current_url' => $url,
				)
			);

			CSV::add_log_entry(
				sprintf(
					/* translators: %d: row number */
					__( 'Enriching row %d', 'ny-court-forms-collector' ),
					$row_num
				)
			);

			$result = self::crawl_url( $url );

			if ( is_wp_error( $result ) ) {
				$error_message = $result->get_error_message();
				$extracted     = array(
					'extracted_form_number' => '',
					'case_type'             => '',
					'legal_action'          => '',
					'original_pdf_urls'     => '',
					'resolved_pdf_urls'     => '',
					'pdf_filenames'         => '',
				);

				CSV::store_result( $index, $row, $extracted, $error_message );
				CSV::add_log_entry( $error_message );
				++$failed_rows;
			} else {
				$extracted = $result;
				CSV::store_result( $index, $row, $extracted );
				CSV::add_log_entry( __( 'Success', 'ny-court-forms-collector' ) );
				++$success_rows;
			}

			++$processed_rows;

			CSV::update_progress(
				array(
					'processed_rows' => $processed_rows,
					'success_rows'   => $success_rows,
					'failed_rows'    => $failed_rows,
					'current_row'    => $row_num,
					'current_url'    => $url,
				)
			);
		}

		$progress = CSV::get_progress();

		if ( (int) $progress['processed_rows'] >= $total_rows ) {
			CSV::update_progress(
				array(
					'crawl_status' => 'completed',
					'current_row'  => 0,
					'current_url'  => '',
				)
			);
			CSV::add_log_entry( __( 'Enrichment completed.', 'ny-court-forms-collector' ) );
			Export::generate_file();
			$progress['crawl_status'] = 'completed';
		}

		return array(
			'progress' => $progress,
			'complete' => 'completed' === ( $progress['crawl_status'] ?? '' ),
		);
	}

	/**
	 * Crawl a single form URL and extract enriched fields.
	 *
	 * @param string $url Form URL.
	 * @return array<string, string>|\WP_Error
	 */
	public static function crawl_url( string $url ) {
		if ( empty( $url ) || ! filter_var( $url, FILTER_VALIDATE_URL ) ) {
			return new \WP_Error( 'invalid_url', __( 'Invalid URL.', 'ny-court-forms-collector' ) );
		}

		$html = Http::get_html( $url );

		if ( is_wp_error( $html ) ) {
			return $html;
		}

		$extracted = self::extract_fields( $html, $url );

		return self::resolve_pdf_fields( $extracted );
	}

	/**
	 * Extract form links from a listing page.
	 *
	 * @param string $html     Page HTML.
	 * @param string $page_url Page URL for resolving relative links.
	 * @return array<int, array<string, string>>
	 */
	public static function extract_form_links( string $html, string $page_url ): array {
		$dom = new \DOMDocument();

		libxml_use_internal_errors( true );
		$dom->loadHTML( '<?xml encoding="UTF-8">' . $html );
		libxml_clear_errors();

		$xpath = new \DOMXPath( $dom );
		$forms = array();

		$row_query = '//*[contains(concat(" ", normalize-space(@class), " "), " teaser-pills ") and contains(concat(" ", normalize-space(@class), " "), " views-row ")]';
		$rows      = $xpath->query( $row_query );

		if ( false === $rows ) {
			return $forms;
		}

		foreach ( $rows as $row ) {
			$number_node = $xpath->query(
				'.//*[contains(concat(" ", normalize-space(@class), " "), " views-field-field-form-number ")]//*[contains(concat(" ", normalize-space(@class), " "), " field-content ")]',
				$row
			);

			$link_node = $xpath->query(
				'.//*[contains(concat(" ", normalize-space(@class), " "), " views-field-title ")]//*[contains(concat(" ", normalize-space(@class), " "), " field-content ")]//a',
				$row
			);

			if ( false === $link_node || 0 === $link_node->length ) {
				continue;
			}

			$anchor = $link_node->item( 0 );

			if ( ! $anchor instanceof \DOMElement ) {
				continue;
			}

			$href = trim( $anchor->getAttribute( 'href' ) );

			if ( '' === $href ) {
				continue;
			}

			$form_number = '';

			if ( false !== $number_node && $number_node->length > 0 ) {
				$form_number = trim( preg_replace( '/\s+/', ' ', $number_node->item( 0 )->textContent ?? '' ) );
			}

			$forms[] = array(
				'Form Number' => $form_number,
				'Form Title'  => trim( preg_replace( '/\s+/', ' ', $anchor->textContent ?? '' ) ),
				'Form URL'    => self::absolute_url( $href, $page_url ),
			);
		}

		return $forms;
	}

	/**
	 * Find next pagination URL.
	 *
	 * @param string $html     Page HTML.
	 * @param string $page_url Current page URL.
	 * @return string
	 */
	public static function find_next_page_url( string $html, string $page_url ): string {
		$dom = new \DOMDocument();

		libxml_use_internal_errors( true );
		$dom->loadHTML( '<?xml encoding="UTF-8">' . $html );
		libxml_clear_errors();

		$xpath = new \DOMXPath( $dom );
		$nodes = $xpath->query( '//a[@rel="next"]' );

		if ( false === $nodes || 0 === $nodes->length ) {
			return '';
		}

		$node = $nodes->item( 0 );

		if ( ! $node instanceof \DOMElement ) {
			return '';
		}

		$href = trim( $node->getAttribute( 'href' ) );

		return '' !== $href ? self::absolute_url( $href, $page_url ) : '';
	}

	/**
	 * Resolve a relative URL against a base URL.
	 *
	 * @param string $href     Relative or absolute href.
	 * @param string $base_url Base URL.
	 * @return string
	 */
	public static function absolute_url( string $href, string $base_url ): string {
		$href = trim( $href );

		if ( '' === $href ) {
			return '';
		}

		if ( preg_match( '#^https?://#i', $href ) ) {
			return esc_url_raw( $href );
		}

		$base_parts = wp_parse_url( $base_url );

		if ( ! is_array( $base_parts ) ) {
			return esc_url_raw( $href );
		}

		$scheme    = $base_parts['scheme'] ?? 'https';
		$host      = $base_parts['host'] ?? '';
		$port      = isset( $base_parts['port'] ) ? ':' . $base_parts['port'] : '';
		$authority = $scheme . '://' . $host . $port;
		$base_path = $base_parts['path'] ?? '/';

		if ( '' === $base_path ) {
			$base_path = '/';
		}

		if ( str_starts_with( $href, '//' ) ) {
			return esc_url_raw( $scheme . ':' . $href );
		}

		// Query-only reference: keep the base path, replace the query. This is
		// how the NY Courts pager links work (e.g. "?...&page=1").
		if ( str_starts_with( $href, '?' ) ) {
			return esc_url_raw( $authority . $base_path . $href );
		}

		// Fragment-only reference: keep base path and query.
		if ( str_starts_with( $href, '#' ) ) {
			$base_query = isset( $base_parts['query'] ) ? '?' . $base_parts['query'] : '';
			return esc_url_raw( $authority . $base_path . $base_query . $href );
		}

		if ( str_starts_with( $href, '/' ) ) {
			return esc_url_raw( $authority . $href );
		}

		if ( str_ends_with( $base_path, '/' ) ) {
			$dir = $base_path;
		} else {
			$slash = strrpos( $base_path, '/' );
			$dir   = false === $slash ? '/' : substr( $base_path, 0, $slash + 1 );
		}

		return esc_url_raw( $authority . trailingslashit( $dir ) . ltrim( $href, '/' ) );
	}

	/**
	 * Extract required fields from form page HTML.
	 *
	 * @param string $html Form page HTML.
	 * @param string $url  Form page URL.
	 * @return array<string, string>
	 */
	public static function extract_fields( string $html, string $url ): array {
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
			'.form-details-sidebar .field--name-field-case-type .field__item'
		);

		$legal_actions = self::get_multiple_texts(
			$xpath,
			'.form-details-sidebar .field--name-field-legal-action .field__item'
		);

		$pdf_urls = self::get_multiple_links(
			$xpath,
			'.field--name-field-file-set .field--name-field-files .field--name-field-file a',
			$url
		);

		return array(
			'extracted_form_number' => $form_number,
			'case_type'             => implode( ', ', $case_types ),
			'legal_action'          => implode( ', ', $legal_actions ),
			'original_pdf_urls'     => implode( '|', $pdf_urls ),
			'resolved_pdf_urls'     => '',
			'pdf_filenames'         => '',
		);
	}

	/**
	 * Resolve PDF redirect URLs and filenames.
	 *
	 * @param array<string, string> $extracted Extracted data.
	 * @return array<string, string>
	 */
	private static function resolve_pdf_fields( array $extracted ): array {
		$original_urls = array_filter(
			array_map( 'trim', explode( '|', $extracted['original_pdf_urls'] ?? '' ) )
		);

		if ( empty( $original_urls ) ) {
			return $extracted;
		}

		$resolved = array();
		$names    = array();

		foreach ( $original_urls as $pdf_url ) {
			list( $final_url, $filename ) = Http::resolve_redirect( $pdf_url );
			$resolved[] = '' !== $final_url ? $final_url : $pdf_url;
			$names[]    = $filename;
		}

		$extracted['resolved_pdf_urls'] = implode( '|', $resolved );
		$extracted['pdf_filenames']     = implode( '|', $names );

		return $extracted;
	}

	/**
	 * Get text content for a CSS selector.
	 *
	 * @param \DOMXPath $xpath    XPath instance.
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
	 * @param \DOMXPath $xpath    XPath instance.
	 * @param string    $selector CSS selector.
	 * @return string[]
	 */
	public static function get_multiple_texts( \DOMXPath $xpath, string $selector ): array {
		$query = self::css_to_xpath( $selector );
		$nodes = $xpath->query( $query );
		$texts = array();

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
	 * @param \DOMXPath $xpath    XPath instance.
	 * @param string    $selector CSS selector.
	 * @param string    $base_url Base URL for relative links.
	 * @return string[]
	 */
	public static function get_multiple_links( \DOMXPath $xpath, string $selector, string $base_url ): array {
		$query = self::css_to_xpath( $selector );
		$nodes = $xpath->query( $query );
		$links = array();

		if ( false === $nodes ) {
			return $links;
		}

		foreach ( $nodes as $node ) {
			if ( ! $node instanceof \DOMElement ) {
				continue;
			}

			$href = trim( $node->getAttribute( 'href' ) );

			if ( '' !== $href ) {
				$links[] = self::absolute_url( $href, $base_url );
			}
		}

		return array_values( array_unique( array_filter( $links ) ) );
	}

	/**
	 * Convert a limited CSS selector to XPath.
	 *
	 * @param string $selector CSS selector.
	 * @return string
	 */
	public static function css_to_xpath( string $selector ): string {
		$parts  = preg_split( '/\s+/', trim( $selector ) ) ?: array();
		$xpaths = array();

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

		return str_replace( array( "'", '"' ), '', $value );
	}
}
