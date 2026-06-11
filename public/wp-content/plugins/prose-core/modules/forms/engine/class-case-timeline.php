<?php
/**
 * Case timeline DTO — resolved deadline buckets and next actions.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Forms\Engine;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Case_Timeline
 *
 * Value object returned by Case_Timeline_Resolver.
 */
final class Case_Timeline {

	/**
	 * Open deadlines (pending, upcoming, due today).
	 *
	 * @var Case_Deadline[]
	 */
	private array $upcoming_deadlines;

	/**
	 * Past-due open deadlines.
	 *
	 * @var Case_Deadline[]
	 */
	private array $overdue_deadlines;

	/**
	 * Completed or cancelled deadlines.
	 *
	 * @var Case_Deadline[]
	 */
	private array $completed_deadlines;

	/**
	 * Suggested next actions.
	 *
	 * @var string[]
	 */
	private array $next_actions;

	/**
	 * Constructor.
	 *
	 * @param Case_Deadline[] $upcoming_deadlines  Upcoming bucket.
	 * @param Case_Deadline[] $overdue_deadlines   Overdue bucket.
	 * @param Case_Deadline[] $completed_deadlines Completed bucket.
	 * @param string[]        $next_actions        Next action labels.
	 */
	public function __construct(
		array $upcoming_deadlines = array(),
		array $overdue_deadlines = array(),
		array $completed_deadlines = array(),
		array $next_actions = array()
	) {
		$this->upcoming_deadlines  = $upcoming_deadlines;
		$this->overdue_deadlines    = $overdue_deadlines;
		$this->completed_deadlines = $completed_deadlines;
		$this->next_actions        = array_values( array_map( 'strval', $next_actions ) );
	}

	/**
	 * @return Case_Deadline[]
	 */
	public function upcoming_deadlines(): array {
		return $this->upcoming_deadlines;
	}

	/**
	 * @return Case_Deadline[]
	 */
	public function overdue_deadlines(): array {
		return $this->overdue_deadlines;
	}

	/**
	 * @return Case_Deadline[]
	 */
	public function completed_deadlines(): array {
		return $this->completed_deadlines;
	}

	/**
	 * @return string[]
	 */
	public function next_actions(): array {
		return $this->next_actions;
	}

	/**
	 * Serialize to array.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array(
			'upcoming_deadlines'  => array_map(
				static fn( Case_Deadline $deadline ): array => $deadline->to_array(),
				$this->upcoming_deadlines
			),
			'overdue_deadlines'   => array_map(
				static fn( Case_Deadline $deadline ): array => $deadline->to_array(),
				$this->overdue_deadlines
			),
			'completed_deadlines' => array_map(
				static fn( Case_Deadline $deadline ): array => $deadline->to_array(),
				$this->completed_deadlines
			),
			'next_actions'        => $this->next_actions,
		);
	}
}
