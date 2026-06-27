<?php
/**
 * Workflow Resolver — selects the best workflow for an issue.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Routing\Resolver;

use ProSe\Core\Routing\Fact_Store;
use ProSe\Core\Routing\Matcher\Priority_Selector;
use ProSe\Core\Routing\Matcher\Routing_Rule_Evaluator;
use ProSe\Core\Routing\Matcher\Trigger_Matcher;
use ProSe\Core\Routing\Workflow_Catalog;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Workflow_Resolver
 */
final class Workflow_Resolver {

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
	 * Routing rule evaluator.
	 *
	 * @var Routing_Rule_Evaluator
	 */
	private Routing_Rule_Evaluator $rule_evaluator;

	/**
	 * Priority selector.
	 *
	 * @var Priority_Selector
	 */
	private Priority_Selector $priority_selector;

	/**
	 * Constructor.
	 *
	 * @param Workflow_Catalog|null       $catalog          Catalog.
	 * @param Trigger_Matcher|null        $matcher          Matcher.
	 * @param Routing_Rule_Evaluator|null $rule_evaluator   Rule evaluator.
	 * @param Priority_Selector|null      $priority_selector Priority selector.
	 */
	public function __construct(
		?Workflow_Catalog $catalog = null,
		?Trigger_Matcher $matcher = null,
		?Routing_Rule_Evaluator $rule_evaluator = null,
		?Priority_Selector $priority_selector = null
	) {
		$this->catalog           = $catalog ?? new Workflow_Catalog();
		$this->matcher           = $matcher ?? new Trigger_Matcher( $this->catalog );
		$this->rule_evaluator    = $rule_evaluator ?? new Routing_Rule_Evaluator();
		$this->priority_selector = $priority_selector ?? new Priority_Selector();
	}

	/**
	 * Resolve workflow from issue, text, and facts.
	 *
	 * @param string|null          $issue Issue type.
	 * @param string               $text  User text.
	 * @param Fact_Store|array<string, mixed> $facts Known facts.
	 * @return array{workflow: string|null, confidence: float, candidate_workflows: string[]}
	 */
	public function resolve( ?string $issue, string $text, $facts ): array {
		if ( null === $issue || '' === $issue ) {
			return array(
				'workflow'            => null,
				'confidence'          => 0.0,
				'candidate_workflows' => array(),
			);
		}

		$store      = $facts instanceof Fact_Store ? $facts : Fact_Store::from_array( (array) $facts );
		$base_issue = $this->base_issue( $issue );

		$redirect = $this->evaluate_routing_rules( $base_issue, $store );

		if ( null !== $redirect ) {
			return array(
				'workflow'            => $redirect,
				'confidence'          => 0.94,
				'candidate_workflows' => array(),
			);
		}

		$candidates = $this->build_candidates( $base_issue, $text, $store );

		if ( empty( $candidates ) ) {
			return array(
				'workflow'            => null,
				'confidence'          => 0.0,
				'candidate_workflows' => array(),
			);
		}

		$selected = $this->select_workflow( $candidates, $text, $store );

		if ( null === $selected ) {
			return array(
				'workflow'            => null,
				'confidence'          => 0.0,
				'candidate_workflows' => array(),
			);
		}

		$confidence = $this->confidence_for( $selected, $text, $candidates );

		return array(
			'workflow'            => $selected,
			'confidence'          => $confidence,
			'candidate_workflows' => array(),
		);
	}

	/**
	 * Evaluate cross-workflow routing rules for the issue.
	 *
	 * @param string     $base_issue Base issue.
	 * @param Fact_Store $store      Fact store.
	 * @return string|null
	 */
	private function evaluate_routing_rules( string $base_issue, Fact_Store $store ): ?string {
		foreach ( $this->catalog->by_issue( $base_issue ) as $workflow ) {
			$rules = (array) ( $workflow['routing_rules'] ?? array() );
			$hit   = $this->rule_evaluator->evaluate( $rules, $store );

			if ( null !== $hit ) {
				return $hit;
			}
		}

		return null;
	}

