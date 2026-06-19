<?php
/**
 * Routing Engine — orchestrates the 5-step routing pipeline.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Routing;

use ProSe\Core\Routing\Matcher\Trigger_Matcher;
use ProSe\Core\Routing\Resolver\Court_Overlap_Resolver;
use ProSe\Core\Routing\Resolver\Court_Resolver;
use ProSe\Core\Routing\Resolver\Intent_Detector;
use ProSe\Core\Routing\Resolver\Issue_Resolver;
use ProSe\Core\Routing\Resolver\Workflow_Resolver;
use ProSe\Core\Routing\Validators\Missing_Info_Detector;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Routing_Engine
 */
final class Routing_Engine {

	/**
	 * Workflow catalog.
	 *
	 * @var Workflow_Catalog
	 */
	private Workflow_Catalog $catalog;

	/**
	 * Intent detector.
	 *
	 * @var Intent_Detector
	 */
	private Intent_Detector $intent_detector;

	/**
	 * Issue resolver.
	 *
	 * @var Issue_Resolver
	 */
	private Issue_Resolver $issue_resolver;

	/**
	 * Court resolver.
	 *
	 * @var Court_Resolver
	 */
	private Court_Resolver $court_resolver;

	/**
	 * Workflow resolver.
	 *
	 * @var Workflow_Resolver
	 */
	private Workflow_Resolver $workflow_resolver;

	/**
	 * Missing info detector.
	 *
	 * @var Missing_Info_Detector
	 */
	private Missing_Info_Detector $missing_info_detector;

	/**
	 * Court overlap resolver.
	 *
	 * @var Court_Overlap_Resolver
	 */
	private Court_Overlap_Resolver $overlap_resolver;

	/**
	 * Constructor.
	 *
	 * @param Workflow_Catalog|null         $catalog               Catalog.
	 * @param Intent_Detector|null          $intent_detector       Intent detector.
	 * @param Issue_Resolver|null           $issue_resolver        Issue resolver.
	 * @param Court_Resolver|null           $court_resolver        Court resolver.
	 * @param Workflow_Resolver|null        $workflow_resolver     Workflow resolver.
	 * @param Missing_Info_Detector|null    $missing_info_detector Missing info detector.
	 * @param Court_Overlap_Resolver|null   $overlap_resolver      Overlap resolver.
	 */
	public function __construct(
		?Workflow_Catalog $catalog = null,
		?Intent_Detector $intent_detector = null,
		?Issue_Resolver $issue_resolver = null,
		?Court_Resolver $court_resolver = null,
		?Workflow_Resolver $workflow_resolver = null,
		?Missing_Info_Detector $missing_info_detector = null,
		?Court_Overlap_Resolver $overlap_resolver = null
	) {
		$this->catalog               = $catalog ?? new Workflow_Catalog();
		$this->intent_detector       = $intent_detector ?? new Intent_Detector();
		$this->issue_resolver        = $issue_resolver ?? new Issue_Resolver( $this->catalog );
		$this->court_resolver        = $court_resolver ?? new Court_Resolver( $this->catalog );
		$this->workflow_resolver     = $workflow_resolver ?? new Workflow_Resolver( $this->catalog );
		$this->missing_info_detector = $missing_info_detector ?? new Missing_Info_Detector();
		$this->overlap_resolver      = $overlap_resolver ?? new Court_Overlap_Resolver( $this->catalog, null, $this->court_resolver );
	}

	/**
	 * Route free-form text with optional known facts array (MVP signature).
	 *
	 * @param string               $text         User text.
	 * @param array<string, mixed> $known_facts  Known facts.
	 * @return Routing_Result
	 */
	public function route( string $text, array $known_facts = array() ): Routing_Result {
		$profile = new Case_Profile( Fact_Store::from_array( $known_facts ) );

		return $this->route_profile( $text, $profile );
	}

