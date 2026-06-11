<?php
/**
 * Case state resolver — produces the canonical case state snapshot.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Forms\Engine;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Case_State_Resolver
 *
 * Resolves a case into the canonical state shape consumed by callers:
 *
 *   {
 *     case_id,
 *     workflow_key,
 *     current_node,
 *     available_packages,
 *     completed_packages,
 *     progress_percentage
 *   }
 *
 * Pure and deterministic; delegates progress computation to
 * Case_Progress_Service.
 */
final class Case_State_Resolver {

	/**
	 * Progress service.
	 *
	 * @var Case_Progress_Service
	 */
	private Case_Progress_Service $progress;

	/**
	 * Constructor.
	 *
	 * @param Case_Progress_Service|null $progress Progress service.
	 */
	public function __construct( ?Case_Progress_Service $progress = null ) {
		$this->progress = $progress ?? new Case_Progress_Service();
	}

	/**
	 * Resolve a case into its canonical state array.
	 *
	 * @param Case_State $state Case state.
	 * @return array{
	 *     case_id: int,
	 *     workflow_key: string,
	 *     current_node: string,
	 *     current_package: string,
	 *     available_packages: string[],
	 *     completed_packages: string[],
	 *     progress_percentage: int,
	 *     is_complete: bool
	 * }
	 */
	public function resolve( Case_State $state ): array {
		$progress = $this->progress->compute( $state );

		return array(
			'case_id'             => $state->case_id(),
			'workflow_key'        => $state->workflow_key(),
			'current_node'        => $progress->current_node(),
			'current_package'     => $progress->current_package(),
			'available_packages'  => $progress->available_packages(),
			'completed_packages'  => $progress->completed_packages(),
			'progress_percentage' => $progress->progress_percentage(),
			'is_complete'         => $progress->is_complete(),
		);
	}
}
