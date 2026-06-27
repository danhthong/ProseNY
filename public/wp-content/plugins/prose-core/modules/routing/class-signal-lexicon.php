<?php
/**
 * Signal Lexicon — natural-language cues to fact tokens.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Routing;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Signal_Lexicon
 *
 * Engine-owned interpretation config (not workflow definitions).
 */
final class Signal_Lexicon {

	/**
	 * Cue phrases mapped to fact tokens.
	 *
	 * @var array<string, array{fact: string, value: mixed}>
	 */
	private const CUES = array(
		'we have children'              => array( 'fact' => 'children', 'value' => true ),
		'have children'                 => array( 'fact' => 'children', 'value' => true ),
		'two children'                  => array( 'fact' => 'children', 'value' => true ),
		'two kids'                      => array( 'fact' => 'children', 'value' => true ),
		'one child'                     => array( 'fact' => 'children', 'value' => true ),
		'minor children'                => array( 'fact' => 'children', 'value' => true ),
		'no children'                   => array( 'fact' => 'children', 'value' => false ),
		'without children'              => array( 'fact' => 'children', 'value' => false ),
		'agree on everything'           => array( 'fact' => 'spouse_agrees', 'value' => true ),
		'reached an agreement'          => array( 'fact' => 'spouse_agrees', 'value' => true ),
		'we settled during discovery'   => array( 'fact' => 'spouse_agrees', 'value' => true ),
		'spouse agrees'                 => array( 'fact' => 'spouse_agrees', 'value' => true ),
		'we both agree'                 => array( 'fact' => 'spouse_agrees', 'value' => true ),
		'agreed divorce'                => array( 'fact' => 'spouse_agrees', 'value' => true ),
		'uncontested'                   => array( 'fact' => 'spouse_agrees', 'value' => true ),
		'spouse will not agree'         => array( 'fact' => 'spouse_agrees', 'value' => false ),
		'wife refuses'                  => array( 'fact' => 'spouse_agrees', 'value' => false ),
		'husband refuses'               => array( 'fact' => 'spouse_agrees', 'value' => false ),
		'spouse refuses'                => array( 'fact' => 'spouse_agrees', 'value' => false ),
		'settlement failed'             => array( 'fact' => 'spouse_agrees', 'value' => false ),
		'will not agree'                => array( 'fact' => 'spouse_agrees', 'value' => false ),
		'contested'                     => array( 'fact' => 'spouse_agrees', 'value' => false ),
		'disagree'                      => array( 'fact' => 'spouse_agrees', 'value' => false ),
		"don't own anything together"   => array( 'fact' => 'marital_property_resolved', 'value' => true ),
		'neither of us wants support'   => array( 'fact' => 'spousal_support_waived', 'value' => true ),
		'no answer'                     => array( 'fact' => 'spouse_responded', 'value' => false ),
		'did not respond'               => array( 'fact' => 'spouse_responded', 'value' => false ),
		'never responded'               => array( 'fact' => 'spouse_responded', 'value' => false ),
		'failed to respond'             => array( 'fact' => 'spouse_responded', 'value' => false ),
		'default divorce'               => array( 'fact' => 'is_default', 'value' => true ),
		'divorce is finalized'          => array( 'fact' => 'active_divorce', 'value' => true ),
		'getting divorced'              => array( 'fact' => 'active_divorce', 'value' => true ),
		'getting a divorce'             => array( 'fact' => 'active_divorce', 'value' => true ),
		'active divorce'                => array( 'fact' => 'active_divorce', 'value' => true ),
		'divorce case'                  => array( 'fact' => 'active_divorce', 'value' => true ),
		'already in a divorce'          => array( 'fact' => 'active_divorce', 'value' => true ),
		'in a divorce'                  => array( 'fact' => 'active_divorce', 'value' => true ),
	);

	/**
	 * Catalog for normalization.
	 *
	 * @var Workflow_Catalog
	 */
	private Workflow_Catalog $catalog;

	/**
	 * Constructor.
	 *
	 * @param Workflow_Catalog|null $catalog Workflow catalog.
	 */
	public function __construct( ?Workflow_Catalog $catalog = null ) {
		$this->catalog = $catalog ?? new Workflow_Catalog();
	}

