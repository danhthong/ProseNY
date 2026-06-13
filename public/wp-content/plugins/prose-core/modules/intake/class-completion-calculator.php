<?php
/**
 * Completion Calculator — intake progress as a percentage.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Intake;

use ProSe\Core\Routing\Fact_Store;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Completion_Calculator
 */
final class Completion_Calculator {

	/**
	 * Calculate completion percentage.
	 *
	 * completion = round( filled_required / total_required * 100 ).
	 * Only fields with required === true are counted. Returns 0 when there
	 * are no required fields (e.g. no workflow resolved yet).
	 *
	 * @param array<int, array<string, mixed>> $required_fields Workflow required_fields.
	 * @param Fact_Store|array<string, mixed>  $facts           Known facts.
	 * @return int
	 */
	public function calculate( array $required_fields, $facts ): int {
		$store = $facts instanceof Fact_Store ? $facts : Fact_Store::from_array( (array) $facts );

		$total  = 0;
		$filled = 0;

		foreach ( $required_fields as $field ) {
			if ( true !== ( $field['required'] ?? false ) ) {
				continue;
			}

			$key = (string) ( $field['key'] ?? '' );

			if ( '' === $key ) {
				continue;
			}

			++$total;

			if ( $this->is_filled( $store, $key ) ) {
				++$filled;
			}
		}

		if ( 0 === $total ) {
			return 0;
		}

		return (int) round( $filled / $total * 100 );
	}

	/**
	 * Ordered list of required field keys still missing from the facts.
	 *
	 * Only fields with required === true are considered. Workflow order preserved.
	 *
	 * @param array<int, array<string, mixed>> $required_fields Workflow required_fields.
	 * @param Fact_Store|array<string, mixed>  $facts           Known facts.
	 * @return string[]
	 */
	public function missing_required( array $required_fields, $facts ): array {
		$store   = $facts instanceof Fact_Store ? $facts : Fact_Store::from_array( (array) $facts );
		$missing = array();

		foreach ( $required_fields as $field ) {
			if ( true !== ( $field['required'] ?? false ) ) {
				continue;
			}

			$key = (string) ( $field['key'] ?? '' );

			if ( '' === $key ) {
				continue;
			}

			if ( ! $this->is_filled( $store, $key ) ) {
				$missing[] = $key;
			}
		}

		return $missing;
	}

	/**
	 * Whether a fact key holds a usable (non-empty) value.
	 *
	 * @param Fact_Store $store Fact store.
	 * @param string     $key   Fact key.
	 * @return bool
	 */
	private function is_filled( Fact_Store $store, string $key ): bool {
		if ( ! $store->has( $key ) ) {
			return false;
		}

		$value = $store->get( $key );

		if ( null === $value ) {
			return false;
		}

		if ( is_string( $value ) && '' === trim( $value ) ) {
			return false;
		}

		if ( is_array( $value ) && array() === $value ) {
			return false;
		}

		return true;
	}
}
