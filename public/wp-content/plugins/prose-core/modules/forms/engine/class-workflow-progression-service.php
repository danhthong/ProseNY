<?php
/**
 * Workflow Progression Service — JSON-driven stage and node progression.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Forms\Engine;

use ProSe\Core\Routing\Workflow_Catalog;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Workflow_Progression_Service
 *
 * Reads workflow definitions from the Workflow Repository and exposes
 * stage progression, form lookup, and node advancement. This is the
 * canonical procedural graph for case lifecycle and navigator UI.
 */
final class Workflow_Progression_Service {

	public const COND_EVENT   = 'event';
	public const COND_PACKAGE = 'package';

	/**
	 * Workflow catalog.
	 *
	 * @var Workflow_Catalog
	 */
	private Workflow_Catalog $catalog;

	/**
	 * Form applicability evaluator.
	 *
	 * @var Workflow_Form_Applicability_Service
	 */
	private Workflow_Form_Applicability_Service $applicability;

	/**
	 * Constructor.
	 *
	 * @param Workflow_Catalog|null                    $catalog       Workflow catalog.
	 * @param Workflow_Form_Applicability_Service|null $applicability Applicability service.
	 */
	public function __construct(
		?Workflow_Catalog $catalog = null,
		?Workflow_Form_Applicability_Service $applicability = null
	) {
		$this->catalog       = $catalog ?? new Workflow_Catalog();
		$this->applicability = $applicability ?? new Workflow_Form_Applicability_Service();
	}

	/**
	 * Resolve a repository workflow key from an engine workflow enum.
	 *
	 * @param string               $workflow_enum Engine enum (e.g. CONTESTED_DIVORCE).
	 * @param array<string, mixed> $context       Optional context (e.g. children flag).
	 * @return string|null
	 */
	public function resolve_workflow_key( string $workflow_enum, array $context = array() ): ?string {
		$enum = strtoupper( trim( $workflow_enum ) );

		if ( '' === $enum ) {
			return null;
		}

		$base_matches = array();

		foreach ( $this->catalog->all() as $key => $definition ) {
			$internal = is_array( $definition['internal'] ?? null ) ? $definition['internal'] : array();
			$file_enum = strtoupper( (string) ( $internal['workflow_enum'] ?? '' ) );
			$file_base = strtoupper( (string) ( $internal['workflow_enum_base'] ?? '' ) );

			if ( $file_enum === $enum ) {
				return (string) $key;
			}

			if ( $file_base === $enum ) {
				$base_matches[ (string) $key ] = $definition;
			}
		}

		if ( empty( $base_matches ) ) {
			return null;
		}

		if ( 'UNCONTESTED_DIVORCE' === $enum ) {
			$has_children = $this->context_has_children( $context );

			return $has_children
				? 'uncontested_divorce_children_nyc'
				: 'uncontested_divorce_no_children_nyc';
		}

		return array_key_first( $base_matches );
	}

	/**
	 * Resolve the engine workflow enum for timeline/deadline services.
	 *
	 * @param string               $workflow_enum_or_key Enum or repository key.
	 * @param array<string, mixed> $context              Context for enum resolution.
	 * @return string
	 */
	public function resolve_engine_enum( string $workflow_enum_or_key, array $context = array() ): string {
		$definition = $this->definition( $workflow_enum_or_key, $context );

		if ( null === $definition ) {
			return strtoupper( trim( $workflow_enum_or_key ) );
		}

		$internal = is_array( $definition['internal'] ?? null ) ? $definition['internal'] : array();

		if ( ! empty( $internal['workflow_enum_base'] ) ) {
			return strtoupper( (string) $internal['workflow_enum_base'] );
		}

		if ( ! empty( $internal['workflow_enum'] ) ) {
			return strtoupper( (string) $internal['workflow_enum'] );
		}

		return strtoupper( trim( $workflow_enum_or_key ) );
	}

	/**
	 * Load a workflow definition by enum or repository key.
	 *
	 * @param string               $workflow_enum_or_key Enum or workflow key.
	 * @param array<string, mixed> $context              Context for enum resolution.
	 * @return array<string, mixed>|null
	 */
	public function definition( string $workflow_enum_or_key, array $context = array() ): ?array {
		$direct = $this->catalog->by_key( $workflow_enum_or_key );

		if ( null !== $direct ) {
			return $direct;
		}

		$key = $this->resolve_workflow_key( $workflow_enum_or_key, $context );

		if ( null === $key ) {
			return null;
		}

		return $this->catalog->by_key( $key );
	}

