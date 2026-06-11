<?php
/**
 * Case progress service — derives a progress snapshot from case state.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Forms\Engine;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Case_Progress_Service
 *
 * Computes the current node, current package, completed and available
 * packages, and a deterministic progress percentage for a case. Pure and
 * database-free: identical state always yields identical progress.
 */
final class Case_Progress_Service {

	/**
	 * Compute the progress snapshot for a case.
	 *
	 * @param Case_State $state Case state.
	 * @return Case_Progress
	 */
	public function compute( Case_State $state ): Case_Progress {
		$workflow_key = $state->workflow_key();
		$current_node = $state->current_node();

		$progress    = Case_Catalog::progress_for_node( $workflow_key, $current_node );
		$is_complete = Case_Catalog::is_terminal( $workflow_key, $current_node );

		if ( $is_complete ) {
			$progress = 100;
		}

		return new Case_Progress(
			$current_node,
			$state->current_package(),
			$state->completed_packages(),
			$state->available_packages(),
			$progress,
			$is_complete
		);
	}

	/**
	 * Convenience accessor for the progress percentage of a case.
	 *
	 * @param Case_State $state Case state.
	 * @return int
	 */
	public function percentage( Case_State $state ): int {
		return $this->compute( $state )->progress_percentage();
	}
}
