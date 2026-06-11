<?php
/**
 * Case event DTO — a single workflow event recorded against a case.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Forms\Engine;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Case_Event
 *
 * Immutable value object describing an event that occurred in a case
 * (for example SERVICE_COMPLETED or JUDGMENT_ENTERED). Events are the
 * inputs that drive deterministic workflow progression.
 */
final class Case_Event {

	/**
	 * Event type (one of the Case_Catalog event constants).
	 *
	 * @var string
	 */
	private string $event_type;

	/**
	 * Node the case was on when the event was applied.
	 *
	 * @var string
	 */
	private string $from_node;

	/**
	 * Node the case advanced to (same as from_node when no advance).
	 *
	 * @var string
	 */
	private string $to_node;

	/**
	 * Arbitrary event payload.
	 *
	 * @var array<string, mixed>
	 */
	private array $payload;

	/**
	 * ISO-8601 / MySQL timestamp the event occurred.
	 *
	 * @var string
	 */
	private string $occurred_at;

	/**
	 * Constructor.
	 *
	 * @param string               $event_type  Event type.
	 * @param string               $from_node   Node before the event.
	 * @param string               $to_node     Node after the event.
	 * @param array<string, mixed> $payload     Event payload.
	 * @param string               $occurred_at Timestamp.
	 */
	public function __construct(
		string $event_type,
		string $from_node = '',
		string $to_node = '',
		array $payload = array(),
		string $occurred_at = ''
	) {
		$this->event_type  = $event_type;
		$this->from_node   = $from_node;
		$this->to_node     = $to_node;
		$this->payload     = $payload;
		$this->occurred_at = '' !== $occurred_at ? $occurred_at : gmdate( 'Y-m-d H:i:s' );
	}

	/**
	 * Event type.
	 *
	 * @return string
	 */
	public function event_type(): string {
		return $this->event_type;
	}

	/**
	 * Node before the event.
	 *
	 * @return string
	 */
	public function from_node(): string {
		return $this->from_node;
	}

	/**
	 * Node after the event.
	 *
	 * @return string
	 */
	public function to_node(): string {
		return $this->to_node;
	}

	/**
	 * Whether the event advanced the case node.
	 *
	 * @return bool
	 */
	public function advanced(): bool {
		return '' !== $this->to_node && $this->to_node !== $this->from_node;
	}

	/**
	 * Event payload.
	 *
	 * @return array<string, mixed>
	 */
	public function payload(): array {
		return $this->payload;
	}

	/**
	 * Timestamp the event occurred.
	 *
	 * @return string
	 */
	public function occurred_at(): string {
		return $this->occurred_at;
	}

	/**
	 * Build a Case_Event from a stored array.
	 *
	 * @param array<string, mixed> $data Stored event data.
	 * @return self
	 */
	public static function from_array( array $data ): self {
		return new self(
			(string) ( $data['event_type'] ?? '' ),
			(string) ( $data['from_node'] ?? '' ),
			(string) ( $data['to_node'] ?? '' ),
			is_array( $data['payload'] ?? null ) ? $data['payload'] : array(),
			(string) ( $data['occurred_at'] ?? '' )
		);
	}

	/**
	 * Serialize to array.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array(
			'event_type'  => $this->event_type,
			'from_node'   => $this->from_node,
			'to_node'     => $this->to_node,
			'payload'     => $this->payload,
			'occurred_at' => $this->occurred_at,
		);
	}
}
