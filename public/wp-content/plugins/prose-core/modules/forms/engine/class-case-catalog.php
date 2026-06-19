<?php
/**
 * Case catalog — deterministic, DB-free workflow progression rules.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Forms\Engine;

use ProSe\Core\Forms\Classification\Vocabulary;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Case_Catalog
 *
 * Encodes how a case moves through its workflow: the ordered nodes, the
 * condition (event or package completion) that advances the case into each
 * node, and the ordered package sequence. The data mirrors the seeded
 * workflow graph (see Graph_Seeder) but is expressed as pure PHP so the
 * Case Engine resolves state deterministically without a database, exactly
 * like Node_Resolver and Package_Resolver.
 */
final class Case_Catalog {

	// Case lifecycle events that drive node progression.
	public const EVENT_SERVICE_COMPLETED           = 'SERVICE_COMPLETED';
	public const EVENT_ANSWER_RECEIVED             = 'ANSWER_RECEIVED';
	public const EVENT_HEARING_SCHEDULED           = 'HEARING_SCHEDULED';
	public const EVENT_JUDGMENT_ENTERED            = 'JUDGMENT_ENTERED';
	public const EVENT_PRELIMINARY_CONFERENCE_HELD = 'PRELIMINARY_CONFERENCE_HELD';
	public const EVENT_DISCOVERY_COMPLETE          = 'DISCOVERY_COMPLETE';
	public const EVENT_COMPLIANCE_CONFERENCE_HELD  = 'COMPLIANCE_CONFERENCE_HELD';
	public const EVENT_SETTLEMENT_REACHED          = 'SETTLEMENT_REACHED';

	// Condition kinds for entering a node.
	public const COND_EVENT   = 'event';
	public const COND_PACKAGE = 'package';

	/**
	 * JSON-driven progression service.
	 *
	 * @var Workflow_Progression_Service|null
	 */
	private static ?Workflow_Progression_Service $progression_service = null;

	/**
	 * @return Workflow_Progression_Service
	 */
	private static function progression(): Workflow_Progression_Service {
		if ( null === self::$progression_service ) {
			self::$progression_service = new Workflow_Progression_Service();
		}

		return self::$progression_service;
	}

	/**
	 * Supported lifecycle event types.
	 *
	 * @return string[]
	 */
	public static function events(): array {
		return array_values(
			array_unique(
				array_merge(
					array(
						self::EVENT_SERVICE_COMPLETED,
						self::EVENT_ANSWER_RECEIVED,
						self::EVENT_HEARING_SCHEDULED,
						self::EVENT_JUDGMENT_ENTERED,
						self::EVENT_PRELIMINARY_CONFERENCE_HELD,
						self::EVENT_DISCOVERY_COMPLETE,
						self::EVENT_COMPLIANCE_CONFERENCE_HELD,
						self::EVENT_SETTLEMENT_REACHED,
					),
					self::progression()->registered_events()
				)
			)
		);
	}

	/**
	 * Whether a string is a recognized lifecycle event.
	 *
	 * @param string $event_type Event type.
	 * @return bool
	 */
	public static function is_event( string $event_type ): bool {
		return in_array( $event_type, self::events(), true );
	}

	/**
	 * Ordered node steps for a workflow.
	 *
	 * Each step is array{node: string, condition: array{kind: string, value: string}|null}.
	 * The entry node (index 0) has a null condition. Each later node is
	 * entered when its condition is satisfied — either a lifecycle event or
	 * the completion of a specific package.
	 *
	 * @param string $workflow_key Workflow key.
	 * @return array<int, array{node: string, condition: array{kind: string, value: string}|null}>
	 */
	public static function steps( string $workflow_key, array $context = array() ): array {
		$json_steps = self::progression()->progression_steps( $workflow_key, $context );

		if ( ! empty( $json_steps ) ) {
			return $json_steps;
		}

		return self::legacy_steps( $workflow_key );
	}

