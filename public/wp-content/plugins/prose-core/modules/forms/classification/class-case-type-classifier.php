<?php
/**
 * Detect case type from PDF title, filename, header, and body text.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Forms\Classification;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Case_Type_Classifier
 */
final class Case_Type_Classifier {

	/**
	 * Keyword rules: pattern => case type label.
	 *
	 * @var array<string, string>
	 */
	private const RULES = array(
		'UNCONTESTED DIVORCE'           => 'Uncontested Divorce',
		'CONTESTED DIVORCE'             => 'Contested Divorce',
		'DIVORCE WITH CHILDREN'         => 'Divorce With Children',
		'WITH MINOR CHILD'              => 'Divorce With Children',
		'WITH CHILDREN'                 => 'Divorce With Children',
		'WITHOUT CHILDREN'              => 'Divorce Without Children',
		'NO MINOR CHILD'                => 'Divorce Without Children',
		'POST[- ]DIVORCE'               => 'Post Divorce',
		'POST JUDGMENT'                 => 'Post Divorce',
		'ORDER OF PROTECTION'           => 'Orders of Protection',
		'ORDERS OF PROTECTION'          => 'Orders of Protection',
		'FAMILY OFFENSE'                => 'Family Offense',
		'CHILD SUPPORT MODIFICATION'    => 'Child Support Modification',
		'CHILD SUPPORT ENFORCEMENT'     => 'Child Support Enforcement',
		'CHILD SUPPORT'                 => 'Child Support',
		'CUSTODY'                       => 'Child Custody',
		'VISITATION'                    => 'Visitation',
		'PATERNITY'                     => 'Paternity',
	);

	/**
	 * Classify case type.
	 *
	 * @param array<string, mixed> $ctx Context.
	 * @return array{value: string, confidence: int, source: string}
	 */
	public function classify( array $ctx ): array {
		$sources = array(
			array(
				'text'       => strtoupper( (string) ( $ctx['title'] ?? '' ) ),
				'confidence' => 95,
				'source'     => Classification_Result::SOURCE_PDF_CONTENT,
			),
			array(
				'text'       => strtoupper( (string) ( $ctx['filename'] ?? '' ) ),
				'confidence' => 85,
				'source'     => Classification_Result::SOURCE_PDF_FILENAME,
			),
			array(
				'text'       => strtoupper( (string) ( $ctx['text'] ?? '' ) ),
				'confidence' => 90,
				'source'     => Classification_Result::SOURCE_PDF_CONTENT,
			),
		);

		$best = Classification_Result::empty();

		foreach ( $sources as $source ) {
			$match = $this->match_rules( $source['text'] );

			if ( '' !== $match ) {
				$candidate = Classification_Result::make( $match, $source['confidence'], $source['source'] );
				$best      = Classification_Result::best_of( $best, $candidate );
			}
		}

		// Form code heuristics.
		$form_code = strtoupper( (string) ( $ctx['form_code'] ?? '' ) );

		if ( preg_match( '/^UD-\d+/', $form_code ) ) {
			$code_guess = Classification_Result::make(
				'Uncontested Divorce',
				75,
				Classification_Result::SOURCE_PDF_FILENAME
			);
			$best = Classification_Result::best_of( $best, $code_guess );
		}

		if ( '' === $best['value'] && ! empty( $ctx['csv_case_type'] ) ) {
			$best = Classification_Result::make(
				sanitize_text_field( (string) $ctx['csv_case_type'] ),
				60,
				Classification_Result::SOURCE_CSV_IMPORT
			);
		}

		return $best;
	}

	/**
	 * Match keyword rules against text.
	 *
	 * @param string $text Uppercase text.
	 * @return string
	 */
	private function match_rules( string $text ): string {
		if ( '' === $text ) {
			return '';
		}

		foreach ( self::RULES as $pattern => $label ) {
			if ( preg_match( '/' . $pattern . '/i', $text ) ) {
				return $label;
			}
		}

		if ( str_contains( $text, 'DIVORCE' ) ) {
			return 'Divorce';
		}

		return '';
	}
}
