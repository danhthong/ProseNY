<?php
/**
 * Issue Resolver — resolves intent signals into an issue classification.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Routing\Resolver;

use ProSe\Core\Routing\Matcher\Trigger_Matcher;
use ProSe\Core\Routing\Workflow_Catalog;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Issue_Resolver
 */
final class Issue_Resolver {

	/**
	 * Workflow catalog.
	 *
	 * @var Workflow_Catalog
	 */
	private Workflow_Catalog $catalog;

	/**
	 * Trigger matcher.
	 *
	 * @var Trigger_Matcher
	 */
	private Trigger_Matcher $matcher;

	/**
	 * Constructor.
	 *
	 * @param Workflow_Catalog|null $catalog Workflow catalog.
	 * @param Trigger_Matcher|null  $matcher Trigger matcher.
	 */
	public function __construct( ?Workflow_Catalog $catalog = null, ?Trigger_Matcher $matcher = null ) {
		$this->catalog = $catalog ?? new Workflow_Catalog();
		$this->matcher = $matcher ?? new Trigger_Matcher( $this->catalog );
	}

	/**
	 * Resolve issue type from text and signals.
	 *
	 * @param string   $text    User text.
	 * @param string[] $signals Intent signals.
	 * @return string|null
	 */
	public function resolve( string $text, array $signals = array() ): ?string {
		$scores = $this->matcher->score_all( $text );
		$best_issue = null;
		$best_score = 0.0;

		foreach ( $this->catalog->all() as $key => $workflow ) {
			$score = (float) ( $scores[ $key ] ?? 0.0 );

			if ( $score <= 0.0 ) {
				continue;
			}

			$issue = (string) ( $workflow['issue_type'] ?? '' );

			if ( '' === $issue ) {
				continue;
			}

			if ( $score > $best_score ) {
				$best_score = $score;
				$best_issue = $issue;
			}
		}

		if ( null !== $best_issue ) {
			return $this->refine_issue( $best_issue, $signals );
		}

		return null;
	}

	/**
	 * Refine issue with signal modifiers (e.g. divorce + children).
	 *
	 * @param string   $issue   Base issue type.
	 * @param string[] $signals Signals.
	 * @return string
	 */
	private function refine_issue( string $issue, array $signals ): string {
		if ( 'divorce' === $issue && in_array( 'children', $signals, true ) ) {
			return 'divorce_with_children';
		}

		return $issue;
	}
}
