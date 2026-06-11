<?php
/**
 * Case service — orchestrates the lifecycle of a legal matter.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Forms\Engine;

use ProSe\Core\Forms\Classification\Vocabulary;
use ProSe\Core\Forms\Database\Repositories\Case_Repository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Case_Service
 *
 * Public entry point for the Case Engine. Creates cases from a workflow
 * key, records lifecycle events, completes packages (unlocking the next
 * packages and advancing the node), and resolves the canonical case state.
 *
 * The service operates on the Case_State aggregate and works with or
 * without persistence: when a Case_Repository is injected, every mutation
 * is persisted; without one it runs purely in memory (used by unit tests),
 * mirroring the repository-optional pattern of the Routing Engine.
 */
final class Case_Service {

	/**
	 * Optional persistence layer.
	 *
	 * @var Case_Repository|null
	 */
	private ?Case_Repository $repository;

	/**
	 * Entry-node resolver (reused from the Routing Engine).
	 *
	 * @var Node_Resolver
	 */
	private Node_Resolver $node_resolver;

	/**
	 * Initial-package resolver (reused from the Routing Engine).
	 *
	 * @var Package_Resolver
	 */
	private Package_Resolver $package_resolver;

	/**
	 * Event service.
	 *
	 * @var Case_Event_Service
	 */
	private Case_Event_Service $event_service;

	/**
	 * State resolver.
	 *
	 * @var Case_State_Resolver
	 */
	private Case_State_Resolver $state_resolver;

	/**
	 * Constructor.
	 *
	 * @param Case_Repository|null     $repository       Persistence (null = in-memory).
	 * @param Node_Resolver|null       $node_resolver    Entry-node resolver.
	 * @param Package_Resolver|null    $package_resolver Initial-package resolver.
	 * @param Case_Event_Service|null  $event_service    Event service.
	 * @param Case_State_Resolver|null $state_resolver   State resolver.
	 */
	public function __construct(
		?Case_Repository $repository = null,
		?Node_Resolver $node_resolver = null,
		?Package_Resolver $package_resolver = null,
		?Case_Event_Service $event_service = null,
		?Case_State_Resolver $state_resolver = null
	) {
		$this->repository       = $repository;
		$this->node_resolver    = $node_resolver ?? new Node_Resolver();
		$this->package_resolver = $package_resolver ?? new Package_Resolver();
		$this->event_service    = $event_service ?? new Case_Event_Service();
		$this->state_resolver   = $state_resolver ?? new Case_State_Resolver();
	}

	/**
	 * Create a new case for a workflow.
	 *
	 * Initializes the current node (entry node) and the initial available
	 * packages, then persists the case when a repository is available.
	 *
	 * @param string               $workflow_key Workflow key.
	 * @param array<string, mixed> $answers      Intake answers.
	 * @return Case_State
	 */
	public function create_case( string $workflow_key, array $answers = array() ): Case_State {
		$node     = $this->node_resolver->resolve( $workflow_key );
		$node_key = (string) ( $node['node_key'] ?? '' );

		if ( '' === $node_key ) {
			$node_key = Case_Catalog::entry_node( $workflow_key );
		}

		$package   = $this->package_resolver->resolve( $workflow_key, $answers );
		$available = (array) ( $package['available_packages'] ?? array() );

		if ( empty( $available ) ) {
			$available = Case_Catalog::initial_packages( $workflow_key, $answers );
		}

		$state = new Case_State( $workflow_key, $node_key, $available, $answers );

		if ( null !== $this->repository ) {
			$state->set_court_routing( $this->court_routing_for_packages( $available ) );
			$this->repository->save_state( $state );
			$this->seed_forms( $state, $available );
		}

		return $state;
	}

