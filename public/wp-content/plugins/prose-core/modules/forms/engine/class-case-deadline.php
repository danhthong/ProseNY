<?php
/**
 * Case deadline DTO — a single procedural deadline for a case.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Forms\Engine;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Case_Deadline
 *
 * Immutable value object describing a generated deadline instance.
 */
final class Case_Deadline {

	/**
	 * Persisted case deadline ID (0 when in-memory).
	 *
	 * @var int
	 */
	private int $case_deadline_id;

	/**
	 * Case ID (0 when in-memory).
	 *
	 * @var int
	 */
	private int $case_id;

	/**
	 * Workflow key.
	 *
	 * @var string
	 */
	private string $workflow_key;

	/**
	 * Deadline rule key.
	 *
	 * @var string
	 */
	private string $deadline_key;

	/**
	 * Human-readable title.
	 *
	 * @var string
	 */
	private string $label;

	/**
	 * Computed due datetime (Y-m-d H:i:s).
	 *
	 * @var string
	 */
	private string $due_date;

	/**
	 * Trigger event that anchored this deadline.
	 *
	 * @var string
	 */
	private string $trigger_event;

	/**
	 * Anchor event datetime (Y-m-d H:i:s).
	 *
	 * @var string
	 */
	private string $anchor_date;

	/**
	 * Day type (calendar|court).
	 *
	 * @var string
	 */
	private string $day_type;

	/**
	 * Resolved status.
	 *
	 * @var string
	 */
	private string $status;

	/**
	 * Whether completed.
	 *
	 * @var bool
	 */
	private bool $completed;

	/**
	 * Completion timestamp.
	 *
	 * @var string|null
	 */
	private ?string $completed_at;

	/**
	 * Whether cancelled.
	 *
	 * @var bool
	 */
	private bool $cancelled;

	/**
	 * Suggested next action.
	 *
	 * @var string
	 */
	private string $next_action;

	/**
	 * Constructor.
	 *
	 * @param string      $deadline_key     Deadline key.
	 * @param string      $label            Label.
	 * @param string      $due_date         Due datetime.
	 * @param string      $trigger_event    Trigger event.
	 * @param string      $anchor_date      Anchor datetime.
	 * @param string      $day_type         Day type.
	 * @param string      $status           Status.
	 * @param bool        $completed        Completed flag.
	 * @param string      $next_action      Next action label.
	 * @param int         $case_deadline_id Persisted ID.
	 * @param int         $case_id          Case ID.
	 * @param string      $workflow_key     Workflow key.
	 * @param string|null $completed_at     Completion timestamp.
	 * @param bool        $cancelled        Cancelled flag.
	 */
	public function __construct(
		string $deadline_key,
		string $label,
		string $due_date,
		string $trigger_event = '',
		string $anchor_date = '',
		string $day_type = 'calendar',
		string $status = Deadline_Status::PENDING,
		bool $completed = false,
		string $next_action = '',
		int $case_deadline_id = 0,
		int $case_id = 0,
		string $workflow_key = '',
		?string $completed_at = null,
		bool $cancelled = false
	) {
		$this->case_deadline_id = $case_deadline_id;
		$this->case_id          = $case_id;
		$this->workflow_key     = $workflow_key;
		$this->deadline_key     = $deadline_key;
		$this->label            = $label;
		$this->due_date         = $due_date;
		$this->trigger_event    = $trigger_event;
		$this->anchor_date      = $anchor_date;
		$this->day_type         = $day_type;
		$this->status           = $status;
		$this->completed        = $completed;
		$this->completed_at     = $completed_at;
		$this->cancelled        = $cancelled;
		$this->next_action      = $next_action;
	}

	/**
	 * @return int
	 */
	public function case_deadline_id(): int {
		return $this->case_deadline_id;
	}

	/**
	 * @return int
	 */
	public function case_id(): int {
		return $this->case_id;
	}

	/**
	 * @return string
	 */
	public function workflow_key(): string {
		return $this->workflow_key;
	}

	/**
	 * @return string
	 */
	public function deadline_key(): string {
		return $this->deadline_key;
	}

	/**
	 * @return string
	 */
	public function label(): string {
		return $this->label;
	}

	/**
	 * @return string
	 */
	public function due_date(): string {
		return $this->due_date;
	}

	/**
	 * @return string
	 */
	public function trigger_event(): string {
		return $this->trigger_event;
	}

	/**
	 * @return string
	 */
	public function anchor_date(): string {
		return $this->anchor_date;
	}

	/**
	 * @return string
	 */
	public function day_type(): string {
		return $this->day_type;
	}

	/**
	 * @return string
	 */
	public function status(): string {
		return $this->status;
	}

	/**
	 * @return bool
	 */
	public function completed(): bool {
		return $this->completed;
	}

	/**
	 * @return string|null
	 */
	public function completed_at(): ?string {
		return $this->completed_at;
	}

	/**
	 * @return bool
	 */
	public function cancelled(): bool {
		return $this->cancelled;
	}

	/**
	 * @return string
	 */
	public function next_action(): string {
		return $this->next_action;
	}

	/**
	 * Return a copy with an updated status.
	 *
	 * @param string $status Status.
	 * @return self
	 */
	public function with_status( string $status ): self {
		$copy           = clone $this;
		$copy->status   = $status;

		return $copy;
	}

	/**
	 * Return a copy marked complete.
	 *
	 * @param string $completed_at Completion timestamp.
	 * @return self
	 */
	public function with_completed( string $completed_at ): self {
		$copy               = clone $this;
		$copy->completed    = true;
		$copy->completed_at = $completed_at;
		$copy->status       = Deadline_Status::COMPLETED;

		return $copy;
	}

	/**
	 * Build from stored array.
	 *
	 * @param array<string, mixed> $data Data.
	 * @return self
	 */
	public static function from_array( array $data ): self {
		return new self(
			(string) ( $data['deadline_key'] ?? '' ),
			(string) ( $data['label'] ?? $data['title'] ?? '' ),
			(string) ( $data['due_date'] ?? '' ),
			(string) ( $data['trigger_event'] ?? $data['source_event'] ?? '' ),
			(string) ( $data['anchor_date'] ?? $data['source_event_date'] ?? '' ),
			(string) ( $data['day_type'] ?? 'calendar' ),
			(string) ( $data['status'] ?? Deadline_Status::PENDING ),
			(bool) ( $data['completed'] ?? false ),
			(string) ( $data['next_action'] ?? '' ),
			(int) ( $data['case_deadline_id'] ?? 0 ),
			(int) ( $data['case_id'] ?? 0 ),
			(string) ( $data['workflow_key'] ?? '' ),
			! empty( $data['completed_at'] ) ? (string) $data['completed_at'] : null,
			(bool) ( $data['cancelled'] ?? false )
		);
	}

	/**
	 * Serialize to array.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array(
			'case_deadline_id' => $this->case_deadline_id,
			'case_id'          => $this->case_id,
			'workflow_key'     => $this->workflow_key,
			'deadline_key'     => $this->deadline_key,
			'label'            => $this->label,
			'title'            => $this->label,
			'due_date'         => $this->due_date,
			'trigger_event'    => $this->trigger_event,
			'anchor_date'      => $this->anchor_date,
			'day_type'         => $this->day_type,
			'status'           => $this->status,
			'completed'        => $this->completed,
			'completed_at'     => $this->completed_at,
			'cancelled'        => $this->cancelled,
			'next_action'      => $this->next_action,
		);
	}
}
