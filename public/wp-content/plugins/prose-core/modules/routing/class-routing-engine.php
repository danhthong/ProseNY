<?php
/**
 * Routing Engine — orchestrates the 5-step routing pipeline.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Routing;

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
	 * Constructor.
	 *
	 * @param Workflow_Catalog|null      $catalog              Catalog.
	 * @param Intent_Detector|null       $intent_detector      Intent detector.
	 * @param Issue_Resolver|null        $issue_resolver       Issue resolver.
	 * @param Court_Resolver|null        $court_resolver       Court resolver.
	 * @param Workflow_Resolver|null     $workflow_resolver    Workflow resolver.
	 * @param Missing_Info_Detector|null $missing_info_detector Missing info detector.
	 */
	public function __construct(
		?Workflow_Catalog $catalog = null,
		?Intent_Detector $intent_detector = null,
		?Issue_Resolver $issue_resolver = null,
		?Court_Resolver $court_resolver = null,
		?Workflow_Resolver $workflow_resolver = null,
		?Missing_Info_Detector $missing_info_detector = null
	) {
		$this->catalog               = $catalog ?? new Workflow_Catalog();
		$this->intent_detector       = $intent_detector ?? new Intent_Detector();
		$this->issue_resolver        = $issue_resolver ?? new Issue_Resolver( $this->catalog );
		$this->court_resolver        = $court_resolver ?? new Court_Resolver( $this->catalog );
		$this->workflow_resolver     = $workflow_resolver ?? new Workflow_Resolver( $this->catalog );
		$this->missing_info_detector = $missing_info_detector ?? new Missing_Info_Detector();
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
			}
		}

		$result = new Routing_Result(
			$issue,
			$court,
			$workflow,
			$confidence,
			$candidate_workflows,
			$missing_fields,
			$required_form_codes
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
}
