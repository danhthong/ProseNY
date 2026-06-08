<?php
/**
 * Detect court from PDF text, filename, or CSV hints.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Forms\Classification;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Court_Classifier
 */
final class Court_Classifier {

	public const SUPREME = 'Supreme Court';
	public const FAMILY  = 'Family Court';

	/**
	 * Unsupported NY courts (for detection only).
	 *
	 * @var array<string, string>
	 */
	private const UNSUPPORTED_PATTERNS = array(
		'CIVIL COURT OF THE CITY OF NEW YORK' => 'Civil Court',
		'COUNTY COURT'                        => 'County Court',
		'SURROGATE\'S COURT'                  => 'Surrogate\'s Court',
		'CRIMINAL COURT'                      => 'Criminal Court',
		'COURT OF CLAIMS'                     => 'Court of Claims',
	);

	/**
	 * Classify court.
	 *
	 * @param array<string, mixed> $ctx Context (text, filename, csv_court).
	 * @return array{value: string, confidence: int, source: string, supported: bool}
	 */
	public function classify( array $ctx ): array {
		$text     = strtoupper( (string) ( $ctx['text'] ?? '' ) );
		$filename = strtoupper( (string) ( $ctx['filename'] ?? '' ) );
		$csv      = (string) ( $ctx['csv_court'] ?? '' );

		if ( str_contains( $text, 'SUPREME COURT OF THE STATE OF NEW YORK' ) ) {
			return $this->result( self::SUPREME, 100, Classification_Result::SOURCE_PDF_CONTENT, true );
		}

		if ( str_contains( $text, 'FAMILY COURT OF THE STATE OF NEW YORK' ) ) {
			return $this->result( self::FAMILY, 100, Classification_Result::SOURCE_PDF_CONTENT, true );
		}

		foreach ( self::UNSUPPORTED_PATTERNS as $pattern => $label ) {
			if ( str_contains( $text, $pattern ) ) {
				return $this->result( 'Unsupported Court', 100, Classification_Result::SOURCE_PDF_CONTENT, false );
			}
		}

		$content_guess = $this->guess_from_string( $text, Classification_Result::SOURCE_PDF_CONTENT, 85 );

		if ( '' !== $content_guess['value'] ) {
			return $content_guess;
		}

		$filename_guess = $this->guess_from_string( $filename, Classification_Result::SOURCE_PDF_FILENAME, 80 );

		if ( '' !== $filename_guess['value'] ) {
			return $filename_guess;
		}

		if ( '' !== $csv ) {
			$csv_upper = strtoupper( $csv );

			if ( str_contains( $csv_upper, 'SUPREME' ) ) {
				return $this->result( self::SUPREME, 60, Classification_Result::SOURCE_CSV_IMPORT, true );
			}

			if ( str_contains( $csv_upper, 'FAMILY' ) ) {
				return $this->result( self::FAMILY, 60, Classification_Result::SOURCE_CSV_IMPORT, true );
			}
		}

		return $this->result( '', 0, Classification_Result::SOURCE_AI_INFERENCE, true );
	}

	/**
	 * Guess court from a string.
	 *
	 * @param string $haystack  Uppercase string.
	 * @param string $source    Source constant.
	 * @param int    $confidence Base confidence.
	 * @return array{value: string, confidence: int, source: string, supported: bool}
	 */
	private function guess_from_string( string $haystack, string $source, int $confidence ): array {
		if ( str_contains( $haystack, 'SUPREME COURT' ) || preg_match( '/\bUD-\d+/i', $haystack ) ) {
			return $this->result( self::SUPREME, $confidence, $source, true );
		}

		if ( str_contains( $haystack, 'FAMILY COURT' ) || preg_match( '/\bFC-\d+/i', $haystack ) ) {
			return $this->result( self::FAMILY, $confidence, $source, true );
		}

		return $this->result( '', 0, Classification_Result::SOURCE_AI_INFERENCE, true );
	}

	/**
	 * Build court result with supported flag.
	 *
	 * @param string $value      Court name.
	 * @param int    $confidence Confidence.
	 * @param string $source     Source.
	 * @param bool   $supported  Whether court is supported.
	 * @return array{value: string, confidence: int, source: string, supported: bool}
	 */
	private function result( string $value, int $confidence, string $source, bool $supported ): array {
		return array_merge(
			Classification_Result::make( $value, $confidence, $source ),
			array( 'supported' => $supported )
		);
	}
}
