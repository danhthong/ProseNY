<?php
/**
 * Trigger Matcher — scores text against workflow triggers.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Routing\Matcher;

use ProSe\Core\Routing\Workflow_Catalog;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Trigger_Matcher
 */
final class Trigger_Matcher {

	/**
	 * Workflow catalog.
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
	 * Score all workflows against text.
	 *
	 * @param string $text Text.
	 * @return array<string, float>
	 */
	public function score_all( string $text ): array {
		$normalized = $this->catalog->normalize_text( $text );
		$scores     = array();

		foreach ( $this->catalog->all() as $key => $workflow ) {
			$scores[ $key ] = $this->score_workflow( $normalized, $workflow );
		}

		return $scores;
	}

	/**
	 * Score workflows filtered by issue type.
	 *
	 * @param string $text       Text.
	 * @param string $issue_type Issue type.
	 * @return array<string, float>
	 */
	public function score_by_issue( string $text, string $issue_type ): array {
		$scores = array();

		foreach ( $this->catalog->by_issue( $issue_type ) as $key => $workflow ) {
			$normalized     = $this->catalog->normalize_text( $text );
			$scores[ $key ] = $this->score_workflow( $normalized, $workflow );
		}

		return $scores;
	}

	/**
	 * Score a single workflow.
	 *
	 * @param string               $normalized Normalized text.
	 * @param array<string, mixed> $workflow   Workflow definition.
	 * @return float
	 */
	private function score_workflow( string $normalized, array $workflow ): float {
		$score    = 0.0;
		$triggers = (array) ( $workflow['triggers'] ?? array() );

		usort(
			$triggers,
			static function ( $a, $b ): int {
				return strlen( (string) $b ) <=> strlen( (string) $a );
			}
		);

		foreach ( $triggers as $trigger ) {
			$phrase = $this->catalog->normalize_text( (string) $trigger );

			if ( '' === $phrase || ! str_contains( $normalized, $phrase ) ) {
				continue;
			}

			$words = count( explode( ' ', $phrase ) );
			$score = max( $score, min( 1.0, 0.4 + ( $words * 0.15 ) ) );
		}

		return round( $score, 4 );
	}
}
