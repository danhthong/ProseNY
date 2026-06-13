<?php
/**
 * Priority Selector — tie-breaks workflows by intake/routing priority.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Routing\Matcher;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Priority_Selector
 */
final class Priority_Selector {

	/**
	 * Select the highest-priority workflow from candidates.
	 *
	 * @param array<string, array<string, mixed>> $candidates Candidate workflows keyed by workflow name.
	 * @return string|null
	 */
	public function select( array $candidates ): ?string {
		if ( empty( $candidates ) ) {
			return null;
		}

		$best_key = null;
		$best     = array(
			'intake_priority'   => -1,
			'routing_priority'  => -1,
		);

		foreach ( $candidates as $key => $workflow ) {
			$intake  = (int) ( $workflow['intake_priority'] ?? 0 );
			$routing = (int) ( $workflow['routing_priority'] ?? 0 );

			if ( $intake > $best['intake_priority']
				|| ( $intake === $best['intake_priority'] && $routing > $best['routing_priority'] ) ) {
				$best_key = (string) $key;
				$best     = array(
					'intake_priority'  => $intake,
					'routing_priority' => $routing,
				);
			}
		}

		return $best_key;
	}

	/**
	 * Sort workflow keys by priority descending.
	 *
	 * @param array<string, array<string, mixed>> $candidates Candidate workflows.
	 * @return string[]
	 */
	public function sort_keys( array $candidates ): array {
		$keys = array_keys( $candidates );

		usort(
			$keys,
			static function ( string $a, string $b ) use ( $candidates ): int {
				$a_intake  = (int) ( $candidates[ $a ]['intake_priority'] ?? 0 );
				$b_intake  = (int) ( $candidates[ $b ]['intake_priority'] ?? 0 );
				$a_routing = (int) ( $candidates[ $a ]['routing_priority'] ?? 0 );
				$b_routing = (int) ( $candidates[ $b ]['routing_priority'] ?? 0 );

				if ( $a_intake !== $b_intake ) {
					return $b_intake <=> $a_intake;
				}

				return $b_routing <=> $a_routing;
			}
		);

		return $keys;
	}
}
