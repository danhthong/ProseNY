<?php
/**
 * Case progress DTO — immutable progress snapshot of a case.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Forms\Engine;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Case_Progress
 *
 * Value object returned by Case_Progress_Service. Carries the case's
 * current node, current package, completed and available packages, and a
 * deterministic 0-100 progress percentage.
 */
final class Case_Progress {

	/**
	 * Current workflow node key.
	 *
	 * @var string
	 */
	private string $current_node;

	/**
	 * Current (next actionable) package key.
	 *
	 * @var string
	 */
	private string $current_package;

	/**
	 * Completed package keys.
	 *
	 * @var string[]
	 */
	private array $completed_packages;

	/**
	 * Available (unlocked, not yet completed) package keys.
	 *
	 * @var string[]
	 */
	private array $available_packages;

	/**
	 * Progress percentage (0-100).
	 *
	 * @var int
	 */
	private int $progress_percentage;

	/**
	 * Whether the case has reached a terminal node.
	 *
	 * @var bool
	 */
	private bool $is_complete;

	/**
	 * Constructor.
	 *
	 * @param string   $current_node        Current node key.
	 * @param string   $current_package     Current package key.
	 * @param string[] $completed_packages  Completed package keys.
	 * @param string[] $available_packages  Available package keys.
	 * @param int      $progress_percentage Progress percentage (0-100).
	 * @param bool     $is_complete         Whether terminal node reached.
	 */
	public function __construct(
		string $current_node = '',
		string $current_package = '',
		array $completed_packages = array(),
		array $available_packages = array(),
		int $progress_percentage = 0,
		bool $is_complete = false
	) {
		$this->current_node        = $current_node;
		$this->current_package     = $current_package;
		$this->completed_packages  = array_values( array_map( 'strval', $completed_packages ) );
		$this->available_packages  = array_values( array_map( 'strval', $available_packages ) );
		$this->progress_percentage = max( 0, min( 100, $progress_percentage ) );
		$this->is_complete         = $is_complete;
	}

	/**
	 * Current node key.
	 *
	 * @return string
	 */
	public function current_node(): string {
		return $this->current_node;
	}

	/**
	 * Current package key.
	 *
	 * @return string
	 */
	public function current_package(): string {
		return $this->current_package;
	}

	/**
	 * Completed package keys.
	 *
	 * @return string[]
	 */
	public function completed_packages(): array {
		return $this->completed_packages;
	}

	/**
	 * Available package keys.
	 *
	 * @return string[]
	 */
	public function available_packages(): array {
		return $this->available_packages;
	}

	/**
	 * Progress percentage (0-100).
	 *
	 * @return int
	 */
	public function progress_percentage(): int {
		return $this->progress_percentage;
	}

	/**
	 * Whether the case has reached a terminal node.
	 *
	 * @return bool
	 */
	public function is_complete(): bool {
		return $this->is_complete;
	}

	/**
	 * Serialize to array.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array(
			'current_node'        => $this->current_node,
			'current_package'     => $this->current_package,
			'completed_packages'  => $this->completed_packages,
			'available_packages'  => $this->available_packages,
			'progress_percentage' => $this->progress_percentage,
			'is_complete'         => $this->is_complete,
		);
	}
}
