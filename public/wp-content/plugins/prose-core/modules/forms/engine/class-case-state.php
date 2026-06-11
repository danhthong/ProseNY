<?php
/**
 * Case state DTO — the in-memory aggregate of a legal matter.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Forms\Engine;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Case_State
 *
 * Mutable aggregate that represents a single user legal matter: its
 * workflow, current node, package progress, and recorded events. The
 * Case_Service, Case_Event_Service and Case_Progress_Service operate on
 * this object so the full engine is testable without a database.
 */
final class Case_State {

	/**
	 * Case ID (0 when not yet persisted).
	 *
	 * @var int
	 */
	private int $case_id;

	/**
	 * Resolved workflow key.
	 *
	 * @var string
	 */
	private string $workflow_key;

	/**
	 * Court routing enum.
	 *
	 * @var string
	 */
	private string $court_routing;

	/**
	 * County enum.
	 *
	 * @var string
	 */
	private string $county;

	/**
	 * Current workflow node key.
	 *
	 * @var string
	 */
	private string $current_node;

	/**
	 * Intake answers used for deterministic routing.
	 *
	 * @var array<string, mixed>
	 */
	private array $answers;

	/**
	 * Completed package keys (in completion order).
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
	 * Recorded events.
	 *
	 * @var Case_Event[]
	 */
	private array $events;

	/**
	 * Lifecycle status (active|closed).
	 *
	 * @var string
	 */
	private string $status;

	/**
	 * Constructor.
	 *
	 * @param string               $workflow_key       Workflow key.
	 * @param string               $current_node       Entry node key.
	 * @param string[]             $available_packages Initial available packages.
	 * @param array<string, mixed> $answers            Intake answers.
	 */
	public function __construct(
		string $workflow_key = '',
		string $current_node = '',
		array $available_packages = array(),
		array $answers = array()
	) {
		$this->case_id            = 0;
		$this->workflow_key       = $workflow_key;
		$this->court_routing      = '';
		$this->county             = '';
		$this->current_node       = $current_node;
		$this->answers            = $answers;
		$this->completed_packages = array();
		$this->available_packages = array_values( array_map( 'strval', $available_packages ) );
		$this->events             = array();
		$this->status             = 'active';
	}

	/**
	 * Case ID.
	 *
	 * @return int
	 */
	public function case_id(): int {
		return $this->case_id;
	}

	/**
	 * Set the case ID (after persistence).
	 *
	 * @param int $case_id Case ID.
	 * @return void
	 */
	public function set_case_id( int $case_id ): void {
		$this->case_id = $case_id;
	}

	/**
	 * Workflow key.
	 *
	 * @return string
	 */
	public function workflow_key(): string {
		return $this->workflow_key;
	}

	/**
	 * Court routing enum.
	 *
	 * @return string
	 */
	public function court_routing(): string {
		return $this->court_routing;
	}

	/**
	 * Set the court routing enum.
	 *
	 * @param string $court_routing Court routing.
	 * @return void
	 */
	public function set_court_routing( string $court_routing ): void {
		$this->court_routing = $court_routing;
	}

	/**
	 * County enum.
	 *
	 * @return string
	 */
	public function county(): string {
		return $this->county;
	}