	/**
	 * Ordered stage slugs for a workflow.
	 *
	 * @param string               $workflow_enum_or_key Enum or key.
	 * @param array<string, mixed> $context              Context.
	 * @return string[]
	 */
	public function get_stages( string $workflow_enum_or_key, array $context = array() ): array {
		$definition = $this->definition( $workflow_enum_or_key, $context );

		if ( null === $definition ) {
			return array();
		}

		return array_values(
			array_map(
				static fn( $stage ): string => (string) $stage,
				(array) ( $definition['stages'] ?? array() )
			)
		);
	}

	/**
	 * Ordered node keys from workflow JSON.
	 *
	 * @param string               $workflow_enum_or_key Enum or key.
	 * @param array<string, mixed> $context              Context.
	 * @return string[]
	 */
	public function get_node_sequence( string $workflow_enum_or_key, array $context = array() ): array {
		$definition = $this->definition( $workflow_enum_or_key, $context );

		if ( null === $definition ) {
			return array();
		}

		$internal = is_array( $definition['internal'] ?? null ) ? $definition['internal'] : array();

		return array_values(
			array_map(
				static fn( $node ): string => (string) $node,
				(array) ( $internal['node_sequence'] ?? array() )
			)
		);
	}

	/**
	 * Map stage slug => node key (1:1 by index).
	 *
	 * @param string               $workflow_enum_or_key Enum or key.
	 * @param array<string, mixed> $context              Context.
	 * @return array<string, string>
	 */
	public function stage_node_map( string $workflow_enum_or_key, array $context = array() ): array {
		$stages = $this->get_stages( $workflow_enum_or_key, $context );
		$nodes  = $this->get_node_sequence( $workflow_enum_or_key, $context );
		$map    = array();

		foreach ( $stages as $index => $stage ) {
			if ( isset( $nodes[ $index ] ) && '' !== $nodes[ $index ] ) {
				$map[ $stage ] = $nodes[ $index ];
			}
		}

		return $map;
	}

	/**
	 * Current stage slug for a node key.
	 *
	 * @param string               $workflow_enum_or_key Enum or key.
	 * @param string               $current_node         Current node key.
	 * @param array<string, mixed> $context              Context.
	 * @return string|null
	 */
	public function get_current_stage( string $workflow_enum_or_key, string $current_node, array $context = array() ): ?string {
		$current_node = trim( $current_node );

		if ( '' === $current_node ) {
			$stages = $this->get_stages( $workflow_enum_or_key, $context );

			return $stages[0] ?? null;
		}

		foreach ( $this->stage_node_map( $workflow_enum_or_key, $context ) as $stage => $node ) {
			if ( $node === $current_node ) {
				return $stage;
			}
		}

		return null;
	}

	/**
	 * Next stage slug after the current stage (linear; branches resolved via context).
	 *
	 * @param string               $workflow_enum_or_key Enum or key.
	 * @param string|null          $current_stage        Current stage slug.
	 * @param array<string, mixed> $context              Context (path=settlement|trial for contested branch).
	 * @return string|null
	 */
	public function get_next_stage( string $workflow_enum_or_key, ?string $current_stage, array $context = array() ): ?string {
		$stages = $this->get_stages( $workflow_enum_or_key, $context );

		if ( empty( $stages ) ) {
			return null;
		}

		if ( null === $current_stage || '' === $current_stage ) {
			return $stages[0];
		}

		$index = array_search( $current_stage, $stages, true );

		if ( false === $index ) {
			return null;
		}

		// Contested divorce: settlement and trial are alternate paths after compliance.
		if ( 'compliance_conference' === $current_stage ) {
			$path = strtolower( (string) ( $context['path'] ?? '' ) );

			if ( 'trial' === $path ) {
				$trial_index = array_search( 'trial', $stages, true );

				return false !== $trial_index ? $stages[ $trial_index ] : null;
			}

			$settlement_index = array_search( 'settlement', $stages, true );

			return false !== $settlement_index ? $stages[ $settlement_index ] : ( $stages[ $index + 1 ] ?? null );
		}

		if ( in_array( $current_stage, array( 'settlement', 'trial' ), true ) ) {
			$judgment_index = array_search( 'judgment', $stages, true );

			return false !== $judgment_index ? $stages[ $judgment_index ] : null;
		}

		return $stages[ $index + 1 ] ?? null;
	}

