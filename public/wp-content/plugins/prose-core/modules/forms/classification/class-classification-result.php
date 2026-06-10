<?php
/**
 * Classification result value object.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Forms\Classification;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Classification_Result
 */
final class Classification_Result {

	public const SOURCE_PDF_CONTENT  = 'pdf_content';
	public const SOURCE_PDF_FILENAME = 'pdf_filename';
	public const SOURCE_CSV_IMPORT   = 'csv_import';
	public const SOURCE_AI_INFERENCE = 'ai_inference';
	public const SOURCE_COMBINED     = 'combined_signals';

	public const CONFIDENCE_THRESHOLD = 70;

	/**
	 * Build a result array.
	 *
	 * @param string $value      Detected value.
	 * @param int    $confidence Confidence 0-100.
	 * @param string $source     Data source constant.
	 * @return array{value: string, confidence: int, source: string}
	 */
	public static function make( string $value, int $confidence, string $source ): array {
		return array(
			'value'      => $value,
			'confidence' => max( 0, min( 100, $confidence ) ),
			'source'     => $source,
		);
	}

	/**
	 * Empty result.
	 *
	 * @return array{value: string, confidence: int, source: string}
	 */
	public static function empty(): array {
		return self::make( '', 0, self::SOURCE_AI_INFERENCE );
	}

	/**
	 * Whether confidence meets auto-assign threshold.
	 *
	 * @param array{value?: string, confidence?: int, source?: string} $result Result.
	 * @return bool
	 */
	public static function is_confident( array $result ): bool {
		return (int) ( $result['confidence'] ?? 0 ) >= self::CONFIDENCE_THRESHOLD;
	}

	/**
	 * Pick the higher-confidence result (PDF wins on tie when sources differ).
	 *
	 * @param array{value?: string, confidence?: int, source?: string} $a First result.
	 * @param array{value?: string, confidence?: int, source?: string} $b Second result.
	 * @return array{value: string, confidence: int, source: string}
	 */
	public static function best_of( array $a, array $b ): array {
		$a_conf = (int) ( $a['confidence'] ?? 0 );
		$b_conf = (int) ( $b['confidence'] ?? 0 );

		if ( $a_conf > $b_conf ) {
			return $a;
		}

		if ( $b_conf > $a_conf ) {
			return $b;
		}

		$priority = array(
			self::SOURCE_PDF_CONTENT  => 5,
			self::SOURCE_COMBINED     => 4,
			self::SOURCE_PDF_FILENAME => 3,
			self::SOURCE_CSV_IMPORT   => 2,
			self::SOURCE_AI_INFERENCE => 1,
		);

		$a_pri = $priority[ $a['source'] ?? '' ] ?? 0;
		$b_pri = $priority[ $b['source'] ?? '' ] ?? 0;

		return $a_pri >= $b_pri ? $a : $b;
	}
}
