<?php
/**
 * Timeline service — orchestrates deadline generation and resolution.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Forms\Engine;

use ProSe\Core\Forms\Database\Repositories\Case_Deadline_Repository;
use ProSe\Core\Forms\Database\Repositories\Case_Repository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Timeline_Service
 *
 * Public entry point for the Timeline Engine. Generates deadlines from
 * catalog rules, recalculates on lifecycle events, and resolves the
 * canonical case timeline. Repository-optional for DB-free unit tests.
 */
final class Timeline_Service {

	/**
	 * Optional case deadline persistence.
	 *
	 * @var Case_Deadline_Repository|null
	 */
	private ?Case_Deadline_Repository $deadline_repository;

	/**
	 * Optional case persistence (for loading by ID).
	 *
	 * @var Case_Repository|null
	 */
	private ?Case_Repository $case_repository;

	/**
	 * Deadline generator.
	 *
	 * @var Deadline_Generator
	 */
	private Deadline_Generator $generator;

	/**
	 * Timeline resolver.
	 *
	 * @var Case_Timeline_Resolver
	 */
	private Case_Timeline_Resolver $resolver;

	/**
	 * In-memory deadline store keyed by case ID (used when no repository).
	 *
	 * @var array<int, Case_Deadline[]>
	 */
	private array $memory_deadlines = array();

	/**
	 * Constructor.
	 *
	 * @param Case_Deadline_Repository|null $deadline_repository Deadline repo.
	 * @param Case_Repository|null          $case_repository     Case repo.
	 * @param Deadline_Generator|null       $generator           Generator.
	 * @param Case_Timeline_Resolver|null   $resolver            Resolver.
	 */
	public function __construct(
		?Case_Deadline_Repository $deadline_repository = null,
		?Case_Repository $case_repository = null,
		?Deadline_Generator $generator = null,
		?Case_Timeline_Resolver $resolver = null
	) {
		$this->deadline_repository = $deadline_repository;
		$this->case_repository     = $case_repository;
		$this->generator           = $generator ?? new Deadline_Generator( $deadline_repository );
		$this->resolver            = $resolver ?? new Case_Timeline_Resolver();
	}

	/**
	 * Generate all deadlines for a case from its anchors.
	 *
	 * @param Case_State $state Case state.
	 * @param int        $now   Current Unix timestamp.
	 * @return Case_Deadline[]
	 */
	public function generate( Case_State $state, int $now = 0 ): array {
		$now        = $now > 0 ? $now : time();
		$deadlines  = $this->generator->generate_for_case( $state, $now );
		$case_key   = $this->case_key( $state );

		$this->memory_deadlines[ $case_key ] = $this->merge_deadlines(
			$this->memory_deadlines[ $case_key ] ?? array(),
			$deadlines
		);

		return $this->memory_deadlines[ $case_key ];
	}

	/**
	 * Recalculate deadlines affected by a lifecycle event.
	 *
	 * @param Case_State $state       Case state.
	 * @param string     $event_type  Event type.
	 * @param string     $occurred_at Anchor datetime (Y-m-d H:i:s).
	 * @param int        $now         Current Unix timestamp.
	 * @return Case_Deadline[]
	 */
	public function handle_event(
		Case_State $state,
		string $event_type,
		string $occurred_at = '',
		int $now = 0
	): array {
		$now         = $now > 0 ? $now : time();
		$anchor_date = '' !== $occurred_at ? $occurred_at : gmdate( 'Y-m-d H:i:s', $now );

		$generated = $this->generator->generate_for_trigger(
			$state,
			$event_type,
			$anchor_date,
			$now
		);

		$case_key = $this->case_key( $state );

		$this->memory_deadlines[ $case_key ] = $this->merge_deadlines(
			$this->memory_deadlines[ $case_key ] ?? array(),
			$generated
		);

		return $generated;
	}

	/**
	 * Build the resolved timeline for a case.
	 *
	 * @param Case_State|int $case Case state or case ID.
	 * @param int            $now  Current Unix timestamp.
	 * @return Case_Timeline
	 */
	public function build_timeline( $case, int $now = 0 ): Case_Timeline {
		$now   = $now > 0 ? $now : time();
		$state = $case instanceof Case_State ? $case : $this->load_case( (int) $case );

		if ( null === $state ) {
			return new Case_Timeline();
		}

		if ( null !== $this->deadline_repository && $state->case_id() > 0 ) {
			$deadlines = $this->deadline_repository->find_for_case( $state->case_id(), $now );
		} else {
			$case_key = $this->case_key( $state );

			if ( empty( $this->memory_deadlines[ $case_key ] ) ) {
				$this->generate( $state, $now );
			}

			$deadlines = $this->memory_deadlines[ $case_key ] ?? array();
		}

		return $this->resolver->resolve( $deadlines, $now );
	}

	/**
	 * Mark a case deadline complete.
	 *
	 * @param int $case_deadline_id Case deadline ID.
	 * @return bool
	 */
	public function complete( int $case_deadline_id ): bool {
		if ( null !== $this->deadline_repository ) {
			return $this->deadline_repository->mark_complete( $case_deadline_id );
		}

		foreach ( $this->memory_deadlines as $case_key => $deadlines ) {
			foreach ( $deadlines as $index => $deadline ) {
				if ( $deadline->case_deadline_id() !== $case_deadline_id && $case_deadline_id > 0 ) {
					continue;
				}

				if ( $case_deadline_id <= 0 ) {
					continue;
				}

				$this->memory_deadlines[ $case_key ][ $index ] = $deadline->with_completed(
					gmdate( 'Y-m-d H:i:s' )
				);

				return true;
			}
		}

		return false;
	}

	/**
	 * Load a case state by ID.
	 *
	 * @param int $case_id Case ID.
	 * @return Case_State|null
	 */
	private function load_case( int $case_id ): ?Case_State {
		if ( null === $this->case_repository || $case_id <= 0 ) {
			return null;
		}

		return $this->case_repository->load_state( $case_id );
	}

	/**
	 * Stable in-memory key for a case.
	 *
	 * @param Case_State $state Case state.
	 * @return int
	 */
	private function case_key( Case_State $state ): int {
		if ( $state->case_id() > 0 ) {
			return $state->case_id();
		}

		return spl_object_id( $state );
	}

	/**
	 * Merge deadlines, replacing same key+trigger pairs.
	 *
	 * @param Case_Deadline[] $existing Existing deadlines.
	 * @param Case_Deadline[] $incoming New deadlines.
	 * @return Case_Deadline[]
	 */
	private function merge_deadlines( array $existing, array $incoming ): array {
		$indexed = array();

		foreach ( $existing as $deadline ) {
			$indexed[ $this->deadline_index_key( $deadline ) ] = $deadline;
		}

		foreach ( $incoming as $deadline ) {
			$indexed[ $this->deadline_index_key( $deadline ) ] = $deadline;
		}

		return array_values( $indexed );
	}

	/**
	 * Unique index key for a deadline.
	 *
	 * @param Case_Deadline $deadline Deadline.
	 * @return string
	 */
	private function deadline_index_key( Case_Deadline $deadline ): string {
		return $deadline->deadline_key() . '|' . $deadline->trigger_event();
	}
}
