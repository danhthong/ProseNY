<?php
/**
 * Court Overlap Resolver — detects multi-court scenarios from intake signals.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Routing\Resolver;

use ProSe\Core\Routing\Court_Routing_Explainer;
use ProSe\Core\Routing\Fact_Store;
use ProSe\Core\Routing\Matcher\Trigger_Matcher;
use ProSe\Core\Routing\Workflow_Catalog;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Court_Overlap_Resolver
 */
final class Court_Overlap_Resolver {

	private const SCORE_THRESHOLD = 0.35;

	/**
	 * Family-court issues that routing_rules may redirect into an active divorce.
	 *
	 * @var string[]
	 */
	private const ACTIVE_DIVORCE_FAMILY_ISSUES = array(
		'custody',
		'visitation',
		'child_support',
	);

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
	 * Court resolver.
	 *
	 * @var Court_Resolver
	 */
	private Court_Resolver $court_resolver;

	/**
	 * Constructor.
	 *
	 * @param Workflow_Catalog|null $catalog        Catalog.
	 * @param Trigger_Matcher|null  $matcher        Matcher.
	 * @param Court_Resolver|null   $court_resolver Court resolver.
	 */
	public function __construct(
		?Workflow_Catalog $catalog = null,
		?Trigger_Matcher $matcher = null,
		?Court_Resolver $court_resolver = null
	) {
		$this->catalog        = $catalog ?? new Workflow_Catalog();
		$this->matcher        = $matcher ?? new Trigger_Matcher( $this->catalog );
		$this->court_resolver = $court_resolver ?? new Court_Resolver( $this->catalog );
	}

	/**
	 * Resolve courts involved, overlap flag, and user-facing explanations.
	 *
	 * @param string               $text     User text.
	 * @param string[]             $signals  Intent signals.
	 * @param Fact_Store           $facts    Known facts.
	 * @param string|null          $issue    Primary issue.
	 * @param string|null          $court    Primary court.
	 * @param string|null          $workflow Resolved workflow.
	 * @return array{courts: string[], overlap: bool, overlap_reason: string|null, routing_explanation: string, routing_note: string}
	 */
	public function resolve(
		string $text,
		array $signals,
		Fact_Store $facts,
		?string $issue,
		?string $court,
		?string $workflow
	): array {
		$redirect_note = $this->active_divorce_redirect_note( $issue, $workflow, $facts );

		if ( '' !== $redirect_note ) {
			$primary_court = $court ?? 'supreme_court';

			return array(
				'courts'                => array( $primary_court ),
				'overlap'               => false,
				'overlap_reason'        => null,
				'routing_explanation'   => '',
				'routing_note'          => $redirect_note,
			);
		}

		$detected_issues = $this->detect_issues( $text, $issue, $signals );
		$courts          = $this->courts_for_issues( $detected_issues, $signals );

		if ( null !== $court && '' !== $court && ! in_array( $court, $courts, true ) ) {
			array_unshift( $courts, $court );
		}

		$courts = array_values( array_unique( array_filter( $courts ) ) );

		if ( empty( $courts ) && null !== $court && '' !== $court ) {
			$courts = array( $court );
		}

		$overlap_reason = $this->overlap_reason( $detected_issues, $facts );
		$overlap        = null !== $overlap_reason && count( $courts ) > 1;

		if ( ! $overlap ) {
			return array(
				'courts'                => $courts,
				'overlap'               => false,
				'overlap_reason'        => null,
				'routing_explanation'   => '',
				'routing_note'          => '',
			);
		}

		return array(
			'courts'                => $courts,
			'overlap'               => true,
			'overlap_reason'        => $overlap_reason,
			'routing_explanation'   => Court_Routing_Explainer::overlap_explanation( $overlap_reason, $courts ),
			'routing_note'          => '',
		);
	}

