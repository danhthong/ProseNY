<?php
/**
 * Case timeline presenter — stages and deadlines from a session case profile.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Intake;

use ProSe\Core\Forms\Engine\Case_Event;
use ProSe\Core\Forms\Engine\Case_Service;
use ProSe\Core\Forms\Engine\Case_State;
use ProSe\Core\Forms\Engine\Timeline_Service;
use ProSe\Core\Forms\Engine\Workflow_Progression_Service;
use ProSe\Core\Procedural\Guidance_Resolver;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Case_Timeline_Presenter
 */
final class Case_Timeline_Presenter {

	/**
	 * Case service.
	 *
	 * @var Case_Service
	 */
	private Case_Service $case_service;

	/**
	 * Timeline service.
	 *
	 * @var Timeline_Service
	 */
	private Timeline_Service $timeline_service;

	/**
	 * Workflow progression.
	 *
	 * @var Workflow_Progression_Service
	 */
	private Workflow_Progression_Service $progression;

	/**
	 * Guidance resolver (stage titles).
	 *
	 * @var Guidance_Resolver
	 */
	private Guidance_Resolver $guidance;

	/**
	 * Constructor.
	 *
	 * @param Case_Service|null                 $case_service     Case service.
	 * @param Timeline_Service|null             $timeline_service Timeline service.
	 * @param Workflow_Progression_Service|null $progression      Progression service.
	 * @param Guidance_Resolver|null            $guidance         Guidance resolver.
	 */
	public function __construct(
		?Case_Service $case_service = null,
		?Timeline_Service $timeline_service = null,
		?Workflow_Progression_Service $progression = null,
		?Guidance_Resolver $guidance = null
	) {
		$this->case_service     = $case_service ?? new Case_Service();
		$this->timeline_service = $timeline_service ?? new Timeline_Service();
		$this->progression      = $progression ?? new Workflow_Progression_Service();
		$this->guidance         = $guidance ?? new Guidance_Resolver();
	}

	/**
	 * Build a timeline payload from a stored session.
	 *
	 * @param array<string, mixed> $session Session row.
	 * @return array<string, mixed>
	 */
	public function from_session( array $session ): array {
		$case_profile = is_array( $session['case_profile'] ?? null ) ? $session['case_profile'] : array();
		$events       = is_array( $session['timeline_events'] ?? null ) ? $session['timeline_events'] : array();

		return $this->from_case_profile( $case_profile, $events );
	}

