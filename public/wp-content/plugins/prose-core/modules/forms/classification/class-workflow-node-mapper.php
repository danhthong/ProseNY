<?php
/**
 * Map forms to workflow nodes and next steps.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Forms\Classification;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Workflow_Node_Mapper
 */
final class Workflow_Node_Mapper {

	/**
	 * Form code => node mapping.
	 *
	 * @var array<string, string>
	 */
	private const FORM_CODE_NODES = array(
		'UD-1'  => Vocabulary::NODE_1001_DIVORCE_FILED,
		'UD-2'  => Vocabulary::NODE_1001_DIVORCE_FILED,
		'UD-3'  => Vocabulary::NODE_1001_DIVORCE_FILED,
		'UD-4'  => Vocabulary::NODE_1001_DIVORCE_FILED,
		'UD-5'  => Vocabulary::NODE_1003_ANSWER_FILED,
		'UD-6'  => Vocabulary::NODE_1010_JUDGMENT,
		'UD-7'  => Vocabulary::NODE_1010_JUDGMENT,
		'UD-8'  => Vocabulary::NODE_1001_DIVORCE_FILED,
		'UD-11' => Vocabulary::NODE_3001_SUPPORT_PETITION,
		'UD-12' => Vocabulary::NODE_3001_SUPPORT_PETITION,
		'RJI'   => Vocabulary::NODE_1005_PRELIMINARY_CONFERENCE,
		'NOI'   => Vocabulary::NODE_1010_JUDGMENT,
	);

	/**
	 * Stage label => node mapping.
	 *
	 * @var array<string, string>
	 */
	private const STAGE_NODES = array(
		'Commencement' => Vocabulary::NODE_1001_DIVORCE_FILED,
		'Service'      => Vocabulary::NODE_1002_SERVICE_COMPLETE,
		'Response'     => Vocabulary::NODE_1003_ANSWER_FILED,
		'Discovery'    => Vocabulary::NODE_1006_DISCOVERY,
		'Settlement'   => Vocabulary::NODE_1008_SETTLEMENT,
		'Trial'        => Vocabulary::NODE_1009_TRIAL,
		'Judgment'     => Vocabulary::NODE_1010_JUDGMENT,
		'Petition'     => Vocabulary::NODE_2001_CUSTODY_PETITION,
		'Hearing'      => Vocabulary::NODE_2002_CUSTODY_HEARING,
		'Order'        => Vocabulary::NODE_2003_CUSTODY_ORDER,
		'Enforcement'  => Vocabulary::NODE_3001_SUPPORT_PETITION,
		'Modification' => Vocabulary::NODE_3001_SUPPORT_PETITION,
	);

	/**
	 * Node => next node(s).
	 *
	 * @var array<string, string[]>
	 */
	private const NODE_NEXT = array(
		Vocabulary::NODE_1001_DIVORCE_FILED          => array( Vocabulary::NODE_1002_SERVICE_COMPLETE ),
		Vocabulary::NODE_1002_SERVICE_COMPLETE       => array( Vocabulary::NODE_1003_ANSWER_FILED ),
		Vocabulary::NODE_1003_ANSWER_FILED           => array( Vocabulary::NODE_1005_PRELIMINARY_CONFERENCE ),
		Vocabulary::NODE_1004_OSC_FILED              => array( Vocabulary::NODE_1005_PRELIMINARY_CONFERENCE ),
		Vocabulary::NODE_1005_PRELIMINARY_CONFERENCE => array( Vocabulary::NODE_1006_DISCOVERY ),
		Vocabulary::NODE_1006_DISCOVERY              => array( Vocabulary::NODE_1007_COMPLIANCE_CONFERENCE ),
		Vocabulary::NODE_1007_COMPLIANCE_CONFERENCE  => array( Vocabulary::NODE_1008_SETTLEMENT, Vocabulary::NODE_1009_TRIAL ),
		Vocabulary::NODE_1008_SETTLEMENT             => array( Vocabulary::NODE_1010_JUDGMENT ),
		Vocabulary::NODE_1009_TRIAL                  => array( Vocabulary::NODE_1010_JUDGMENT ),
		Vocabulary::NODE_2001_CUSTODY_PETITION       => array( Vocabulary::NODE_2002_CUSTODY_HEARING ),
		Vocabulary::NODE_2002_CUSTODY_HEARING        => array( Vocabulary::NODE_2003_CUSTODY_ORDER ),
		Vocabulary::NODE_3001_SUPPORT_PETITION       => array( Vocabulary::NODE_3002_SUPPORT_ORDER ),
		Vocabulary::NODE_4001_FAMILY_OFFENSE         => array( Vocabulary::NODE_4002_TEMP_OP ),
		Vocabulary::NODE_4002_TEMP_OP                => array( Vocabulary::NODE_4003_FINAL_OP ),
	);