	/**
	 * Detect issue types present in the user's text and signals.
	 *
	 * @param string      $text    User text.
	 * @param string|null $issue   Primary issue (always included when set).
	 * @param string[]    $signals Intent signals.
	 * @return string[]
	 */
	private function detect_issues( string $text, ?string $issue, array $signals = array() ): array {
		$issues = array();
		$scores = $this->matcher->score_all( $text );

		foreach ( $this->catalog->all() as $key => $workflow ) {
			if ( (float) ( $scores[ $key ] ?? 0.0 ) < self::SCORE_THRESHOLD ) {
				continue;
			}

			$issue_type = (string) ( $workflow['issue_type'] ?? '' );

			if ( '' !== $issue_type ) {
				$issues[ $issue_type ] = true;
			}
		}

		foreach ( $this->issues_from_signals( $signals ) as $signal_issue ) {
			$issues[ $signal_issue ] = true;
		}

		if ( null !== $issue && '' !== $issue ) {
			$issues[ $issue ] = true;
		}

		return array_keys( $issues );
	}

	/**
	 * Map intent signals to issue types via the workflow trigger index.
	 *
	 * @param string[] $signals Intent signals.
	 * @return string[]
	 */
	private function issues_from_signals( array $signals ): array {
		$issues = array();
		$index  = $this->catalog->trigger_index();

		foreach ( $signals as $signal ) {
			$normalized = $this->catalog->normalize_text( (string) $signal );

			if ( ! isset( $index[ $normalized ] ) ) {
				continue;
			}

			foreach ( $index[ $normalized ] as $workflow_key ) {
				$definition = $this->catalog->by_key( $workflow_key );
				$issue_type = (string) ( $definition['issue_type'] ?? '' );

				if ( '' !== $issue_type ) {
					$issues[] = $issue_type;
				}
			}
		}

		return $issues;
	}

	/**
	 * Map detected issues to courts.
	 *
	 * @param string[] $issues  Issue types.
	 * @param string[] $signals Intent signals.
	 * @return string[]
	 */
	private function courts_for_issues( array $issues, array $signals ): array {
		$courts = array();

		foreach ( $issues as $issue ) {
			$resolved = $this->court_resolver->resolve( $issue, $signals );

			if ( null !== $resolved && '' !== $resolved ) {
				$courts[] = $resolved;
			}
		}

		return array_values( array_unique( $courts ) );
	}

	/**
	 * Whether this turn is an active-divorce redirect rather than a true overlap.
	 *
	 * @param string|null $issue    Primary issue.
	 * @param string|null $workflow Resolved workflow.
	 * @param Fact_Store  $facts    Facts.
	 * @return string Routing note text or empty string.
	 */
	private function active_divorce_redirect_note( ?string $issue, ?string $workflow, Fact_Store $facts ): string {
		if ( ! $facts->has( 'active_divorce' ) || ! (bool) $facts->get( 'active_divorce' ) ) {
			return '';
		}

		if ( null === $issue || '' === $issue ) {
			return '';
		}

		$base_issue = $this->base_issue( $issue );

		if ( ! in_array( $base_issue, self::ACTIVE_DIVORCE_FAMILY_ISSUES, true ) ) {
			return '';
		}

		if ( null === $workflow || '' === $workflow || ! str_contains( $workflow, 'divorce' ) ) {
			return '';
		}

		return Court_Routing_Explainer::routing_note( 'active_divorce_family_matter_redirect' );
	}

	/**
	 * Determine overlap reason key when multiple courts apply.
	 *
	 * @param string[]   $issues Detected issues.
	 * @param Fact_Store $facts  Facts.
	 * @return string|null
	 */
	private function overlap_reason( array $issues, Fact_Store $facts ): ?string {
		$has_divorce = $this->has_divorce_issue( $issues );
		$has_op      = in_array( 'order_of_protection', $issues, true );
		$has_offense = in_array( 'family_offense', $issues, true );
		$needs_protection = $facts->has( 'protection_needed' ) && (bool) $facts->get( 'protection_needed' );

		if ( $has_divorce && $has_op ) {
			return 'divorce_and_order_of_protection';
		}

		if ( $has_divorce && $has_offense ) {
			return 'divorce_and_family_offense';
		}

		if ( $has_divorce && $needs_protection ) {
			return 'divorce_and_protection';
		}

		return null;
	}

	/**
	 * Whether any detected issue is divorce-related.
	 *
	 * @param string[] $issues Issue types.
	 * @return bool
	 */
	private function has_divorce_issue( array $issues ): bool {
		foreach ( $issues as $issue ) {
			if ( str_starts_with( $issue, 'divorce' ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Strip issue refinements.
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
}
