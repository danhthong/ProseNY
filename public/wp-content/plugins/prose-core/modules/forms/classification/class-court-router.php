<?php
/**
 * Determine court routing enum from workflows and case type.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Forms\Classification;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Court_Router
 */
final class Court_Router {

	/**
	 * Supreme Court workflows.
	 *
	 * @var string[]
	 */
	private const SUPREME_WORKFLOWS = array(
		Vocabulary::WF_UNCONTESTED_DIVORCE,
		Vocabulary::WF_CONTESTED_DIVORCE,
		Vocabulary::WF_DEFAULT_DIVORCE,
		Vocabulary::WF_PROPERTY_DIVISION,
		Vocabulary::WF_DISCOVERY,
		Vocabulary::WF_MOTION_PRACTICE,
		Vocabulary::WF_EMERGENCY_RELIEF,
		Vocabulary::WF_SPOUSAL_MAINTENANCE,
		Vocabulary::WF_APPEAL,
	);

	/**
	 * Family Court workflows.
	 *
	 * @var string[]
	 */
	private const FAMILY_WORKFLOWS = array(
		Vocabulary::WF_CUSTODY,
		Vocabulary::WF_VISITATION,
		Vocabulary::WF_PARENTING_TIME,
		Vocabulary::WF_CHILD_SUPPORT,
		Vocabulary::WF_FAMILY_OFFENSE,
		Vocabulary::WF_ORDER_OF_PROTECTION,
		Vocabulary::WF_ENFORCEMENT,
		Vocabulary::WF_MODIFICATION,
	);

	/**
	 * Resolve court routing enum.
	 *
	 * @param array<string, mixed> $ctx Context (court, case_type, workflow_ids).
	 * @return string[]
	 */
	public function route( array $ctx ): array {
		$court        = (string) ( $ctx['court'] ?? '' );
		$case_type    = (string) ( $ctx['case_type'] ?? '' );
		$workflow_ids = is_array( $ctx['workflow_ids'] ?? null ) ? $ctx['workflow_ids'] : array();

		$routing = array();

		// Direct court label mapping.
		$from_court = Vocabulary::court_to_routing( $court );

		if ( '' !== $from_court ) {
			$routing[] = $from_court;
		}

		// Overlap rule: divorce + custody or child support.
		$has_divorce = $this->has_workflow( $workflow_ids, self::SUPREME_WORKFLOWS )
			|| str_contains( strtoupper( $case_type ), 'DIVORCE' );

		$has_family = $this->has_workflow( $workflow_ids, self::FAMILY_WORKFLOWS )
			|| str_contains( strtoupper( $case_type ), 'CUSTODY' )
			|| str_contains( strtoupper( $case_type ), 'CHILD SUPPORT' )
			|| str_contains( strtoupper( $case_type ), 'VISITATION' );

		if ( $has_divorce && $has_family ) {
			$routing = array( Vocabulary::ROUTE_SUPREME_AND_FAMILY_OVERLAP );
		} elseif ( $has_divorce ) {
			$routing[] = Vocabulary::ROUTE_SUPREME_COURT;
		} elseif ( $has_family ) {
			$routing[] = Vocabulary::ROUTE_FAMILY_COURT;
		}

		if ( empty( $routing ) && '' !== $from_court ) {
			$routing[] = $from_court;
		}

		$routing = array_values( array_unique( $routing ) );

		/**
		 * Filter court routing values.
		 *
		 * @param string[]             $routing Routing enum values.
		 * @param array<string, mixed> $ctx     Context.
		 */
		return apply_filters( 'prose_core_court_routing', $routing, $ctx );
	}

	/**
	 * Check if any workflow in list matches.
	 *
	 * @param string[] $workflow_ids Workflow IDs.
	 * @param string[] $candidates   Candidate workflows.
	 * @return bool
	 */
	private function has_workflow( array $workflow_ids, array $candidates ): bool {
		foreach ( $workflow_ids as $wf ) {
			if ( in_array( $wf, $candidates, true ) ) {
				return true;
			}
		}

		return false;
	}
}