	/**
	 * Form codes required/optional for a stage.
	 *
	 * @param string               $workflow_enum_or_key Enum or key.
	 * @param string               $stage_slug           Stage slug.
	 * @param array<string, mixed> $context              Context.
	 * @return array<int, array{code: string, title: string, required: bool}>
	 */
	public function get_stage_forms( string $workflow_enum_or_key, string $stage_slug, array $context = array() ): array {
		$partition = $this->partition_stage_forms( $workflow_enum_or_key, $stage_slug, $context );

		return $partition['applicable'];
	}

	/**
	 * Applicable and skipped forms for a stage.
	 *
	 * @param string               $workflow_enum_or_key Enum or key.
	 * @param string               $stage_slug           Stage slug.
	 * @param array<string, mixed> $context              Context.
	 * @return array{applicable: array<int, array<string, mixed>>, skipped: array<int, array<string, mixed>>}
	 */
	public function partition_stage_forms( string $workflow_enum_or_key, string $stage_slug, array $context = array() ): array {
		$definition = $this->definition( $workflow_enum_or_key, $context );

		if ( null === $definition ) {
			return array(
				'applicable' => array(),
				'skipped'    => array(),
			);
		}

		$workflow_key = (string) ( $definition['workflow'] ?? $workflow_enum_or_key );
		$rows         = array();

		foreach ( array( 'required_forms', 'optional_forms' ) as $bucket ) {
			$required = 'required_forms' === $bucket;

			foreach ( (array) ( $definition[ $bucket ] ?? array() ) as $mapping ) {
				if ( (string) ( $mapping['stage'] ?? '' ) !== $stage_slug ) {
					continue;
				}

				foreach ( (array) ( $mapping['forms'] ?? array() ) as $form ) {
					$code = trim( (string) ( $form['code'] ?? '' ) );

					if ( '' === $code ) {
						continue;
					}

					$rows[] = array(
						'code'          => $code,
						'title'         => (string) ( $form['title'] ?? $code ),
						'required'      => $required,
						'required_when' => trim( (string) ( $form['required_when'] ?? ( $required ? 'always' : '' ) ) ),
					);
				}
			}
		}

		return $this->applicability->partition_stage_forms( $rows, $workflow_key, $stage_slug, $context );
	}

	/**
	 * Case-catalog-compatible progression steps from JSON.
	 *
	 * @param string               $workflow_enum_or_key Enum or key.
	 * @param array<string, mixed> $context              Context.
	 * @return array<int, array{node: string, condition: array{kind: string, value: string}|null}>
	 */
	public function progression_steps( string $workflow_enum_or_key, array $context = array() ): array {
		$definition = $this->definition( $workflow_enum_or_key, $context );

		if ( null === $definition ) {
			return array();
		}

		$internal    = is_array( $definition['internal'] ?? null ) ? $definition['internal'] : array();
		$progression = (array) ( $internal['progression'] ?? array() );
		$steps       = array();

		foreach ( $progression as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}

			$node = trim( (string) ( $entry['node'] ?? '' ) );

			if ( '' === $node ) {
				continue;
			}

			$condition = null;

			if ( is_array( $entry['condition'] ?? null ) ) {
				$kind  = trim( (string) ( $entry['condition']['kind'] ?? '' ) );
				$value = trim( (string) ( $entry['condition']['value'] ?? '' ) );

				if ( '' !== $kind && '' !== $value ) {
					$condition = array(
						'kind'  => $kind,
						'value' => $value,
					);
				}
			}

			$steps[] = array(
				'node'      => $node,
				'condition' => $condition,
			);
		}