	/**
	 * Map context to workflow nodes and next steps.
	 *
	 * @param array<string, mixed> $ctx Context (form_code, workflow_stage, text, title).
	 * @return array{workflow_nodes: string[], next_steps: string[], trigger_events: string[], completion_events: string[]}
	 */
	public function map( array $ctx ): array {
		$form_code = strtoupper( trim( (string) ( $ctx['form_code'] ?? '' ) ) );
		$stage     = (string) ( $ctx['workflow_stage'] ?? '' );
		$text      = strtoupper( (string) ( $ctx['text'] ?? '' ) . ' ' . (string) ( $ctx['title'] ?? '' ) );

		$nodes = array();

		if ( isset( self::FORM_CODE_NODES[ $form_code ] ) ) {
			$nodes[] = self::FORM_CODE_NODES[ $form_code ];
		}

		if ( isset( self::STAGE_NODES[ $stage ] ) ) {
			$nodes[] = self::STAGE_NODES[ $stage ];
		}

		// Content-based node detection.
		if ( str_contains( $text, 'AFFIDAVIT OF SERVICE' ) || str_contains( $text, 'PROOF OF SERVICE' ) ) {
			$nodes[] = Vocabulary::NODE_1002_SERVICE_COMPLETE;
		}

		if ( str_contains( $text, 'ANSWER' ) ) {
			$nodes[] = Vocabulary::NODE_1003_ANSWER_FILED;
		}

		if ( str_contains( $text, 'ORDER TO SHOW CAUSE' ) ) {
			$nodes[] = Vocabulary::NODE_1004_OSC_FILED;
		}

		if ( str_contains( $text, 'ORDER OF PROTECTION' ) ) {
			$nodes[] = Vocabulary::NODE_4001_FAMILY_OFFENSE;
		}

		$nodes = array_values( array_unique( $nodes ) );

		$next_steps = array();

		foreach ( $nodes as $node ) {
			if ( isset( self::NODE_NEXT[ $node ] ) ) {
				$next_steps = array_merge( $next_steps, self::NODE_NEXT[ $node ] );
			}
		}

		$next_steps = array_values( array_unique( $next_steps ) );

		$trigger_events    = $this->trigger_events( $form_code, $stage, $text );
		$completion_events = $this->completion_events( $form_code, $stage, $text );

		return array(
			'workflow_nodes'    => $nodes,
			'next_steps'        => $next_steps,
			'trigger_events'    => $trigger_events,
			'completion_events' => $completion_events,
		);
	}

	/**
	 * Derive trigger events.
	 *
	 * @param string $form_code Form code.
	 * @param string $stage     Workflow stage.
	 * @param string $text      Uppercase text.
	 * @return string[]
	 */
	private function trigger_events( string $form_code, string $stage, string $text ): array {
		$events = array();

		if ( in_array( $form_code, array( 'UD-1', 'UD-2' ), true ) || 'Commencement' === $stage ) {
			$events[] = 'ACTION_COMMENCED';
		}

		if ( str_contains( $text, 'SERVICE' ) || 'Service' === $stage ) {
			$events[] = 'SERVICE_REQUIRED';
		}

		if ( str_contains( $text, 'PETITION' ) || 'Petition' === $stage ) {
			$events[] = 'PETITION_FILED';
		}

		return array_values( array_unique( $events ) );
	}

	/**
	 * Derive completion events.
	 *
	 * @param string $form_code Form code.
	 * @param string $stage     Workflow stage.
	 * @param string $text      Uppercase text.
	 * @return string[]
	 */
	private function completion_events( string $form_code, string $stage, string $text ): array {
		$events = array();

		if ( str_contains( $text, 'AFFIDAVIT OF SERVICE' ) || str_contains( $text, 'PROOF OF SERVICE' ) ) {
			$events[] = 'SERVICE_COMPLETE';
		}

		if ( 'UD-5' === $form_code || str_contains( $text, 'ANSWER FILED' ) ) {
			$events[] = 'ANSWER_FILED';
		}

		if ( 'UD-7' === $form_code || str_contains( $text, 'JUDGMENT OF DIVORCE' ) ) {
			$events[] = 'JUDGMENT_ENTERED';
		}

		if ( 'Order' === $stage ) {
			$events[] = 'ORDER_ENTERED';
		}

		return array_values( array_unique( $events ) );
	}
}