	/**
	 * Set the county enum.
	 *
	 * @param string $county County.
	 * @return void
	 */
	public function set_county( string $county ): void {
		$this->county = $county;
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
	 * Set the current node key.
	 *
	 * @param string $node_key Node key.
	 * @return void
	 */
	public function set_current_node( string $node_key ): void {
		$this->current_node = $node_key;
	}

	/**
	 * Intake answers.
	 *
	 * @return array<string, mixed>
	 */
	public function answers(): array {
		return $this->answers;
	}

	/**
	 * Completed package keys.
	 *
	 * @return string[]
	 */
	public function completed_packages(): array {
		return array_values( $this->completed_packages );
	}

	/**
	 * Available package keys.
	 *
	 * @return string[]
	 */
	public function available_packages(): array {
		return array_values( $this->available_packages );
	}

	/**
	 * Set the available package keys.
	 *
	 * @param string[] $packages Package keys.
	 * @return void
	 */
	public function set_available_packages( array $packages ): void {
		$this->available_packages = array_values( array_unique( array_map( 'strval', $packages ) ) );
	}

	/**
	 * Current (next actionable) package key.
	 *
	 * @return string
	 */
	public function current_package(): string {
		return $this->available_packages[0] ?? '';
	}

	/**
	 * Mark a package complete and remove it from the available set.
	 *
	 * @param string $package_key Package key.
	 * @return void
	 */
	public function mark_package_complete( string $package_key ): void {
		$package_key = (string) $package_key;

		if ( '' === $package_key ) {
			return;
		}

		if ( ! in_array( $package_key, $this->completed_packages, true ) ) {
			$this->completed_packages[] = $package_key;
		}

		$this->available_packages = array_values(
			array_filter(
				$this->available_packages,
				static fn( string $key ): bool => $key !== $package_key
			)
		);
	}

	/**
	 * Whether a package has been completed.
	 *
	 * @param string $package_key Package key.
	 * @return bool
	 */
	public function is_package_complete( string $package_key ): bool {
		return in_array( (string) $package_key, $this->completed_packages, true );
	}

	/**
	 * Recorded events.
	 *
	 * @return Case_Event[]
	 */
	public function events(): array {
		return $this->events;
	}

	/**
	 * Append a recorded event.
	 *
	 * @param Case_Event $event Event.
	 * @return void
	 */
	public function add_event( Case_Event $event ): void {
		$this->events[] = $event;
	}

	/**
	 * Lifecycle status.
	 *
	 * @return string
	 */
	public function status(): string {
		return $this->status;
	}

	/**
	 * Set the lifecycle status.
	 *
	 * @param string $status Status.
	 * @return void
	 */
	public function set_status( string $status ): void {
		$this->status = $status;
	}

	/**
	 * Rebuild a Case_State from stored data.
	 *
	 * @param array<string, mixed> $data Stored case data.
	 * @return self
	 */
	public static function from_array( array $data ): self {
		$state = new self(
			(string) ( $data['workflow_key'] ?? '' ),
			(string) ( $data['current_node'] ?? '' ),
			is_array( $data['available_packages'] ?? null ) ? $data['available_packages'] : array(),
			is_array( $data['answers'] ?? null ) ? $data['answers'] : array()
		);

		$state->set_case_id( (int) ( $data['case_id'] ?? 0 ) );
		$state->set_court_routing( (string) ( $data['court_routing'] ?? '' ) );
		$state->set_county( (string) ( $data['county'] ?? '' ) );
		$state->set_status( (string) ( $data['status'] ?? 'active' ) );

		foreach ( (array) ( $data['completed_packages'] ?? array() ) as $key ) {
			$state->mark_package_complete( (string) $key );
		}

		// Re-apply available packages after completions (completions strip them).
		$state->set_available_packages(
			is_array( $data['available_packages'] ?? null ) ? $data['available_packages'] : array()
		);

		foreach ( (array) ( $data['events'] ?? array() ) as $event ) {
			if ( is_array( $event ) ) {
				$state->add_event( Case_Event::from_array( $event ) );
			}
		}

		return $state;
	}

	/**
	 * Serialize to array.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array(
			'case_id'            => $this->case_id,
			'workflow_key'       => $this->workflow_key,
			'court_routing'      => $this->court_routing,
			'county'             => $this->county,
			'current_node'       => $this->current_node,
			'current_package'    => $this->current_package(),
			'completed_packages' => $this->completed_packages(),
			'available_packages' => $this->available_packages(),
			'answers'            => $this->answers,
			'status'             => $this->status,
			'events'             => array_map(
				static fn( Case_Event $event ): array => $event->to_array(),
				$this->events
			),
		);
	}
}
