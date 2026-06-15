<?php
/**
 * Consistency checker — deterministic contradiction detection.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Ai_Intake;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Consistency_Checker
 */
final class Consistency_Checker {

	/**
	 * Detect contradictions in intake state.
	 *
	 * @param Intake_State $state Intake state.
	 * @return array<int, array{field: string, message: string}>
	 */
	public function check( Intake_State $state ): array {
		$facts         = $state->plain_facts();
		$contradictions = array();
		$issue         = (string) ( $facts['issue'] ?? $state->workflow() ?? '' );
		$child_count   = $this->child_count( $facts );

		if ( $this->is_child_matter( $issue, $state->workflow() ) && 0 === $child_count && $state->is_filled( 'child_count' ) ) {
			$contradictions[] = array(
				'field'   => 'child_count',
				'message' => 'Custody and visitation matters require at least one child.',
			);
		}

		if ( isset( $facts['has_minor_children'] ) && false === $this->to_bool( $facts['has_minor_children'] )
			&& null !== $child_count && $child_count > 0 ) {
			$contradictions[] = array(
				'field'   => 'has_minor_children',
				'message' => 'You indicated no minor children, but also provided a child count greater than zero.',
			);
		}

		if ( isset( $facts['children'] ) && false === $this->to_bool( $facts['children'] )
			&& null !== $child_count && $child_count > 0 ) {
			$contradictions[] = array(
				'field'   => 'children',
				'message' => 'You indicated no children, but also provided a child count greater than zero.',
			);
		}

		if ( isset( $facts['spouse_agrees'] ) && false === $this->to_bool( $facts['spouse_agrees'] )
			&& str_contains( (string) $state->workflow(), 'uncontested' ) ) {
			$contradictions[] = array(
				'field'   => 'spouse_agrees',
				'message' => 'An uncontested divorce requires spouse agreement.',
			);
		}

		return $contradictions;
	}

	/**
	 * Whether issue/workflow is child-related.
	 *
	 * @param string      $issue    Issue type.
	 * @param string|null $workflow Workflow key.
	 * @return bool
	 */
	private function is_child_matter( string $issue, ?string $workflow ): bool {
		$haystack = strtolower( $issue . ' ' . (string) $workflow );

		return str_contains( $haystack, 'custody' )
			|| str_contains( $haystack, 'visitation' )
			|| str_contains( $haystack, 'child_support' );
	}

	/**
	 * Resolve child count from facts.
	 *
	 * @param array<string, mixed> $facts Facts.
	 * @return int|null
	 */
	private function child_count( array $facts ): ?int {
		foreach ( array( 'child_count', 'children_count' ) as $key ) {
			if ( isset( $facts[ $key ] ) && is_numeric( $facts[ $key ] ) ) {
				return (int) $facts[ $key ];
			}
		}

		return null;
	}

	/**
	 * Cast to boolean.
	 *
	 * @param mixed $value Value.
	 * @return bool
	 */
	private function to_bool( $value ): bool {
		if ( is_bool( $value ) ) {
			return $value;
		}

		return in_array( strtolower( (string) $value ), array( '1', 'true', 'yes' ), true );
	}
}
