<?php
/**
 * Detect workflow stage from PDF content based on court type.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Forms\Classification;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Workflow_Classifier
 */
final class Workflow_Classifier {

	/**
	 * Supreme Court keyword rules.
	 *
	 * @var array<string, string>
	 */
	private const SUPREME_RULES = array(
		'SUMMONS WITH NOTICE'     => 'Commencement',
		'SUMMONS'                 => 'Commencement',
		'VERIFIED COMPLAINT'      => 'Commencement',
		'AFFIDAVIT OF SERVICE'    => 'Service',
		'PROOF OF SERVICE'        => 'Service',
		'ANSWER'                  => 'Response',
		'SETTLEMENT AGREEMENT'    => 'Settlement',
		'SEPARATION AGREEMENT'    => 'Settlement',
		'JUDGMENT OF DIVORCE'     => 'Judgment',
		'MOTION TO MODIFY'        => 'Post-Judgment',
		'POST[- ]JUDGMENT'        => 'Post-Judgment',
	);

	/**
	 * Family Court keyword rules.
	 *
	 * @var array<string, string>
	 */
	private const FAMILY_RULES = array(
		'VIOLATION PETITION'      => 'Enforcement',
		'SUPPLEMENTAL PETITION'   => 'Modification',
		'PETITION'                => 'Petition',
		'AFFIDAVIT OF SERVICE'    => 'Service',
		'PROOF OF SERVICE'        => 'Service',
		'HEARING'                 => 'Hearing',
		'ORDER'                   => 'Order',
	);

	/**
	 * Classify workflow stage.
	 *
	 * @param array<string, mixed> $ctx Context (text, title, filename, court).
	 * @return array{value: string, confidence: int, source: string}
	 */
	public function classify( array $ctx ): array {
		$court    = (string) ( $ctx['court'] ?? '' );
		$is_family = str_contains( strtoupper( $court ), 'FAMILY' );

		$rules = $is_family ? self::FAMILY_RULES : self::SUPREME_RULES;

		$sources = array(
			array(
				'text'       => strtoupper( (string) ( $ctx['title'] ?? '' ) ),
				'confidence' => 95,
				'source'     => Classification_Result::SOURCE_PDF_CONTENT,
			),
			array(
				'text'       => strtoupper( (string) ( $ctx['text'] ?? '' ) ),
				'confidence' => 90,
				'source'     => Classification_Result::SOURCE_PDF_CONTENT,
			),
			array(
				'text'       => strtoupper( (string) ( $ctx['filename'] ?? '' ) ),
				'confidence' => 80,
				'source'     => Classification_Result::SOURCE_PDF_FILENAME,
			),
		);

		$best = Classification_Result::empty();

		foreach ( $sources as $source ) {
			$match = $this->match_rules( $source['text'], $rules );

			if ( '' !== $match ) {
				$candidate = Classification_Result::make( $match, $source['confidence'], $source['source'] );
				$best      = Classification_Result::best_of( $best, $candidate );
			}
		}

		if ( '' === $best['value'] && ! empty( $ctx['csv_workflow_stage'] ) ) {
			$best = Classification_Result::make(
				sanitize_text_field( (string) $ctx['csv_workflow_stage'] ),
				60,
				Classification_Result::SOURCE_CSV_IMPORT
			);
		}

		return $best;
	}

	/**
	 * Match rules against text (longer patterns first).
	 *
	 * @param string               $text  Uppercase text.
	 * @param array<string, string> $rules Rules.
	 * @return string
	 */
	private function match_rules( string $text, array $rules ): string {
		if ( '' === $text ) {
			return '';
		}

		$keys = array_keys( $rules );
		usort(
			$keys,
			static function ( string $a, string $b ): int {
				return strlen( $b ) <=> strlen( $a );
			}
		);

		foreach ( $keys as $pattern ) {
			if ( preg_match( '/' . $pattern . '/i', $text ) ) {
				return $rules[ $pattern ];
			}
		}

		return '';
	}
}
