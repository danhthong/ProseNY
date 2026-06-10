<?php
/**
 * Map case type and context to workflow IDs and issue types.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Forms\Classification;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Workflow_Mapper
 */
final class Workflow_Mapper {

	/**
	 * Map classification context to workflow IDs and issue types.
	 *
	 * @param array<string, mixed> $ctx Context (case_type, text, title, form_code).
	 * @return array{workflow_ids: string[], issue_types: string[]}
	 */
	public function map( array $ctx ): array {
		$case_type = (string) ( $ctx['case_type'] ?? '' );
		$text      = strtoupper( (string) ( $ctx['text'] ?? '' ) . ' ' . (string) ( $ctx['title'] ?? '' ) );
		$form_code = strtoupper( trim( (string) ( $ctx['form_code'] ?? '' ) ) );

		$workflow_ids = Vocabulary::workflows_for_case_type( $case_type );
		$issue_types  = Vocabulary::issue_types_for_case_type( $case_type );

		// Keyword boosts from PDF content.
		if ( str_contains( $text, 'DISCOVERY' ) || str_contains( $text, 'INTERROGATOR' ) ) {
			$workflow_ids[] = Vocabulary::WF_DISCOVERY;
		}

		if ( str_contains( $text, 'MOTION' ) || str_contains( $text, 'ORDER TO SHOW CAUSE' ) ) {
			$workflow_ids[] = Vocabulary::WF_MOTION_PRACTICE;
		}

		if ( str_contains( $text, 'DEFAULT' ) ) {
			$workflow_ids[] = Vocabulary::WF_DEFAULT_DIVORCE;
		}

		if ( str_contains( $text, 'ORDER OF PROTECTION' ) ) {
			$workflow_ids[] = Vocabulary::WF_ORDER_OF_PROTECTION;
		}

		if ( preg_match( '/^UD-/', $form_code ) && empty( $workflow_ids ) ) {
			$workflow_ids[] = Vocabulary::WF_UNCONTESTED_DIVORCE;
		}

		$workflow_ids = array_values( array_unique( $workflow_ids ) );
		$issue_types    = array_values( array_unique( $issue_types ) );

		/**
		 * Filter mapped workflow IDs.
		 *
		 * @param string[]             $workflow_ids Workflow enum values.
		 * @param array<string, mixed> $ctx          Context.
		 */
		$workflow_ids = apply_filters( 'prose_core_workflow_ids', $workflow_ids, $ctx );

		/**
		 * Filter mapped issue types.
		 *
		 * @param string[]             $issue_types Issue type values.
		 * @param array<string, mixed> $ctx         Context.
		 */
		$issue_types = apply_filters( 'prose_core_issue_types', $issue_types, $ctx );

		return array(
			'workflow_ids' => $workflow_ids,
			'issue_types'  => $issue_types,
		);
	}
}