	/**
	 * Complete a package: unlock the next packages and advance the node when
	 * the package satisfies the next node's condition.
	 *
	 * @param Case_State $state       Case state.
	 * @param string     $package_key Package key.
	 * @return Case_State
	 */
	public function complete_package( Case_State $state, string $package_key ): Case_State {
		$state->mark_package_complete( $package_key );

		$unlocked = Case_Catalog::unlocked_after(
			$state->workflow_key(),
			$package_key,
			$state->completed_packages(),
			$state->answers()
		);

		if ( ! empty( $unlocked ) ) {
			$state->set_available_packages( array_merge( $state->available_packages(), $unlocked ) );
		}

		$advanced = Case_Catalog::advance(
			$state->workflow_key(),
			$state->current_node(),
			Case_Catalog::COND_PACKAGE,
			$package_key
		);

		if ( $advanced !== $state->current_node() ) {
			$state->set_current_node( $advanced );
		}

		if ( null !== $this->repository ) {
			$this->repository->save_state( $state );
			$this->seed_forms( $state, $unlocked );
		}

		return $state;
	}

	/**
	 * Record a lifecycle event, advancing the workflow when satisfied.
	 *
	 * @param Case_State           $state      Case state.
	 * @param string               $event_type Event type.
	 * @param array<string, mixed> $payload    Event payload.
	 * @return Case_State
	 */
	public function record_event( Case_State $state, string $event_type, array $payload = array() ): Case_State {
		$event = $this->event_service->apply( $state, $event_type, $payload );

		if ( null !== $this->repository ) {
			$this->repository->insert_event( $state->case_id(), $event );
			$this->repository->save_state( $state );
		}

		return $state;
	}

	/**
	 * Load a persisted case.
	 *
	 * @param int $case_id Case ID.
	 * @return Case_State|null
	 */
	public function get_case( int $case_id ): ?Case_State {
		if ( null === $this->repository ) {
			return null;
		}

		return $this->repository->load_state( $case_id );
	}

	/**
	 * Resolve a case into its canonical state array.
	 *
	 * @param Case_State $state Case state.
	 * @return array<string, mixed>
	 */
	public function resolve_state( Case_State $state ): array {
		return $this->state_resolver->resolve( $state );
	}

	/**
	 * Resolve a persisted case into its canonical state array.
	 *
	 * @param int $case_id Case ID.
	 * @return array<string, mixed>|null
	 */
	public function resolve_case( int $case_id ): ?array {
		$state = $this->get_case( $case_id );

		return null === $state ? null : $this->resolve_state( $state );
	}

	/**
	 * Seed the tracked forms for a set of packages from the package catalog.
	 *
	 * @param Case_State $state        Case state.
	 * @param string[]   $package_keys Package keys.
	 * @return void
	 */
	private function seed_forms( Case_State $state, array $package_keys ): void {
		if ( null === $this->repository || $state->case_id() <= 0 ) {
			return;
		}

		$catalog = Vocabulary::package_catalog();

		foreach ( $package_keys as $package_key ) {
			$definition = $catalog[ $package_key ] ?? null;

			if ( null === $definition ) {
				continue;
			}

			$forms = array();

			foreach ( (array) ( $definition['required_forms'] ?? array() ) as $form_code ) {
				$forms[] = array(
					'form_code'   => (string) $form_code,
					'requirement' => 'required',
				);
			}

			foreach ( (array) ( $definition['optional_forms'] ?? array() ) as $form_code ) {
				$forms[] = array(
					'form_code'   => (string) $form_code,
					'requirement' => 'optional',
				);
			}

			if ( ! empty( $forms ) ) {
				$this->repository->set_package_forms( $state->case_id(), $package_key, $forms );
			}
		}
	}

	/**
	 * Resolve the court routing for the first known package.
	 *
	 * @param string[] $package_keys Package keys.
	 * @return string
	 */
	private function court_routing_for_packages( array $package_keys ): string {
		$catalog = Vocabulary::package_catalog();

		foreach ( $package_keys as $package_key ) {
			if ( isset( $catalog[ $package_key ]['court'] ) ) {
				return (string) $catalog[ $package_key ]['court'];
			}
		}

		return '';
	}
}
