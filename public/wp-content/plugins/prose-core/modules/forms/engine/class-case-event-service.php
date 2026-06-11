<?php
/**
 * Case event service — applies lifecycle events to a case.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Forms\Engine;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Case_Event_Service
 *
 * Applies lifecycle events (SERVICE_COMPLETED, ANSWER_RECEIVED,
 * HEARING_SCHEDULED, JUDGMENT_ENTERED) to a case. Events drive workflow
 * progression: when an event satisfies the condition for the case's next
 * node, the case advances. The recorded event captures the node transition.
 *
 * Operates purely on the Case_State aggregate so it is fully testable
 * without a database; persistence is the Case_Service's responsibility.
 */
final class Case_Event_Service {

	/**
	 * Apply a lifecycle event to a case, advancing the node when satisfied.
	 *
	 * @param Case_State           $state      Case state (mutated in place).
	 * @param string               $event_type Event type.
	 * @param array<string, mixed> $payload    Event payload.
	 * @param string               $occurred_at Optional timestamp.
	 * @return Case_Event The recorded event.
	 */
	public function apply( Case_State $state, string $event_type, array $payload = array(), string $occurred_at = '' ): Case_Event {
		$from_node = $state->current_node();
		$to_node   = $from_node;

		if ( Case_Catalog::is_event( $event_type ) ) {
			$to_node = Case_Catalog::advance(
				$state->workflow_key(),
				$from_node,
				Case_Catalog::COND_EVENT,
				$event_type
			);

			if ( $to_node !== $from_node ) {
				$state->set_current_node( $to_node );
			}
		}

		$event = new Case_Event( $event_type, $from_node, $to_node, $payload, $occurred_at );
		$state->add_event( $event );

		return $event;
	}
}