	/**
	 * Route free-form text using a Case Profile (canonical session state).
	 *
	 * @param string       $text    User text.
	 * @param Case_Profile $profile Case profile.
	 * @return Routing_Result
	 */
	public function route_profile( string $text, Case_Profile $profile ): Routing_Result {
		$prior_issue               = $profile->issue();
		$prior_court               = $profile->court();
		$prior_candidate_workflows = $profile->candidate_workflows();

		$facts = $profile->facts();
		$facts->merge( $this->intent_detector->extract_facts( $text ) );

		$intent = $this->intent_detector->detect( $text );
		$signals = $intent['signals'];

		$issue = $this->issue_resolver->resolve( $text, $signals );

		// Short follow-up answers (e.g. "No", "Brooklyn") carry no issue signal.
		// Retain the session issue so routing does not reset mid-intake.
		if ( ( null === $issue || '' === $issue ) && null !== $prior_issue && '' !== $prior_issue ) {
			$issue = $prior_issue;
		}

		$issue = $this->prefer_active_divorce_family_issue( $issue, $text, $facts );

		$court = $this->court_resolver->resolve( $issue, $signals );

		if ( ( null === $court || '' === $court ) && null !== $prior_court && '' !== $prior_court ) {
			$court = $prior_court;
		}

		$workflow_resolution = $this->workflow_resolver->resolve( $issue, $text, $facts );

		$workflow            = $workflow_resolution['workflow'];
		$confidence          = (float) $workflow_resolution['confidence'];
		$candidate_workflows = (array) $workflow_resolution['candidate_workflows'];
		$missing_fields      = array();

		if ( null === $workflow || '' === $workflow ) {
			if ( empty( $candidate_workflows ) && null !== $issue ) {
				$candidate_workflows = array_keys( $this->catalog->by_issue( $this->base_issue( $issue ) ) );
			}

			if ( empty( $candidate_workflows ) && ! empty( $prior_candidate_workflows ) ) {
				$candidate_workflows = $prior_candidate_workflows;
			}

			$candidate_workflows = $this->filter_ambiguity_candidates( $candidate_workflows, $issue );
			$missing_fields      = $this->missing_info_detector->detect( $candidate_workflows, $facts );
			$confidence          = 0.0;
		}

		$required_form_codes = array();

		if ( null !== $workflow && '' !== $workflow ) {
			$definition = $this->catalog->by_key( $workflow );

			if ( null !== $definition ) {
				$required_form_codes = $this->catalog->required_form_codes( $definition );

				$workflow_court = (string) ( $definition['court'] ?? '' );

				if ( '' !== $workflow_court ) {
					$court = $workflow_court;
				}
			}
		}

		$overlap = $this->overlap_resolver->resolve( $text, $signals, $facts, $issue, $court, $workflow );

		$result = new Routing_Result(
			$issue,
			$court,
			$workflow,
			$confidence,
			$candidate_workflows,
			$missing_fields,
			$required_form_codes,
			$overlap['courts'],
			$overlap['overlap'],
			$overlap['overlap_reason'],
			$overlap['routing_explanation'],
			$overlap['routing_note']
		);

		$profile->apply_result( $result );

		return $result;
	}

	/**
	 * Base issue without refinements.
	 *
	 * @param string $issue Issue type.
	 * @return string
	 */
	private function base_issue( string $issue ): string {
		if ( str_starts_with( $issue, 'divorce' ) ) {
			return 'divorce';
		}

		return $issue;
	}

	/**
	 * Limit ambiguity candidates to entry-level workflows.
	 *
	 * @param string[]    $candidate_workflows Candidate workflows.
	 * @param string|null $issue               Issue type.
	 * @return string[]
	 */
	private function filter_ambiguity_candidates( array $candidate_workflows, ?string $issue ): array {
		if ( null === $issue || 'divorce' !== $this->base_issue( $issue ) ) {
			return array_values( $candidate_workflows );
		}

		$allowed = array(
			'uncontested_divorce_no_children_nyc',
			'uncontested_divorce_children_nyc',
			'contested_divorce_nyc',
		);

		$filtered = array_values( array_intersect( $candidate_workflows, $allowed ) );

		return ! empty( $filtered ) ? $filtered : array_values( $candidate_workflows );
	}

	/**
	 * When an active divorce is known, prefer the family-court issue the user is asking about
	 * so routing_rules can redirect into the Supreme Court divorce workflow.
	 *
	 * @param string|null $issue Resolved issue.
	 * @param string      $text  User text.
	 * @param Fact_Store  $facts Known facts.
	 * @return string|null
	 */
	private function prefer_active_divorce_family_issue( ?string $issue, string $text, Fact_Store $facts ): ?string {
		if ( ! $facts->has( 'active_divorce' ) || ! (bool) $facts->get( 'active_divorce' ) ) {
			return $issue;
		}

		$matcher = new Trigger_Matcher( $this->catalog );

		foreach ( array( 'custody', 'visitation', 'child_support' ) as $family_issue ) {
			foreach ( $matcher->score_by_issue( $text, $family_issue ) as $score ) {
				if ( (float) $score > 0.0 ) {
					return $family_issue;
				}
			}
		}

		return $issue;
	}
}
