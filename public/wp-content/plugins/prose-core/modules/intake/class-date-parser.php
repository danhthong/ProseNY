<?php
/**
 * Date parser — shared normalization for intake date fields.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Intake;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Date_Parser
 */
final class Date_Parser {

	/**
	 * Parse user text into Y-m-d when possible.
	 *
	 * Supports ISO dates, US (MM/DD/YYYY), day-first (DD/MM/YYYY when day > 12),
	 * and natural-language month names.
	 *
	 * @param string $text Raw text.
	 * @return string|null
	 */
	public static function parse( string $text ): ?string {
		$text = trim( $text );

		if ( '' === $text ) {
			return null;
		}

		if ( preg_match( '/^(\d{4})-(\d{1,2})-(\d{1,2})$/', $text, $matches ) ) {
			return self::valid_ymd( (int) $matches[1], (int) $matches[2], (int) $matches[3] );
		}

		if ( preg_match( '#^(\d{1,2})[/-](\d{1,2})[/-](\d{4})$#', $text, $matches ) ) {
			$first  = (int) $matches[1];
			$second = (int) $matches[2];
			$year   = (int) $matches[3];

			if ( $first > 12 && $second >= 1 && $second <= 12 ) {
				return self::valid_ymd( $year, $second, $first );
			}

			if ( $second > 12 && $first >= 1 && $first <= 12 ) {
				return self::valid_ymd( $year, $first, $second );
			}

			if ( $first >= 1 && $first <= 12 && $second >= 1 && $second <= 31 ) {
				return self::valid_ymd( $year, $first, $second );
			}
		}

		if ( preg_match( '/^(\d{4})$/', $text, $matches ) ) {
			$year = (int) $matches[1];

			if ( $year >= 1900 && $year <= 2100 ) {
				return sprintf( '%04d-01-01', $year );
			}
		}

		$timestamp = strtotime( $text );

		if ( false !== $timestamp && preg_match( '/[a-zA-Z]{3,}/', $text ) ) {
			return gmdate( 'Y-m-d', $timestamp );
		}

		return null;
	}

	/**
	 * Extract marriage/separation dates from a free-form message.
	 *
	 * @param string $message Message.
	 * @return array<string, string>
	 */
	public static function extract_marriage_and_separation( string $message ): array {
		$facts = array();

		if ( preg_match( '/\b(?:were married|got married|married)\s+(?:on\s+)?([^.;,]+?)(?:\.|;|,|$|\s+and\b|\s+we\b)/i', $message, $matches ) ) {
			$parsed = self::parse( trim( $matches[1] ) );

			if ( null !== $parsed ) {
				$facts['marriage_date'] = $parsed;
			}
		}

		if ( ! isset( $facts['marriage_date'] ) && preg_match( '/\bmarried\s+(?:in\s+)?(\d{4})\b/i', $message, $matches ) ) {
			$parsed = self::parse( $matches[1] );

			if ( null !== $parsed ) {
				$facts['marriage_date'] = $parsed;
			}
		}

		if ( preg_match( '#\b(\d{1,2}[/-]\d{1,2}[/-]\d{4})\b#', $message, $matches ) ) {
			$parsed = self::parse( $matches[1] );

			if ( null !== $parsed && ! self::extract_child_birth_date( $message ) ) {
				$facts['marriage_date'] = $parsed;
			}
		}

		if ( preg_match( '/\b(?:separated|separation)\s+(?:on|since)\s+([^.;,]+?)(?:\.|;|,|$|\s+and\b)/i', $message, $matches ) ) {
			$parsed = self::parse( trim( $matches[1] ) );

			if ( null !== $parsed ) {
				$facts['separation_date'] = $parsed;
			}
		}

		return $facts;
	}

	/**
	 * Whether text contains a slash-form calendar date (DD/MM/YYYY or MM/DD/YYYY).
	 *
	 * @param string $text Raw text.
	 * @return bool
	 */
	public static function contains_slash_date( string $text ): bool {
		return (bool) preg_match( '#\b\d{1,2}[/-]\d{1,2}[/-]\d{4}\b#', trim( $text ) );
	}

	/**
	 * Parse the first embedded slash or ISO date from a sentence.
	 *
	 * @param string $message Message.
	 * @return string|null
	 */
	public static function extract_embedded_date( string $message ): ?string {
		if ( ! preg_match( '#\b(\d{1,2}[/-]\d{1,2}[/-]\d{4}|\d{4}-\d{1,2}-\d{1,2})\b#', $message, $matches ) ) {
			return null;
		}

		return self::parse( $matches[1] );
	}

	/**
	 * Extract a child birth date from natural language.
	 *
	 * @param string $message Message.
	 * @return string|null
	 */
	public static function extract_child_birth_date( string $message ): ?string {
		$mentions_birth = (bool) preg_match(
			'/\b(?:birthday|birth\s*date|date\s+of\s+birth|dob|born)\b/i',
			$message
		);

		$mentions_child = (bool) preg_match(
			'/\b(?:kid|child|children|son|daughter)\b.{0,50}\b(?:birthday|born|birth\s*date)\b/i',
			$message
		) || (bool) preg_match(
			'/\b(?:birthday|born|birth\s*date)\b.{0,50}\b(?:kid|child|son|daughter)\b/i',
			$message
		);

		if ( ! $mentions_birth && ! $mentions_child ) {
			return null;
		}

		return self::extract_embedded_date( $message );
	}

	/**
	 * Whether a stored date is only a year placeholder (YYYY-01-01).
	 *
	 * @param string $ymd Date string.
	 * @return bool
	 */
	public static function is_year_only_placeholder( string $ymd ): bool {
		return (bool) preg_match( '/^\d{4}-01-01$/', trim( $ymd ) );
	}

	/**
	 * Confidence score for a parsed date value.
	 *
	 * @param string $ymd Parsed Y-m-d value.
	 * @return float
	 */
	public static function confidence_for( string $ymd ): float {
		return self::is_year_only_placeholder( $ymd ) ? 0.82 : 0.95;
	}

	/**
	 * @param int $year  Year.
	 * @param int $month Month.
	 * @param int $day   Day.
	 * @return string|null
	 */
	private static function valid_ymd( int $year, int $month, int $day ): ?string {
		if ( ! checkdate( $month, $day, $year ) ) {
			return null;
		}

		return sprintf( '%04d-%02d-%02d', $year, $month, $day );
	}
}