	/**
	 * Legacy hardcoded steps retained only when JSON progression is absent.
	 *
	 * @param string $workflow_key Workflow key.
	 * @return array<int, array{node: string, condition: array{kind: string, value: string}|null}>
	 */
	private static function legacy_steps( string $workflow_key ): array {
		switch ( $workflow_key ) {
			case Vocabulary::WF_UNCONTESTED_DIVORCE:
				return array(
					self::step( Vocabulary::NODE_1001_DIVORCE_FILED ),
					self::step( Vocabulary::NODE_1002_SERVICE_COMPLETE, self::COND_EVENT, self::EVENT_SERVICE_COMPLETED ),
					self::step( Vocabulary::NODE_1010_JUDGMENT, self::COND_PACKAGE, Vocabulary::PKG_JUDGMENT ),
				);

			case Vocabulary::WF_CONTESTED_DIVORCE:
				return array(
					self::step( Vocabulary::NODE_1001_DIVORCE_FILED ),
					self::step( Vocabulary::NODE_1002_SERVICE_COMPLETE, self::COND_EVENT, self::EVENT_SERVICE_COMPLETED ),
					self::step( Vocabulary::NODE_1003_ANSWER_FILED, self::COND_EVENT, self::EVENT_ANSWER_RECEIVED ),
					self::step( Vocabulary::NODE_1009_TRIAL, self::COND_EVENT, self::EVENT_HEARING_SCHEDULED ),
					self::step( Vocabulary::NODE_1010_JUDGMENT, self::COND_EVENT, self::EVENT_JUDGMENT_ENTERED ),
				);

			case Vocabulary::WF_DEFAULT_DIVORCE:
				return array(
					self::step( Vocabulary::NODE_1001_DIVORCE_FILED ),
					self::step( Vocabulary::NODE_1002_SERVICE_COMPLETE, self::COND_EVENT, self::EVENT_SERVICE_COMPLETED ),
					self::step( Vocabulary::NODE_1010_JUDGMENT, self::COND_EVENT, self::EVENT_JUDGMENT_ENTERED ),
				);

			case Vocabulary::WF_CUSTODY:
				return array(
					self::step( Vocabulary::NODE_2001_CUSTODY_PETITION ),
					self::step( Vocabulary::NODE_2002_CUSTODY_HEARING, self::COND_EVENT, self::EVENT_HEARING_SCHEDULED ),
					self::step( Vocabulary::NODE_2003_CUSTODY_ORDER, self::COND_EVENT, self::EVENT_JUDGMENT_ENTERED ),
				);

			case Vocabulary::WF_CHILD_SUPPORT:
				return array(
					self::step( Vocabulary::NODE_3001_SUPPORT_PETITION ),
					self::step( Vocabulary::NODE_3002_SUPPORT_ORDER, self::COND_EVENT, self::EVENT_JUDGMENT_ENTERED ),
				);

			case Vocabulary::WF_ORDER_OF_PROTECTION:
				return array(
					self::step( Vocabulary::NODE_4001_FAMILY_OFFENSE ),
					self::step( Vocabulary::NODE_4002_TEMP_OP, self::COND_EVENT, self::EVENT_HEARING_SCHEDULED ),
					self::step( Vocabulary::NODE_4003_FINAL_OP, self::COND_EVENT, self::EVENT_JUDGMENT_ENTERED ),
				);

			default:
				return array();
		}
	}

	/**
	 * Entry node key for a workflow.
	 *
	 * @param string $workflow_key Workflow key.
	 * @return string
	 */
	public static function entry_node( string $workflow_key, array $context = array() ): string {
		$sequence = self::node_sequence( $workflow_key, $context );

		return $sequence[0] ?? '';
	}

	/**
	 * Ordered node keys for a workflow.
	 *
	 * @param string               $workflow_key Workflow key.
	 * @param array<string, mixed> $context      Intake context.
	 * @return string[]
	 */
	public static function node_sequence( string $workflow_key, array $context = array() ): array {
		$from_json = self::progression()->get_node_sequence( $workflow_key, $context );

		if ( ! empty( $from_json ) ) {
			return $from_json;
		}

		return array_map(
			static fn( array $step ): string => $step['node'],
			self::legacy_steps( $workflow_key )
		);
	}

