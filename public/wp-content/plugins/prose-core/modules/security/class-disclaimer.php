<?php
/**
 * Legal disclaimer for user-facing CourtFlow surfaces.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Security;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Disclaimer
 */
final class Disclaimer {

	/**
	 * Plain-text disclaimer.
	 *
	 * @return string
	 */
	public static function text(): string {
		/**
		 * Filter the CourtFlow legal disclaimer text.
		 *
		 * @param string $text Disclaimer copy.
		 */
		return (string) apply_filters(
			'prose_disclaimer_text',
			__(
				'CourtFlow AI provides procedural navigation and form assistance only. It is not a law firm and does not provide legal advice. Verify all filings with the court before submission.',
				'prose-core'
			)
		);
	}

	/**
	 * Render the disclaimer as HTML.
	 *
	 * @return string
	 */
	public static function render_html(): string {
		return sprintf(
			'<div class="prose-disclaimer" role="note"><p>%s</p></div>',
			esc_html( self::text() )
		);
	}
}