	/**
	 * Normalize free-form text.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	public function normalize( string $text ): string {
		return $this->catalog->normalize_text( $text );
	}

	/**
	 * Extract fact tokens from text.
	 *
	 * @param string $text Text.
	 * @return array<string, mixed>
	 */
	public function extract_facts( string $text ): array {
		$normalized = $this->normalize( $text );
		$facts      = array();

		if ( preg_match( '/\b(\d+)\s+children?\b/', $normalized, $matches ) ) {
			$facts['children']    = true;
			$facts['child_count'] = (int) $matches[1];
		}

		if ( preg_match( '/\b(my|our)\s+son\b|\b(my|our)\s+daughter\b|\bmy child\b/', $normalized ) ) {
			$facts['children'] = true;
		}

		foreach ( self::CUES as $cue => $mapping ) {
			if ( str_contains( $normalized, $cue ) ) {
				$facts[ $mapping['fact'] ] = $mapping['value'];
			}
		}

		if ( str_contains( $normalized, 'threatened' )
			|| str_contains( $normalized, 'abuse' )
			|| str_contains( $normalized, 'afraid of' )
			|| str_contains( $normalized, 'afraid' ) ) {
			$facts['protection_needed'] = true;
		}

		if ( preg_match( '/\bown\s+(?:two|three|multiple)\s+(?:houses|homes|properties)\b/', $normalized )
			|| preg_match( '/\b(?:two|three)\s+(?:houses|homes|properties)\b/', $normalized ) ) {
			$facts['marital_property_resolved'] = false;
		}

		if ( str_contains( $normalized, 'deployed' ) || str_contains( $normalized, 'deployment' ) ) {
			$facts['military_spouse'] = true;
		}

		if ( preg_match( '/\bmarried in\b/', $normalized ) ) {
			$facts['marriage_location'] = trim( $text );
		}

		if ( str_contains( $normalized, 'agree on everything' ) ) {
			$facts['marital_property_resolved'] = true;
		}

		$residency = $this->extract_residency_qualification( $normalized );

		if ( null !== $residency ) {
			$facts['residency_qualification'] = $residency;
		}

		return $facts;
	}

	/**
	 * Extract issue signals from text using workflow triggers.
	 *
	 * @param string $text Text.
	 * @return string[]
	 */
	public function extract_signals( string $text ): array {
		$normalized = $this->normalize( $text );
		$signals    = array();
		$catalog    = $this->catalog;
		$index      = $catalog->trigger_index();

		$phrases = array_keys( $index );
		usort(
			$phrases,
			static function ( string $a, string $b ): int {
				return strlen( $b ) <=> strlen( $a );
			}
		);

		foreach ( $phrases as $phrase ) {
			if ( str_contains( $normalized, $phrase ) ) {
				$signals[] = $phrase;
			}
		}

		foreach ( $this->extract_facts( $text ) as $fact => $value ) {
			if ( true === $value || is_numeric( $value ) ) {
				$signals[] = (string) $fact;
			}
		}

		return array_values( array_unique( $signals ) );
	}

	/**
	 * Map residency duration phrases to qualification keys.
	 *
	 * @param string $normalized Normalized text.
	 * @return string|null
	 */
	private function extract_residency_qualification( string $normalized ): ?string {
		if ( ! preg_match( '/\b(?:lived|living|resident|residency|moved|here|new york|ny)\b/', $normalized )
			&& ! str_contains( $normalized, 'just moved' ) ) {
			return null;
		}

		if ( str_contains( $normalized, 'just moved' ) ) {
			return 'ineligible';
		}

		if ( preg_match( '/\b(\d+)\s+months?\b/', $normalized, $matches ) ) {
			return (int) $matches[1] < 12 ? 'ineligible' : 'not_met';
		}

		if ( preg_match( '/\b(\d+)\s+years?\b/', $normalized, $matches ) ) {
			$years = (int) $matches[1];

			if ( $years >= 2 ) {
				return '2_year_state';
			}

			if ( $years >= 1 ) {
				return '1_year_state';
			}

			return 'ineligible';
		}

		return null;
	}
}
