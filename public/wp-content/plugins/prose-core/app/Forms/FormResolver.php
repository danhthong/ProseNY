<?php
/**
 * Resolves which official forms apply to a session (rules + fallbacks).
 *
 * @package ProseCore
 */

namespace Prose\Core\Forms;

final class FormResolver {

	/**
	 * @param array<string, mixed> $facts
	 * @param array<string, mixed> $workflow_state
	 * @return array<int, string>
	 */
	public function resolve( array $facts, array $workflow_state = array() ): array {
		$from_rules = $workflow_state['required_forms'] ?? array();
		if ( is_array( $from_rules ) && ! empty( $from_rules ) ) {
			return $this->normalize_slugs( $from_rules );
		}

		return $this->fallback_for_facts( $facts );
	}

	/**
	 * @param array<string, mixed> $facts
	 * @return array<int, string>
	 */
	private function fallback_for_facts( array $facts ): array {
		$case = is_array( $facts['case'] ?? null ) ? $facts['case'] : array();

		if ( ! empty( $case['order_of_protection'] ) ) {
			return array();
		}

		$case_type = strtolower( (string) ( $case['case_type'] ?? 'divorce' ) );
		if ( ! in_array( $case_type, array( 'divorce', '' ), true ) ) {
			return array();
		}

		$forms = array( 'UD-2', 'UD-3' );

		if ( ! empty( $case['children'] ) ) {
			$forms[] = 'UCS-111';
		}

		return $this->normalize_slugs( $forms );
	}

	/**
	 * @param array<int, string> $slugs
	 * @return array<int, string>
	 */
	private function normalize_slugs( array $slugs ): array {
		$out = array();
		foreach ( $slugs as $slug ) {
			$slug = strtoupper( trim( (string) $slug ) );
			if ( '' !== $slug ) {
				$out[] = $slug;
			}
		}
		return array_values( array_unique( $out ) );
	}
}
