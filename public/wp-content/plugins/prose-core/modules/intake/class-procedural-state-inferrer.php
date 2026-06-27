<?php
/**
 * Procedural state inferrer — facts and procedural node from user statements.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Intake;

use ProSe\Core\Forms\Classification\Vocabulary;
use ProSe\Core\Forms\Engine\Workflow_Progression_Service;
use ProSe\Core\Routing\Signal_Lexicon;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Procedural_State_Inferrer
 */
final class Procedural_State_Inferrer {

	/**
	 * @var Signal_Lexicon
	 */
	private Signal_Lexicon $lexicon;

	/**
	 * @var Workflow_Progression_Service
	 */
	private Workflow_Progression_Service $progression;

	/**
	 * Constructor.
	 *
	 * @param Signal_Lexicon|null              $lexicon     Signal lexicon.
	 * @param Workflow_Progression_Service|null $progression Progression service.
	 */
	public function __construct(
		?Signal_Lexicon $lexicon = null,
		?Workflow_Progression_Service $progression = null
	) {
		$this->lexicon     = $lexicon ?? new Signal_Lexicon();
		$this->progression = $progression ?? new Workflow_Progression_Service();
	}

	/**
	 * Deterministic fact updates from natural-language case-state cues.
	 *
	 * @param string               $message User message.
	 * @param array<string, mixed> $existing Existing plain facts.
	 * @return array<string, array{value: mixed, confidence: float}>
	 */
	public function supplemental_fact_updates( string $message, array $existing = array() ): array {
		$facts = $this->lexicon->extract_facts( $message );
		$text  = strtolower( trim( $message ) );

		if ( preg_match( '/\b(?:signed|have|has|had)\s+(?:a\s+)?settlement\s+agreement\b/', $text )
			|| preg_match( '/\bsettlement\s+agreement\s+(?:is\s+)?(?:signed|done|complete)\b/', $text ) ) {
			$facts['marital_property_resolved'] = true;
			$facts['spouse_agrees']             = true;
		}

		if ( $this->message_indicates_case_filed( $message ) ) {
			$facts['active_divorce'] = true;
			$facts['existing_case']  = true;
			$facts['case_status']    = 'FILED';
		}

		if ( preg_match( '/\b(?:served|service\s+(?:was\s+)?complete)\b/', $text )
			&& ! preg_match( '/\b(?:not|never|haven?t|hasn?t)\s+(?:been\s+)?served\b/', $text ) ) {
			$facts['service_completed'] = true;
			$facts['case_status']       = 'SERVED';
		}

		$updates = array();

		foreach ( $facts as $key => $value ) {
			if ( array_key_exists( $key, $existing ) && $this->values_equal( $existing[ $key ], $value ) ) {
				continue;
			}

			$updates[ $key ] = array(
				'value'      => $value,
				'confidence' => 0.95,
			);
		}

		return $updates;
	}

	/**
	 * Whether the user states the matrimonial case has already been commenced.
	 *
	 * @param string $message User message.
	 * @return bool
	 */
	public function message_indicates_case_filed( string $message ): bool {
		$text = strtolower( trim( $message ) );

		if ( preg_match(
			'/\b(?:no|not|never|hasn?t|haven?t)\s+(?:divorce\s+)?case\s+(?:has\s+been\s+)?filed\b/',
			$text
		) || preg_match( '/\b(?:not|never)\s+filed\s+yet\b/', $text ) ) {
			return false;
		}

		foreach ( array(
			'filed the divorce papers',
			'filed divorce papers',
			'filed the papers',
			'filed my divorce',
			'filed the divorce',
			'filed divorce',
			'already filed',
			'case has been filed',
			'papers were filed',
			'papers have been filed',
		) as $phrase ) {
			if ( str_contains( $text, $phrase ) ) {
				return true;
			}
		}

		return (bool) preg_match(
			'/\b(?:i|we)\s+filed\b.{0,50}\b(?:divorce|papers|summons|complaint)\b/',
			$text
		);
	}

	/**
	 * Whether facts or message show the case is already commenced.
	 *
	 * @param array<string, mixed> $facts   Plain facts.
	 * @param string               $message User message.
	 * @return bool
	 */
	public function case_already_filed( array $facts, string $message ): bool {
		if ( ! empty( $facts['active_divorce'] ) ) {
			return true;
		}

		return $this->message_indicates_case_filed( $message );
	}

	/**
	 * Infer procedural node from workflow + facts (never moves backward).
	 *
	 * @param string               $workflow     Workflow key.
	 * @param string               $current_node Current node.
	 * @param array<string, mixed> $facts        Plain facts.
	 * @param string               $message      User message.
	 * @return string
	 */
	public function infer_procedural_node(
		string $workflow,
		string $current_node,
		array $facts,
		string $message
	): string {
		$workflow     = trim( $workflow );
		$current_node = trim( $current_node );

		if ( '' === $workflow || ! str_contains( $workflow, 'divorce' ) ) {
			return $current_node;
		}

		$sequence = $this->progression->get_node_sequence( $workflow, $facts );

		if ( empty( $sequence ) ) {
			return $current_node;
		}

		$target = $current_node;

		$status = strtoupper( trim( (string) ( $facts['case_status'] ?? '' ) ) );

		if ( '' !== $status ) {
			$definition = $this->progression->definition( $workflow, $facts );
			$map        = is_array( $definition['internal']['case_status_node_map'] ?? null )
				? $definition['internal']['case_status_node_map']
				: array();

			if ( isset( $map[ $status ] ) ) {
				$target = $this->max_node( $sequence, $target, (string) $map[ $status ] );
			}
		}

		if ( $this->case_already_filed( $facts, $message ) ) {
			$target = $this->max_node( $sequence, $target, Vocabulary::NODE_1002_SERVICE_COMPLETE );
		}

		if ( ! empty( $facts['service_completed'] ) ) {
			$judgment = Vocabulary::NODE_1010_JUDGMENT;

			if ( in_array( $judgment, $sequence, true ) ) {
				$target = $this->max_node( $sequence, $target, $judgment );
			}
		}

		return $target;
	}

	/**
	 * @param string[] $sequence Node sequence.
	 * @param string   $current  Current node.
	 * @param string   $minimum  Minimum node to reach.
	 * @return string
	 */
	private function max_node( array $sequence, string $current, string $minimum ): string {
		$current_index  = array_search( $current, $sequence, true );
		$minimum_index  = array_search( $minimum, $sequence, true );

		if ( false === $minimum_index ) {
			return $current;
		}

		if ( false === $current_index || $minimum_index > $current_index ) {
			return (string) $sequence[ $minimum_index ];
		}

		return $current;
	}

	/**
	 * @param mixed $existing Existing value.
	 * @param mixed $incoming Incoming value.
	 * @return bool
	 */
	private function values_equal( $existing, $incoming ): bool {
		if ( is_bool( $existing ) || is_bool( $incoming ) ) {
			return (bool) $existing === (bool) $incoming;
		}

		return (string) $existing === (string) $incoming;
	}
}