	/**
	 * Whether a node is the terminal node of its workflow.
	 *
	 * @param string               $workflow_key Workflow key.
	 * @param string               $node_key     Node key.
	 * @param array<string, mixed> $context      Intake context.
	 * @return bool
	 */
	public static function is_terminal( string $workflow_key, string $node_key, array $context = array() ): bool {
		$sequence = self::node_sequence( $workflow_key, $context );

		return ! empty( $sequence ) && end( $sequence ) === $node_key;
	}

	/**
	 * Resolve the node a case advances to given its current node and a trigger.
	 *
	 * @param string               $workflow_key  Workflow key.
	 * @param string               $current_node  Current node key.
	 * @param string               $trigger_kind  Trigger kind (event|package).
	 * @param string               $trigger_value Trigger value (event name or package key).
	 * @param array<string, mixed> $context       Intake context.
	 * @return string Resolved node key.
	 */
	public static function advance(
		string $workflow_key,
		string $current_node,
		string $trigger_kind,
		string $trigger_value,
		array $context = array()
	): string {
		if ( ! empty( self::progression()->progression_steps( $workflow_key, $context ) )
			|| ! empty( self::progression()->get_node_sequence( $workflow_key, $context ) ) ) {
			return self::progression()->advance( $workflow_key, $current_node, $trigger_kind, $trigger_value, $context );
		}

		return self::legacy_advance( $workflow_key, $current_node, $trigger_kind, $trigger_value );
	}

	/**
	 * Progress percentage for a node within its workflow (0-100).
	 *
	 * @param string               $workflow_key Workflow key.
	 * @param string               $node_key     Node key.
	 * @param array<string, mixed> $context      Intake context.
	 * @return int
	 */
	public static function progress_for_node( string $workflow_key, string $node_key, array $context = array() ): int {
		if ( ! empty( self::progression()->get_stages( $workflow_key, $context ) ) ) {
			return self::progression()->progress_for_node( $workflow_key, $node_key, $context );
		}

		return self::legacy_progress_for_node( $workflow_key, $node_key );
	}

	/**
	 * @param string $workflow_key Workflow key.
	 * @param string $node_key     Node key.
	 * @return int
	 */
	private static function legacy_progress_for_node( string $workflow_key, string $node_key ): int {
		$sequence = array_map(
			static fn( array $step ): string => $step['node'],
			self::legacy_steps( $workflow_key )
		);
		$total    = count( $sequence );

		if ( $total <= 1 ) {
			return '' !== $node_key ? 100 : 0;
		}

		$index = array_search( $node_key, $sequence, true );

		if ( false === $index ) {
			return 0;
		}

		return (int) round( ( (int) $index / ( $total - 1 ) ) * 100 );
	}

	/**
	 * @param string $workflow_key  Workflow key.
	 * @param string $current_node  Current node.
	 * @param string $trigger_kind  Trigger kind.
	 * @param string $trigger_value Trigger value.
	 * @return string
	 */
	private static function legacy_advance( string $workflow_key, string $current_node, string $trigger_kind, string $trigger_value ): string {
		$steps = self::legacy_steps( $workflow_key );

		foreach ( $steps as $index => $step ) {
			if ( $step['node'] !== $current_node ) {
				continue;
			}

			$next = $steps[ $index + 1 ] ?? null;

			if ( null === $next || null === $next['condition'] ) {
				return $current_node;
			}

			if ( $next['condition']['kind'] === $trigger_kind && $next['condition']['value'] === $trigger_value ) {
				return $next['node'];
			}

			return $current_node;
		}

		return $current_node;
	}