	/**
	 * Build a timeline payload from case profile facts and optional lifecycle events.
	 *
	 * @param array<string, mixed> $case_profile Case profile.
	 * @param array<int, mixed>    $events       Optional lifecycle events.
	 * @return array<string, mixed>
	 */
	public function from_case_profile( array $case_profile, array $events = array() ): array {
		$workflow = trim( (string) ( $case_profile['workflow'] ?? '' ) );
		$facts    = is_array( $case_profile['facts'] ?? null ) ? $case_profile['facts'] : array();

		if ( '' === $workflow ) {
			return $this->empty_timeline();
		}

		$engine_key = $this->progression->resolve_engine_enum( $workflow, $facts );
		$state      = $this->case_service->create_case( $engine_key, $facts );
		$this->replay_events( $state, $events );

		$now      = time();
		$timeline = $this->timeline_service->build_timeline( $state, $now );
		$resolved = $timeline->to_array();

		$definition    = $this->progression->definition( $workflow, $facts ) ?? array();
		$current_node  = $state->current_node();
		$current_stage = $this->progression->get_current_stage( $workflow, $current_node );
		$next_steps    = $this->guidance->next_steps( $definition, $current_stage, $current_node );
		$stages        = $this->build_stages( $next_steps, $current_stage );

		$deadlines = array_merge(
			$resolved['upcoming_deadlines'] ?? array(),
			$resolved['overdue_deadlines'] ?? array()
		);

		return array(
			'workflow'      => $workflow,
			'current_stage' => $this->current_stage_block( $stages, $current_stage ),
			'stages'        => $stages,
			'deadlines'     => $deadlines,
			'events'        => $this->serialize_events( $state->events() ),
			'tasks'         => $this->tasks_from_deadlines( $deadlines, $resolved['next_actions'] ?? array() ),
			'next_actions'  => $resolved['next_actions'] ?? array(),
			'status'        => $this->overall_status( $resolved ),
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function empty_timeline(): array {
		return array(
			'workflow'      => '',
			'current_stage' => null,
			'stages'        => array(),
			'deadlines'     => array(),
			'events'        => array(),
			'tasks'         => array(),
			'next_actions'  => array(),
			'status'        => 'pending',
		);
	}

	/**
	 * @param Case_State           $state  Case state.
	 * @param array<int, mixed>    $events Event rows.
	 * @return void
	 */
	private function replay_events( Case_State $state, array $events ): void {
		$this->timeline_service->generate( $state );

		foreach ( $events as $event ) {
			if ( ! is_array( $event ) ) {
				continue;
			}

			$type = trim( (string) ( $event['type'] ?? $event['event_type'] ?? '' ) );

			if ( '' === $type ) {
				continue;
			}

			$this->case_service->record_event(
				$state,
				$type,
				is_array( $event['payload'] ?? null ) ? $event['payload'] : array()
			);

			$occurred_at = trim( (string) ( $event['occurred_at'] ?? '' ) );
			$this->timeline_service->handle_event( $state, $type, $occurred_at );
		}
	}

	/**
	 * @param array<int, array<string, mixed>> $next_steps    Navigator steps.
	 * @param string|null                      $current_stage Current stage slug.
	 * @return array<int, array<string, mixed>>
	 */
	private function build_stages( array $next_steps, ?string $current_stage ): array {
		$stages = array();

		foreach ( $next_steps as $step ) {
			$is_current = ! empty( $step['current'] );

			$stages[] = array(
				'id'      => (string) ( $step['id'] ?? '' ),
				'title'   => (string) ( $step['title'] ?? '' ),
				'order'   => (int) ( $step['order'] ?? 0 ),
				'status'  => $is_current ? 'current' : ( $this->stage_is_complete( $next_steps, $step ) ? 'complete' : 'pending' ),
				'current' => $is_current,
				'forms'   => is_array( $step['forms'] ?? null ) ? $step['forms'] : array(),
			);
		}

		if ( null !== $current_stage && '' !== $current_stage ) {
			$found = false;

			foreach ( $stages as $index => $stage ) {
				if ( $stage['id'] === $current_stage ) {
					$stages[ $index ]['status']  = 'current';
					$stages[ $index ]['current'] = true;
					$found                       = true;
				}
			}

			if ( ! $found && ! empty( $stages ) ) {
				$stages[0]['status']  = 'current';
				$stages[0]['current'] = true;
			}
		}

		return $stages;
	}

	/**
	 * @param array<int, array<string, mixed>> $next_steps All steps.
	 * @param array<string, mixed>             $step       Step under test.
	 * @return bool
	 */
	private function stage_is_complete( array $next_steps, array $step ): bool {
		$order = (int) ( $step['order'] ?? 0 );

		foreach ( $next_steps as $candidate ) {
			if ( ! empty( $candidate['current'] ) && (int) ( $candidate['order'] ?? 0 ) > $order ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @param array<int, array<string, mixed>> $stages        Stage rows.
	 * @param string|null                      $current_stage Current stage slug.
	 * @return array<string, mixed>|null
	 */
	private function current_stage_block( array $stages, ?string $current_stage ): ?array {
		foreach ( $stages as $stage ) {
			if ( ! empty( $stage['current'] ) ) {
				return $stage;
			}
		}

		if ( null !== $current_stage && '' !== $current_stage ) {
			foreach ( $stages as $stage ) {
				if ( $stage['id'] === $current_stage ) {
					return $stage;
				}
			}
		}

		return ! empty( $stages ) ? $stages[0] : null;
	}

	/**
	 * @param Case_Event[] $events Case events.
	 * @return array<int, array<string, mixed>>
	 */
	private function serialize_events( array $events ): array {
		$rows = array();

		foreach ( $events as $event ) {
			if ( ! $event instanceof Case_Event ) {
				continue;
			}

			$rows[] = array(
				'type'        => $event->event_type(),
				'from_node'   => $event->from_node(),
				'to_node'     => $event->to_node(),
				'occurred_at' => $event->occurred_at(),
			);
		}

		return $rows;
	}

	/**
	 * @param array<int, array<string, mixed>> $deadlines   Deadline rows.
	 * @param string[]                         $next_actions Next action labels.
	 * @return array<int, array<string, mixed>>
	 */
	private function tasks_from_deadlines( array $deadlines, array $next_actions ): array {
		$tasks = array();

		foreach ( $deadlines as $deadline ) {
			if ( ! is_array( $deadline ) ) {
				continue;
			}

			$tasks[] = array(
				'id'          => (string) ( $deadline['deadline_key'] ?? '' ),
				'label'       => (string) ( $deadline['label'] ?? '' ),
				'due_date'    => (string) ( $deadline['due_date'] ?? '' ),
				'status'      => (string) ( $deadline['status'] ?? 'pending' ),
				'next_action' => (string) ( $deadline['next_action'] ?? '' ),
			);
		}

		foreach ( $next_actions as $action ) {
			$action = trim( (string) $action );

			if ( '' === $action ) {
				continue;
			}

			$tasks[] = array(
				'id'          => \sanitize_key( $action ),
				'label'       => $action,
				'due_date'    => '',
				'status'      => 'pending',
				'next_action' => $action,
			);
		}

		return $tasks;
	}

	/**
	 * @param array<string, mixed> $resolved Timeline resolver output.
	 * @return string
	 */
	private function overall_status( array $resolved ): string {
		if ( ! empty( $resolved['overdue_deadlines'] ) ) {
			return 'attention';
		}

		if ( ! empty( $resolved['upcoming_deadlines'] ) ) {
			return 'active';
		}

		return 'pending';
	}
}
