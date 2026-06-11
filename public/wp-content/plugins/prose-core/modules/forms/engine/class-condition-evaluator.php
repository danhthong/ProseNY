<?php
/**
 * Condition DSL evaluator for package state machine.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Forms\Engine;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Condition_Evaluator
 */
final class Condition_Evaluator {

	/**
	 * Evaluate a condition group against case context.
	 *
	 * @param array<string, mixed> $group Condition group {all:[], any:[]}.
	 * @param array<string, mixed> $ctx   Case context.
	 * @return bool
	 */
	public function evaluate( array $group, array $ctx ): bool {
		if ( empty( $group ) ) {
			return true;
		}

		if ( ! empty( $group['all'] ) && is_array( $group['all'] ) ) {
			foreach ( $group['all'] as $condition ) {
				if ( ! $this->evaluate_atomic( (array) $condition, $ctx ) ) {
					return false;
				}
			}

			return true;
		}

		if ( ! empty( $group['any'] ) && is_array( $group['any'] ) ) {
			foreach ( $group['any'] as $condition ) {
				if ( $this->evaluate_atomic( (array) $condition, $ctx ) ) {
					return true;
				}
			}

			return false;
		}

		return true;
	}

	/**
	 * Evaluate a single atomic condition.
	 *
	 * @param array<string, mixed> $condition Condition.
	 * @param array<string, mixed> $ctx       Context.
	 * @return bool
	 */
	public function evaluate_atomic( array $condition, array $ctx ): bool {
		$type = (string) ( $condition['type'] ?? '' );

		if ( 'always' === $type ) {
			return true;
		}

		if ( 'event' === $type ) {
			$key    = (string) ( $condition['key'] ?? '' );
			$events = is_array( $ctx['events'] ?? null ) ? $ctx['events'] : array();

			return in_array( $key, $events, true );
		}

		if ( 'answer' === $type ) {
			$key   = (string) ( $condition['key'] ?? '' );
			$value = $condition['value'] ?? null;
			$op    = (string) ( $condition['op'] ?? 'eq' );
			$actual = $ctx['answers'][ $key ] ?? null;

			return $this->compare( $actual, $value, $op );
		}

		if ( 'state' === $type || 'dependency' === $type ) {
			$key    = (string) ( $condition['key'] ?? '' );
			$value  = (string) ( $condition['value'] ?? 'COMPLETE' );
			$states = is_array( $ctx['package_states'] ?? null ) ? $ctx['package_states'] : array();
			$actual = (string) ( $states[ $key ] ?? 'LOCKED' );

			$op = (string) ( $condition['op'] ?? 'eq' );

			return $this->compare( $actual, $value, $op );
		}

		return false;
	}

	/**
	 * Compare values.
	 *
	 * @param mixed  $actual Actual value.
	 * @param mixed  $expected Expected value.
	 * @param string $op Operator.
	 * @return bool
	 */
	private function compare( $actual, $expected, string $op ): bool {
		switch ( $op ) {
			case 'eq':
				return $actual === $expected;
			case 'neq':
				return $actual !== $expected;
			case 'in':
				return is_array( $expected ) && in_array( $actual, $expected, true );
			case 'exists':
				return null !== $actual && '' !== $actual;
			case 'gt':
				return $this->to_number( $actual ) > $this->to_number( $expected );
			case 'gte':
				return $this->to_number( $actual ) >= $this->to_number( $expected );
			case 'lt':
				return $this->to_number( $actual ) < $this->to_number( $expected );
			case 'lte':
				return $this->to_number( $actual ) <= $this->to_number( $expected );
			default:
				return false;
		}
	}

	/**
	 * Coerce a value to a number for numeric comparisons.
	 *
	 * Non-numeric and null values collapse to 0 so that, for example,
	 * an absent "children_count" answer never satisfies "> 0".
	 *
	 * @param mixed $value Value.
	 * @return float
	 */
	private function to_number( $value ): float {
		if ( is_bool( $value ) ) {
			return $value ? 1.0 : 0.0;
		}

		return is_numeric( $value ) ? (float) $value : 0.0;
	}
}