		return $steps;
	}

	/**
	 * All lifecycle event types referenced in workflow JSON progression/edges.
	 *
	 * @return string[]
	 */
	public function registered_events(): array {
		$events = array();

		foreach ( $this->catalog->all() as $definition ) {
			$internal = is_array( $definition['internal'] ?? null ) ? $definition['internal'] : array();

			foreach ( (array) ( $internal['progression'] ?? array() ) as $entry ) {
				$this->collect_event_from_condition( $events, $entry['condition'] ?? null );
			}

			foreach ( (array) ( $internal['edges'] ?? array() ) as $edge ) {
				$this->collect_event_from_condition( $events, $edge['condition'] ?? null );
			}
		}

		return array_values( array_unique( $events ) );
	}

	/**
	 * Advance to the next node given a trigger.
	 *
	 * @param string               $workflow_enum_or_key Enum or key.
	 * @param string               $current_node         Current node.
	 * @param string               $trigger_kind         event|package.
	 * @param string               $trigger_value        Trigger value.
	 * @param array<string, mixed> $context              Context.
	 * @return string
	 */
	public function advance(
		string $workflow_enum_or_key,
		string $current_node,
		string $trigger_kind,
		string $trigger_value,
		array $context = array()
	): string {
		$definition = $this->definition( $workflow_enum_or_key, $context );

		if ( null === $definition ) {
			return $current_node;
		}

		$key      = (string) ( $definition['workflow'] ?? $workflow_enum_or_key );
		$internal = is_array( $definition['internal'] ?? null ) ? $definition['internal'] : array();
		$edges    = (array) ( $internal['edges'] ?? array() );

		foreach ( $edges as $edge ) {
			if ( ! is_array( $edge ) ) {
				continue;
			}

			if ( (string) ( $edge['from'] ?? '' ) !== $current_node ) {
				continue;
			}

			$condition = is_array( $edge['condition'] ?? null ) ? $edge['condition'] : null;

			if ( $this->condition_matches( $condition, $trigger_kind, $trigger_value ) ) {
				return (string) ( $edge['to'] ?? $current_node );
			}
		}

		return $this->advance_linear( $key, $current_node, $trigger_kind, $trigger_value, $context );
	}

	/**
	 * Progress percentage based on node_sequence index (0 at first node, 100 at last).
	 *
	 * @param string               $workflow_enum_or_key Enum or key.
	 * @param string               $current_node         Current node.
	 * @param array<string, mixed> $context              Context.
	 * @return int
	 */
	public function progress_for_node( string $workflow_enum_or_key, string $current_node, array $context = array() ): int {
		$nodes = $this->get_node_sequence( $workflow_enum_or_key, $context );
		$total = count( $nodes );

		if ( $total <= 1 ) {
			return '' !== $current_node ? 100 : 0;
		}

		$index = array_search( $current_node, $nodes, true );

		if ( false === $index ) {
			return 0;
		}

		return (int) round( ( (int) $index / ( $total - 1 ) ) * 100 );
	}

	/**
	 * Linear advancement using internal.progression.
	 *
	 * @param string               $workflow_key  Repository workflow key.
	 * @param string               $current_node  Current node.
	 * @param string               $trigger_kind  Trigger kind.
	 * @param string               $trigger_value Trigger value.
	 * @param array<string, mixed> $context       Context.
	 * @return string
	 */
	private function advance_linear(
		string $workflow_key,
		string $current_node,
		string $trigger_kind,
		string $trigger_value,
		array $context
	): string {
		$steps = $this->progression_steps( $workflow_key, $context );

		foreach ( $steps as $index => $step ) {
			if ( $step['node'] !== $current_node ) {
				continue;
			}

			$next = $steps[ $index + 1 ] ?? null;

			if ( null === $next || null === $next['condition'] ) {
				return $current_node;
			}

			if ( $this->condition_matches( $next['condition'], $trigger_kind, $trigger_value ) ) {
				return $next['node'];
			}

			return $current_node;
		}

		return $current_node;
	}

	/**
	 * Whether a trigger satisfies a JSON condition block.
	 *
	 * @param array<string, string>|null $condition     Condition block.
	 * @param string                     $trigger_kind  Trigger kind.
	 * @param string                     $trigger_value Trigger value.
	 * @return bool
	 */
	private function condition_matches( ?array $condition, string $trigger_kind, string $trigger_value ): bool {
		if ( null === $condition ) {
			return false;
		}

		return (string) ( $condition['kind'] ?? '' ) === $trigger_kind
			&& (string) ( $condition['value'] ?? '' ) === $trigger_value;
	}

	/**
	 * @param string[]             $events    Collected events.
	 * @param array<string, mixed>|null $condition Condition block.
	 * @return void
	 */
	private function collect_event_from_condition( array &$events, $condition ): void {
		if ( ! is_array( $condition ) ) {
			return;
		}

		if ( self::COND_EVENT === ( $condition['kind'] ?? '' ) && ! empty( $condition['value'] ) ) {
			$events[] = (string) $condition['value'];
		}
	}

	/**
	 * @param array<string, mixed> $context Context.
	 * @return bool
	 */
	private function context_has_children( array $context ): bool {
		if ( ! empty( $context['children'] ) ) {
			return true;
		}

		if ( ! empty( $context['has_minor_children'] ) ) {
			return true;
		}

		if ( isset( $context['child_count'] ) && (int) $context['child_count'] > 0 ) {
			return true;
		}

		return false;
	}
}
