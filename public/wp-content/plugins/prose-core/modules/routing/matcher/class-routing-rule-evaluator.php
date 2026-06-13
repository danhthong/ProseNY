<?php
/**
 * Routing Rule Evaluator — evaluates workflow routing_rules conditions.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Routing\Matcher;

use ProSe\Core\Routing\Fact_Store;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Routing_Rule_Evaluator
 */
final class Routing_Rule_Evaluator {

	/**
	 * Evaluate routing rules against known facts.
	 *
	 * @param array<int, array<string, mixed>> $rules Routing rules.
	 * @param Fact_Store|array<string, mixed>  $facts Facts.
	 * @return string|null Redirect workflow key.
	 */
	public function evaluate( array $rules, $facts ): ?string {
		$store = $facts instanceof Fact_Store ? $facts : Fact_Store::from_array( (array) $facts );

		foreach ( $rules as $rule ) {
			$condition = (string) ( $rule['condition'] ?? '' );
			$target    = (string) ( $rule['workflow'] ?? '' );

			if ( '' === $condition || '' === $target ) {
				continue;
			}

			if ( $this->matches_condition( $condition, $store ) ) {
				return $target;
			}
		}

		return null;
	}

	/**
	 * Whether a condition matches the fact store.
	 *
	 * @param string    $condition Condition string (key=value).
	 * @param Fact_Store $store    Fact store.
	 * @return bool
	 */
	public function matches_condition( string $condition, Fact_Store $store ): bool {
		$parts = explode( '=', $condition, 2 );

		if ( 2 !== count( $parts ) ) {
			return false;
		}

		$key           = trim( $parts[0] );
		$expected_raw  = trim( $parts[1] );
		$expected      = $this->coerce_value( $expected_raw );

		if ( ! $store->has( $key ) ) {
			return false;
		}

		$actual = $store->get( $key );

		return $this->values_equal( $actual, $expected );
	}

	/**
	 * Coerce a condition value.
	 *
	 * @param string $value Raw value.
	 * @return mixed
	 */
	private function coerce_value( string $value ) {
		$lower = strtolower( $value );

		if ( 'true' === $lower ) {
			return true;
		}

		if ( 'false' === $lower ) {
			return false;
		}

		if ( is_numeric( $value ) ) {
			return str_contains( $value, '.' ) ? (float) $value : (int) $value;
		}

		return $value;
	}

	/**
	 * Compare fact values.
	 *
	 * @param mixed $actual   Actual value.
	 * @param mixed $expected Expected value.
	 * @return bool
	 */
	private function values_equal( $actual, $expected ): bool {
		if ( is_bool( $expected ) ) {
			if ( is_bool( $actual ) ) {
				return $actual === $expected;
			}

			if ( is_string( $actual ) ) {
				$lower = strtolower( $actual );
				return ( true === $expected && in_array( $lower, array( 'true', 'yes', '1' ), true ) )
					|| ( false === $expected && in_array( $lower, array( 'false', 'no', '0' ), true ) );
			}
		}

		return (string) $actual === (string) $expected;
	}
}