	/**
	 * Build candidate workflows for an issue.
	 *
	 * @param string     $base_issue Base issue.
	 * @param string     $text       User text.
	 * @param Fact_Store $store      Fact store.
	 * @return array<string, array<string, mixed>>
	 */
	private function build_candidates( string $base_issue, string $text, Fact_Store $store ): array {
		$scores     = $this->matcher->score_by_issue( $text, $base_issue );
		$candidates = array();

		foreach ( $this->catalog->by_issue( $base_issue ) as $key => $workflow ) {
			$score = (float) ( $scores[ $key ] ?? 0.0 );

			if ( $score > 0.0 || $this->matches_entry_facts( $workflow, $store ) ) {
				$candidates[ $key ] = $workflow;
			}
		}

		$this->inject_fact_driven_candidates( $candidates, $base_issue, $store );

		if ( ! empty( $candidates ) ) {
			return $candidates;
		}

		return $this->catalog->by_issue( $base_issue );
	}

	/**
	 * Inject workflows implied by known facts.
	 *
	 * @param array<string, array<string, mixed>> $candidates   Candidates.
	 * @param string                              $base_issue   Base issue.
	 * @param Fact_Store                          $store        Facts.
	 * @return void
	 */
	private function inject_fact_driven_candidates( array &$candidates, string $base_issue, Fact_Store $store ): void {
		if ( 'divorce' !== $base_issue ) {
			return;
		}

		if ( ( $store->has( 'spouse_responded' ) && false === (bool) $store->get( 'spouse_responded' ) )
			|| ( $store->has( 'is_default' ) && true === (bool) $store->get( 'is_default' ) ) ) {
			$default = $this->catalog->by_key( 'default_divorce_nyc' );

			if ( null !== $default ) {
				$candidates['default_divorce_nyc'] = $default;
			}
		}
	}

	/**
	 * Select a single workflow from candidates.
	 *
	 * @param array<string, array<string, mixed>> $candidates Candidates.
	 * @param string                              $text       Text.
	 * @param Fact_Store                          $store      Facts.
	 * @return string|null
	 */
	private function select_workflow( array $candidates, string $text, Fact_Store $store ): ?string {
		$filtered = $this->filter_by_facts( $candidates, $store );

		if ( 1 === count( $filtered ) ) {
			return (string) array_key_first( $filtered );
		}

		$children_pref = $this->prefer_uncontested_children_workflow( $filtered, $store );

		if ( null !== $children_pref ) {
			return $children_pref;
		}

		if ( count( $filtered ) > 1 ) {
			$issue_type = (string) ( reset( $filtered )['issue_type'] ?? '' );
			$scores     = $this->matcher->score_by_issue( $text, $issue_type );
			$best       = null;
			$best_score = 0.0;
			$second     = 0.0;

			foreach ( $filtered as $key => $workflow ) {
				$score = (float) ( $scores[ $key ] ?? 0.0 );

				if ( $score > $best_score ) {
					$second     = $best_score;
					$best_score = $score;
					$best       = (string) $key;
				} elseif ( $score > $second ) {
					$second = $score;
				}
			}

			if ( null !== $best && $best_score >= 0.55 && ( $best_score - $second ) >= 0.15 ) {
				return $best;
			}

			return null;
		}

		if ( 1 === count( $candidates ) ) {
			return (string) array_key_first( $candidates );
		}

		return null;
	}

	/**
	 * Prefer uncontested divorce with children when children are known and spouse has not disagreed.
	 *
	 * @param array<string, array<string, mixed>> $filtered Filtered candidates.
	 * @param Fact_Store                          $store    Facts.
	 * @return string|null
	 */
	private function prefer_uncontested_children_workflow( array $filtered, Fact_Store $store ): ?string {
		if ( ! $store->has( 'children' ) || true !== (bool) $store->get( 'children' ) ) {
			return null;
		}

		if ( $store->has( 'spouse_agrees' ) && false === (bool) $store->get( 'spouse_agrees' ) ) {
			return null;
		}

		if ( isset( $filtered['uncontested_divorce_children_nyc'] ) ) {
			return 'uncontested_divorce_children_nyc';
		}

		return null;
	}

