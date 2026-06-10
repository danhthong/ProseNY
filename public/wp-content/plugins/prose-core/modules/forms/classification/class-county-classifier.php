<?php
/**
 * Detect county from PDF text or filename.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Forms\Classification;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class County_Classifier
 */
final class County_Classifier {

	/**
	 * Supported counties.
	 *
	 * @var array<string, string> Pattern fragment => display name.
	 */
	private const COUNTIES = array(
		'NEW YORK'    => 'New York',
		'KINGS'       => 'Kings',
		'QUEENS'      => 'Queens',
		'BRONX'       => 'Bronx',
		'RICHMOND'    => 'Richmond',
		'NASSAU'      => 'Nassau',
		'SUFFOLK'     => 'Suffolk',
		'WESTCHESTER' => 'Westchester',
	);

	/**
	 * Classify county.
	 *
	 * @param array<string, mixed> $ctx Context.
	 * @return array{value: string, confidence: int, source: string}
	 */
	public function classify( array $ctx ): array {
		$text     = strtoupper( (string) ( $ctx['text'] ?? '' ) );
		$filename = strtoupper( (string) ( $ctx['filename'] ?? '' ) );
		$compact  = (string) preg_replace( '/[^A-Z0-9]/', '', $text );

		foreach ( self::COUNTIES as $pattern => $name ) {
			if ( preg_match( '/COUNTY OF ' . preg_quote( $pattern, '/' ) . '\b/', $text ) ) {
				return Classification_Result::make( $name, 100, Classification_Result::SOURCE_PDF_CONTENT );
			}
		}

		foreach ( self::COUNTIES as $pattern => $name ) {
			$compact_name = (string) preg_replace( '/[^A-Z0-9]/', '', $pattern );

			// Only the "COUNTY OF <name>" construct is reliable. Matching
			// "<name> COUNTY" would misfire on "STATE OF NEW YORK / COUNTY OF".
			if ( str_contains( $text, 'COUNTY OF ' . $pattern )
				|| str_contains( $compact, 'COUNTYOF' . $compact_name ) ) {
				return Classification_Result::make( $name, 90, Classification_Result::SOURCE_PDF_CONTENT );
			}
		}

		foreach ( self::COUNTIES as $pattern => $name ) {
			if ( str_contains( $filename, $pattern ) || str_contains( $filename, strtoupper( $name ) ) ) {
				return Classification_Result::make( $name, 80, Classification_Result::SOURCE_PDF_FILENAME );
			}
		}

		$csv = (string) ( $ctx['csv_county'] ?? '' );

		if ( '' !== $csv ) {
			return Classification_Result::make( sanitize_text_field( $csv ), 60, Classification_Result::SOURCE_CSV_IMPORT );
		}

		return Classification_Result::empty();
	}
}
