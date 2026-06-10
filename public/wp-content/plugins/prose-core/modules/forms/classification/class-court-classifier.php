<?php
/**
 * Detect court from PDF content with intelligent multi-signal fallback.
 *
 * Many NY court forms do not explicitly name the court (generic forms, cover
 * sheets, instructions, supporting documents). When the PDF content does not
 * state the court, this classifier falls back to a weighted scoring model that
 * combines the PDF title, detected case type, workflow packet membership, the
 * form code naming convention, and the workflow stage.
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
	 * Signal weights (scoring model priority). Higher beats lower on conflict.
	 */
	private const W_CONTENT  = 100;
	private const W_TITLE    = 90;
	private const W_CASETYPE = 80;
	private const W_PACKAGE  = 75;
	private const W_FORMCODE = 60;
	private const W_STAGE    = 50;
	private const W_CSV      = 40;

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
	 * Case type keyword => court. Matched with str_contains (most specific first).
	 *
	 * @var array<string, string>
	 */
	private const CASE_TYPE_COURT = array(
		'UNCONTESTED DIVORCE'      => self::SUPREME,
		'CONTESTED DIVORCE'        => self::SUPREME,
		'DIVORCE WITH CHILDREN'    => self::SUPREME,
		'DIVORCE WITHOUT CHILDREN' => self::SUPREME,
		'POST DIVORCE'             => self::SUPREME,
		'POST-DIVORCE'             => self::SUPREME,
		'MATRIMONIAL'              => self::SUPREME,
		'DIVORCE'                  => self::SUPREME,
		'CHILD SUPPORT'            => self::FAMILY,
		'CHILD CUSTODY'            => self::FAMILY,
		'CUSTODY'                  => self::FAMILY,
		'VISITATION'               => self::FAMILY,
		'PATERNITY'                => self::FAMILY,
		'FAMILY OFFENSE'           => self::FAMILY,
		'ORDERS OF PROTECTION'     => self::FAMILY,
		'ORDER OF PROTECTION'      => self::FAMILY,
	);

	/**
	 * Form code patterns: [ regex, court, confidence, label ]. First match wins.
	 *
	 * @var array<int, array{0: string, 1: string, 2: int, 3: string}>
	 */
	private const FORM_CODE_PATTERNS = array(
		array( '/^UCCJEA-?/i', self::FAMILY, 90, 'UCCJEA Custody Form' ),
		array( '/^UD-?\d/i', self::SUPREME, 90, 'UD Uncontested Divorce Series' ),
		array( '/^DRL-?/i', self::SUPREME, 90, 'DRL Domestic Relations Form' ),
		array( '/^GF-?\d/i', self::FAMILY, 75, 'GF General Family Court Form' ),
		array( '/^4-\d/i', self::FAMILY, 85, 'Article 4 Child Support Form' ),
		array( '/^5-\d/i', self::FAMILY, 85, 'Article 5 Paternity Form' ),
	);

	/**
	 * Strong title/content keyword => [ court, confidence ]. Most specific first.
	 *
	 * @var array<string, array{0: string, 1: int}>
	 */
	private const KEYWORD_COURT = array(
		'MATRIMONIAL'         => array( self::SUPREME, 95 ),
		'JUDGMENT OF DIVORCE' => array( self::SUPREME, 90 ),
		'FAMILY OFFENSE'      => array( self::FAMILY, 90 ),
		'ORDER OF PROTECTION' => array( self::FAMILY, 90 ),
		'CHILD SUPPORT'       => array( self::FAMILY, 85 ),
		'CUSTODY'             => array( self::FAMILY, 85 ),
		'VISITATION'          => array( self::FAMILY, 85 ),
		'PATERNITY'           => array( self::FAMILY, 85 ),
		'DIVORCE'             => array( self::SUPREME, 85 ),
	);

	/**
	 * Workflow-stage keyword => [ court, stage label ]. Most specific first.
	 *
	 * @var array<string, array{0: string, 1: string}>
	 */
	private const STAGE_COURT = array(
		'JUDGMENT OF DIVORCE'   => array( self::SUPREME, 'Judgment' ),
		'SUMMONS WITH NOTICE'   => array( self::SUPREME, 'Commencement' ),
		'VERIFIED COMPLAINT'    => array( self::SUPREME, 'Commencement' ),
		'SUMMONS'               => array( self::SUPREME, 'Commencement' ),
		'VIOLATION PETITION'    => array( self::FAMILY, 'Enforcement' ),
		'SUPPLEMENTAL PETITION' => array( self::FAMILY, 'Modification' ),
		'PETITION'              => array( self::FAMILY, 'Petition' ),
	);

	/**
	 * Classify court.
	 *
	 * @param array<string, mixed> $ctx Context. Recognized keys: text, title,
	 *                                  filename, form_code, case_type,
	 *                                  workflow_package, csv_court.
	 * @return array{value: string, confidence: int, source: string, supported: bool, signals: string[]}
	 */
	public function classify( array $ctx ): array {
		$text      = strtoupper( (string) ( $ctx['text'] ?? '' ) );
		$title     = strtoupper( (string) ( $ctx['title'] ?? '' ) );
		$filename  = strtoupper( (string) ( $ctx['filename'] ?? '' ) );
		$form_code = strtoupper( trim( (string) ( $ctx['form_code'] ?? '' ) ) );
		$case_type = (string) ( $ctx['case_type'] ?? '' );
		$package   = is_array( $ctx['workflow_package'] ?? null ) ? $ctx['workflow_package'] : array();
		$csv       = (string) ( $ctx['csv_court'] ?? '' );

		// Whitespace/punctuation-insensitive copy. Some PDFs split words with
		// per-glyph positioning (e.g. "FAMILY CO URT O F").
		$compact = (string) preg_replace( '/[^A-Z0-9]/', '', $text );

		// Priority 1: court explicitly named in PDF content (definitive).
		if ( str_contains( $text, 'SUPREME COURT OF THE STATE OF NEW YORK' ) || str_contains( $compact, 'SUPREMECOURTOFTHESTATEOFNEWYORK' ) ) {
			return $this->finalize( self::SUPREME, 100, Classification_Result::SOURCE_PDF_CONTENT, true, array( __( 'Supreme Court named in PDF', 'prose-core' ) ) );
		}

		if ( str_contains( $text, 'FAMILY COURT OF THE STATE OF NEW YORK' ) || str_contains( $compact, 'FAMILYCOURTOFTHESTATEOFNEWYORK' ) ) {
			return $this->finalize( self::FAMILY, 100, Classification_Result::SOURCE_PDF_CONTENT, true, array( __( 'Family Court named in PDF', 'prose-core' ) ) );
		}

		// Unsupported court explicitly named — do not override with fallback.
		foreach ( self::UNSUPPORTED_PATTERNS as $pattern => $label ) {
			$compact_pattern = (string) preg_replace( '/[^A-Z0-9]/', '', strtoupper( $pattern ) );

			if ( str_contains( $text, $pattern ) || ( '' !== $compact_pattern && str_contains( $compact, $compact_pattern ) ) ) {
				return $this->finalize(
					'Unsupported Court',
					100,
					Classification_Result::SOURCE_PDF_CONTENT,
					false,
					array(
						sprintf(
							/* translators: %s: court name */
							__( '%s named in PDF', 'prose-core' ),
							$label
						),
					)
				);
			}
		}

		// Fallback: gather weighted signals.
		$signals = array();

		// Generic "X Court" phrase in body content (weight 100, lower confidence).
		if ( str_contains( $text, 'SUPREME COURT' ) ) {
			$signals[] = $this->sig( self::SUPREME, 85, self::W_CONTENT, Classification_Result::SOURCE_PDF_CONTENT, __( '"Supreme Court" in PDF text', 'prose-core' ) );
		} elseif ( str_contains( $text, 'FAMILY COURT' ) ) {
			$signals[] = $this->sig( self::FAMILY, 85, self::W_CONTENT, Classification_Result::SOURCE_PDF_CONTENT, __( '"Family Court" in PDF text', 'prose-core' ) );
		}

		// PDF title keyword signal.
		$title_sig = $this->keyword_signal( $title, self::W_TITLE, Classification_Result::SOURCE_PDF_CONTENT, __( 'PDF title', 'prose-core' ) );

		if ( null !== $title_sig ) {
			$signals[] = $title_sig;
		}

		// Strong body-text keyword signal (e.g. MATRIMONIAL).
		$content_sig = $this->keyword_signal( $text, self::W_TITLE, Classification_Result::SOURCE_PDF_CONTENT, __( 'PDF keyword', 'prose-core' ) );

		if ( null !== $content_sig ) {
			$signals[] = $content_sig;
		}

		// Case type signal.
		if ( '' !== $case_type ) {
			$ct_court = $this->court_for_case_type( $case_type );

			if ( '' !== $ct_court ) {
				$signals[] = $this->sig(
					$ct_court,
					90,
					self::W_CASETYPE,
					Classification_Result::SOURCE_COMBINED,
					sprintf(
						/* translators: %s: case type */
						__( '%s case type', 'prose-core' ),
						$case_type
					)
				);
			}
		}

		// Workflow packet membership signal (strongest fallback).
		if ( '' !== $form_code && count( $package ) > 1 ) {
			$package_upper = array_map( 'strtoupper', $package );

			if ( in_array( $form_code, $package_upper, true ) ) {
				$pkg_court = '' !== $case_type ? $this->court_for_case_type( $case_type ) : '';

				if ( '' === $pkg_court ) {
					$pkg_court = $this->court_for_form_code( $form_code )['court'];
				}

				if ( '' !== $pkg_court ) {
					$signals[] = $this->sig( $pkg_court, 95, self::W_PACKAGE, Classification_Result::SOURCE_COMBINED, __( 'Member of workflow packet', 'prose-core' ) );
				}
			}
		}

		// Form code naming convention signal.
		$fc = $this->court_for_form_code( $form_code );

		if ( '' !== $fc['court'] ) {
			$signals[] = $this->sig( $fc['court'], $fc['confidence'], self::W_FORMCODE, Classification_Result::SOURCE_PDF_FILENAME, $fc['label'] );
		} elseif ( '' !== $filename ) {
			$fc_file = $this->court_for_form_code( $this->code_from_filename( $filename ) );

			if ( '' !== $fc_file['court'] ) {
				$signals[] = $this->sig( $fc_file['court'], $fc_file['confidence'], self::W_FORMCODE, Classification_Result::SOURCE_PDF_FILENAME, $fc_file['label'] );
			}
		}

		// Workflow stage signal (weakest).
		$stage = $this->stage_signal( $title . ' ' . $text );

		if ( null !== $stage ) {
			$signals[] = $this->sig(
				$stage['court'],
				70,
				self::W_STAGE,
				Classification_Result::SOURCE_COMBINED,
				sprintf(
					/* translators: %s: workflow stage */
					__( '%s workflow stage', 'prose-core' ),
					$stage['stage']
				)
			);
		}

		// CSV import hint (lowest priority external signal).
		if ( '' !== $csv ) {
			$csv_upper = strtoupper( $csv );

			if ( str_contains( $csv_upper, 'SUPREME' ) ) {
				$signals[] = $this->sig( self::SUPREME, 60, self::W_CSV, Classification_Result::SOURCE_CSV_IMPORT, __( 'CSV court hint', 'prose-core' ) );
			} elseif ( str_contains( $csv_upper, 'FAMILY' ) ) {
				$signals[] = $this->sig( self::FAMILY, 60, self::W_CSV, Classification_Result::SOURCE_CSV_IMPORT, __( 'CSV court hint', 'prose-core' ) );
			}
		}

		return $this->resolve_signals( $signals );
	}

	/**
	 * Resolve gathered signals into a final court decision.
	 *
	 * The highest-weight signal selects the winning court; every agreeing signal
	 * contributes its label, and the final confidence is the strongest agreeing
	 * confidence. Two or more distinct agreeing signals yield combined_signals.
	 *
	 * @param array<int, array<string, mixed>> $signals Signals.
	 * @return array{value: string, confidence: int, source: string, supported: bool, signals: string[]}
	 */
	private function resolve_signals( array $signals ): array {
		if ( empty( $signals ) ) {
			return $this->finalize( '', 0, Classification_Result::SOURCE_AI_INFERENCE, true, array() );
		}

		usort(
			$signals,
			static function ( array $a, array $b ): int {
				return ( (int) $b['weight'] <=> (int) $a['weight'] )
					?: ( (int) $b['confidence'] <=> (int) $a['confidence'] );
			}
		);

		$winner     = (string) $signals[0]['court'];
		$confidence = 0;
		$labels     = array();

		foreach ( $signals as $signal ) {
			if ( $signal['court'] !== $winner ) {
				continue;
			}

			$confidence = max( $confidence, (int) $signal['confidence'] );
			$labels[]   = (string) $signal['label'];
		}

		$labels = array_values( array_unique( $labels ) );

		$top = $signals[0];

		if ( Classification_Result::SOURCE_PDF_CONTENT === $top['source'] && (int) $top['weight'] >= self::W_CONTENT ) {
			$source = Classification_Result::SOURCE_PDF_CONTENT;
		} elseif ( count( $labels ) >= 2 ) {
			$source = Classification_Result::SOURCE_COMBINED;
		} else {
			$source = (string) $top['source'];
		}

		return $this->finalize( $winner, $confidence, $source, true, $labels );
	}

	/**
	 * Map a case type label to its court.
	 *
	 * @param string $case_type Case type.
	 * @return string Court name or empty string.
	 */
	private function court_for_case_type( string $case_type ): string {
		$upper = strtoupper( $case_type );

		foreach ( self::CASE_TYPE_COURT as $keyword => $court ) {
			if ( str_contains( $upper, $keyword ) ) {
				return $court;
			}
		}

		return '';
	}

	/**
	 * Map a form code to its court via naming conventions.
	 *
	 * @param string $form_code Uppercase form code.
	 * @return array{court: string, confidence: int, label: string}
	 */
	private function court_for_form_code( string $form_code ): array {
		if ( '' !== $form_code ) {
			foreach ( self::FORM_CODE_PATTERNS as $pattern ) {
				if ( preg_match( $pattern[0], $form_code ) ) {
					return array(
						'court'      => $pattern[1],
						'confidence' => $pattern[2],
						'label'      => $pattern[3],
					);
				}
			}
		}

		return array(
			'court'      => '',
			'confidence' => 0,
			'label'      => '',
		);
	}

	/**
	 * Derive a probable form code from a filename.
	 *
	 * @param string $filename Uppercase filename.
	 * @return string
	 */
	private function code_from_filename( string $filename ): string {
		$base = preg_replace( '/\.[A-Z0-9]+$/', '', $filename );

		if ( preg_match( '/^[A-Z]*-?\d+[A-Z]?/', (string) $base, $match ) ) {
			return $match[0];
		}

		return (string) $base;
	}

	/**
	 * Build a keyword-based court signal from a haystack.
	 *
	 * @param string $haystack Uppercase haystack.
	 * @param int    $weight   Signal weight.
	 * @param string $source   Source constant.
	 * @param string $origin   Human-readable origin (used in the signal label).
	 * @return array<string, mixed>|null
	 */
	private function keyword_signal( string $haystack, int $weight, string $source, string $origin ): ?array {
		if ( '' === $haystack ) {
			return null;
		}

		foreach ( self::KEYWORD_COURT as $keyword => $info ) {
			if ( str_contains( $haystack, $keyword ) ) {
				return $this->sig(
					$info[0],
					$info[1],
					$weight,
					$source,
					sprintf( '%s: %s', $origin, ucwords( strtolower( $keyword ) ) )
				);
			}
		}

		return null;
	}

	/**
	 * Detect a workflow-stage court signal from text.
	 *
	 * @param string $haystack Text (any case).
	 * @return array{court: string, stage: string}|null
	 */
	private function stage_signal( string $haystack ): ?array {
		$upper = strtoupper( $haystack );

		foreach ( self::STAGE_COURT as $keyword => $info ) {
			if ( str_contains( $upper, $keyword ) ) {
				return array(
					'court' => $info[0],
					'stage' => $info[1],
				);
			}
		}

		return null;
	}

	/**
	 * Build a signal record.
	 *
	 * @param string $court      Court name.
	 * @param int    $confidence Confidence 0-100.
	 * @param int    $weight     Scoring weight.
	 * @param string $source     Source constant.
	 * @param string $label      Human-readable signal label.
	 * @return array<string, mixed>
	 */
	private function sig( string $court, int $confidence, int $weight, string $source, string $label ): array {
		return array(
			'court'      => $court,
			'confidence' => max( 0, min( 100, $confidence ) ),
			'weight'     => $weight,
			'source'     => $source,
			'label'      => $label,
		);
	}

	/**
	 * Build the final court result.
	 *
	 * @param string   $value      Court name.
	 * @param int      $confidence Confidence.
	 * @param string   $source     Source.
	 * @param bool     $supported  Whether court is supported.
	 * @param string[] $signals    Contributing signal labels.
	 * @return array{value: string, confidence: int, source: string, supported: bool, signals: string[]}
	 */
	private function finalize( string $value, int $confidence, string $source, bool $supported, array $signals ): array {
		return array_merge(
			Classification_Result::make( $value, $confidence, $source ),
			array(
				'supported' => $supported,
				'signals'   => array_values( $signals ),
			)
		);
	}
}