	/**
	 * Filter candidates using known discriminator facts.
	 *
	 * @param array<string, array<string, mixed>> $candidates Candidates.
	 * @param Fact_Store                          $store      Facts.
	 * @return array<string, array<string, mixed>>
	 */
	private function filter_by_facts( array $candidates, Fact_Store $store ): array {
		$filtered = $candidates;

		if ( $store->has( 'children' ) ) {
			$has_children = (bool) $store->get( 'children' );
			$filtered     = $this->filter_divorce_children( $filtered, $has_children, $store );
		}

		if ( $store->has( 'spouse_agrees' ) ) {
			$agrees   = (bool) $store->get( 'spouse_agrees' );
			$filtered = $this->filter_divorce_agreement( $filtered, $agrees );

			if ( true === $agrees && $store->has( 'children' ) && false === (bool) $store->get( 'children' ) ) {
				$filtered = array_intersect_key(
					$filtered,
					array_flip( array( 'uncontested_divorce_no_children_nyc' ) )
				);
			}
		}

		if ( $store->has( 'spouse_responded' ) && false === (bool) $store->get( 'spouse_responded' ) ) {
			$filtered = array_intersect_key(
				$filtered,
				array_flip( array( 'default_divorce_nyc' ) )
			);
		}

		if ( $store->has( 'is_default' ) && true === (bool) $store->get( 'is_default' ) ) {
			$filtered = array_intersect_key(
				$filtered,
				array_flip( array( 'default_divorce_nyc' ) )
			);
		}

		return ! empty( $filtered ) ? $filtered : $candidates;
	}

	/**
	 * Filter divorce workflows by children fact.
	 *
	 * @param array<string, array<string, mixed>> $candidates   Candidates.
	 * @param bool                                $has_children Has children.
	 * @param Fact_Store                          $store        Facts.
	 * @return array<string, array<string, mixed>>
	 */
	private function filter_divorce_children( array $candidates, bool $has_children, Fact_Store $store ): array {
		$spouse_disputes = $store->has( 'spouse_agrees' ) && false === (bool) $store->get( 'spouse_agrees' );

		if ( $has_children ) {
			$allowed = $spouse_disputes
				? array( 'contested_divorce_nyc' )
				: array( 'uncontested_divorce_children_nyc' );
		} else {
			$allowed = $spouse_disputes
				? array( 'contested_divorce_nyc' )
				: array( 'uncontested_divorce_no_children_nyc' );
		}

		$result = array();

		foreach ( $candidates as $key => $workflow ) {
			if ( in_array( $key, $allowed, true ) || ! str_contains( $key, 'divorce' ) ) {
				$result[ $key ] = $workflow;
			}
		}

		return $result;
	}

	/**
	 * Filter divorce workflows by spouse agreement.
	 *
	 * @param array<string, array<string, mixed>> $candidates Candidates.
	 * @param bool                                $agrees     Spouse agrees.
	 * @return array<string, array<string, mixed>>
	 */
	private function filter_divorce_agreement( array $candidates, bool $agrees ): array {
		$allowed = $agrees
			? array( 'uncontested_divorce_children_nyc', 'uncontested_divorce_no_children_nyc' )
			: array( 'contested_divorce_nyc' );

		$result = array();

		foreach ( $candidates as $key => $workflow ) {
			if ( in_array( $key, $allowed, true ) || ! str_contains( $key, 'divorce' ) ) {
				$result[ $key ] = $workflow;
			}
		}

		return $result;
	}

	/**
	 * Whether workflow entry conditions are satisfied by facts.
	 *
	 * @param array<string, mixed> $workflow Workflow.
	 * @param Fact_Store           $store    Facts.
	 * @return bool
	 */
	private function matches_entry_facts( array $workflow, Fact_Store $store ): bool {
		unset( $workflow, $store );
		return false;
	}

	/**
	 * Compute confidence for a selected workflow.
	 *
	 * @param string                              $selected   Selected workflow.
	 * @param string                              $text       Text.
	 * @param array<string, array<string, mixed>> $candidates Candidates.
	 * @return float
	 */
	private function confidence_for( string $selected, string $text, array $candidates ): float {
		$workflow = $candidates[ $selected ] ?? $this->catalog->by_key( $selected );

		if ( null === $workflow ) {
			return 0.0;
		}

		$issue  = (string) ( $workflow['issue_type'] ?? '' );
		$scores = $this->matcher->score_by_issue( $text, $issue );
		$score  = (float) ( $scores[ $selected ] ?? 0.0 );

		if ( $score >= 0.7 ) {
			return round( min( 0.96, 0.85 + ( $score * 0.1 ) ), 2 );
		}

		if ( $score >= 0.4 ) {
			return round( 0.80 + ( $score * 0.1 ), 2 );
		}

		return 0.75;
	}

	/**
	 * Base issue without refinements.
	 *
	 * @param string $issue Issue.
	 * @return string
	 */
	private function base_issue( string $issue ): string {
		if ( str_starts_with( $issue, 'divorce' ) ) {
			return 'divorce';
		}

		return $issue;
	}
}