	/**
	 * Ordered package sequence for a workflow, branching on intake answers.
	 *
	 * The first element matches what Package_Resolver returns at intake; the
	 * remaining elements are unlocked one at a time as packages complete.
	 *
	 * @param string               $workflow_key Workflow key.
	 * @param array<string, mixed> $answers      Intake answers.
	 * @return string[]
	 */
	public static function package_sequence( string $workflow_key, array $answers = array() ): array {
		switch ( $workflow_key ) {
			case Vocabulary::WF_UNCONTESTED_DIVORCE:
				$initial = self::has_children( $answers )
					? Vocabulary::PKG_UNCONTESTED_WITH_CHILDREN
					: Vocabulary::PKG_UNCONTESTED_NO_CHILDREN;

				return array( $initial, Vocabulary::PKG_JUDGMENT );

			case Vocabulary::WF_CONTESTED_DIVORCE:
				return array(
					Vocabulary::PKG_CONTESTED_COMMENCEMENT,
					Vocabulary::PKG_DISCOVERY,
					Vocabulary::PKG_TRIAL,
					Vocabulary::PKG_JUDGMENT,
				);

			case Vocabulary::WF_DEFAULT_DIVORCE:
				return array( Vocabulary::PKG_DEFAULT_DIVORCE );

			case Vocabulary::WF_CUSTODY:
				return array( Vocabulary::PKG_CUSTODY_PETITION );

			case Vocabulary::WF_CHILD_SUPPORT:
				return array( Vocabulary::PKG_CHILD_SUPPORT_PETITION );

			case Vocabulary::WF_ORDER_OF_PROTECTION:
				return array( Vocabulary::PKG_ORDER_OF_PROTECTION );

			default:
				return array();
		}
	}

	/**
	 * Initial available packages for a workflow at intake.
	 *
	 * @param string               $workflow_key Workflow key.
	 * @param array<string, mixed> $answers      Intake answers.
	 * @return string[]
	 */
	public static function initial_packages( string $workflow_key, array $answers = array() ): array {
		$sequence = self::package_sequence( $workflow_key, $answers );

		return array() === $sequence ? array() : array( $sequence[0] );
	}

	/**
	 * Package keys unlocked after completing a package.
	 *
	 * Returns the next package in the workflow sequence (if any) that has not
	 * already been completed.
	 *
	 * @param string               $workflow_key       Workflow key.
	 * @param string               $completed_package  Package just completed.
	 * @param string[]             $completed_packages All completed package keys.
	 * @param array<string, mixed> $answers            Intake answers.
	 * @return string[]
	 */
	public static function unlocked_after(
		string $workflow_key,
		string $completed_package,
		array $completed_packages,
		array $answers = array()
	): array {
		$sequence = self::package_sequence( $workflow_key, $answers );
		$index    = array_search( $completed_package, $sequence, true );

		if ( false === $index ) {
			return array();
		}

		$next = $sequence[ $index + 1 ] ?? null;

		if ( null === $next || in_array( $next, $completed_packages, true ) ) {
			return array();
		}

		return array( $next );
	}

	/**
	 * Build a node step definition.
	 *
	 * @param string $node_key   Node key.
	 * @param string $cond_kind  Condition kind (event|package), empty for entry.
	 * @param string $cond_value Condition value.
	 * @return array{node: string, condition: array{kind: string, value: string}|null}
	 */
	private static function step( string $node_key, string $cond_kind = '', string $cond_value = '' ): array {
		return array(
			'node'      => $node_key,
			'condition' => '' === $cond_kind
				? null
				: array(
					'kind'  => $cond_kind,
					'value' => $cond_value,
				),
		);
	}

	/**
	 * Whether intake answers indicate minor children.
	 *
	 * @param array<string, mixed> $answers Intake answers.
	 * @return bool
	 */
	private static function has_children( array $answers ): bool {
		$value = $answers['children'] ?? null;

		if ( is_bool( $value ) ) {
			return $value;
		}

		if ( is_int( $value ) || is_float( $value ) ) {
			return 0 !== (int) $value;
		}

		if ( is_string( $value ) ) {
			$token = strtolower( trim( $value ) );

			return in_array( $token, array( 'true', '1', 'yes', 'y', 'on' ), true );
		}

		return false;
	}
}
