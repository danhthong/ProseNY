<?php
/**
 * Workflow State Resolver — single canonical procedural state from facts.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Routing;

use ProSe\Core\Forms\Classification\Vocabulary;
use ProSe\Core\Forms\Engine\Case_Catalog;
use ProSe\Core\Forms\Engine\Workflow_Progression_Service;
use ProSe\Core\Guidance\Guidance_Repository;
use ProSe\Core\Intake\Completion_Calculator;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Workflow_State_Resolver
 */
final class Workflow_State_Resolver {

	/**
	 * @var Workflow_Catalog
	 */
	private Workflow_Catalog $catalog;

	/**
	 * @var Workflow_Progression_Service
	 */
	private Workflow_Progression_Service $progression;

	/**
	 * @var Completion_Calculator
	 */
	private Completion_Calculator $completion;

	/**
	 * @var Guidance_Repository
	 */
	private Guidance_Repository $guidance;

	/**
	 * Constructor.
	 *
	 * @param Workflow_Catalog|null             $catalog     Catalog.
	 * @param Workflow_Progression_Service|null $progression Progression.
	 * @param Completion_Calculator|null      $completion  Completion calculator.
	 * @param Guidance_Repository|null        $guidance    Stage guidance.
	 */
	public function __construct(
		?Workflow_Catalog $catalog = null,
		?Workflow_Progression_Service $progression = null,
		?Completion_Calculator $completion = null,
		?Guidance_Repository $guidance = null
	) {
		$this->catalog     = $catalog ?? new Workflow_Catalog();
		$this->progression = $progression ?? new Workflow_Progression_Service( $this->catalog );
		$this->completion  = $completion ?? new Completion_Calculator();
		$this->guidance    = $guidance ?? new Guidance_Repository();
	}

	/**
	 * Resolve canonical workflow state consumed by all UI and engine surfaces.
	 *
	 * @param array<string, mixed> $input {
	 *     @type string               $workflow             Workflow key.
	 *     @type array<string, mixed> $facts                Plain facts.
	 *     @type string               $procedural_node      Stored procedural node.
	 *     @type array<int, array<string, mixed>> $required_field_defs Workflow required fields.
	 * }
	 * @return array<string, mixed>
	 */
	public function resolve( array $input ): array {
		$workflow      = trim( (string) ( $input['workflow'] ?? '' ) );
		$facts         = is_array( $input['facts'] ?? null ) ? $input['facts'] : array();
		$stored_node            = trim( (string) ( $input['procedural_node'] ?? '' ) );
		$required_defs          = is_array( $input['required_field_defs'] ?? null ) ? $input['required_field_defs'] : array();
		$completed_stage_count  = max( 0, (int) ( $input['completed_stage_count'] ?? 0 ) );

		if ( '' === $workflow ) {
			return $this->empty_state();
		}

		$missing_required = $this->completion->missing_required( $required_defs, $facts );
		$intake_complete  = empty( $missing_required );
		$completion       = $this->completion->calculate( $required_defs, $facts );
		$entry_node       = $this->entry_node( $workflow, $facts );
		$sequence         = $this->progression->get_node_sequence( $workflow, $facts );
		$effective_node   = $this->effective_node( $stored_node, $entry_node, $sequence, $intake_complete, $completed_stage_count );
		$stages           = $this->progression->get_stages( $workflow, $facts );
		$stage_slug       = $this->resolve_current_stage_slug( $workflow, $effective_node, $facts, $completed_stage_count, $stages );

		$guidance = $this->guidance->read_stage( $stage_slug );
		$title    = trim( (string) ( $guidance['title'] ?? '' ) );

		if ( '' === $title ) {
			$title = ucwords( str_replace( '_', ' ', $stage_slug ) );
		}

		$can_advance_stage = $intake_complete && $this->node_can_advance( $workflow, $effective_node, $facts );

		return array(
			'workflow'               => $workflow,
			'procedural_node'        => $effective_node,
			'stored_procedural_node' => $stored_node,
			'current_stage'          => array(
				'id'    => $stage_slug,
				'title' => $title,
				'label' => $title,
			),
			'intake_complete'        => $intake_complete,
			'completion'             => $completion,
			'missing_required'       => array_values( $missing_required ),
			'can_advance_stage'      => $can_advance_stage,
			'entry_node'             => $entry_node,
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function empty_state(): array {
		return array(
			'workflow'               => '',
			'procedural_node'        => '',
			'stored_procedural_node' => '',
			'current_stage'          => null,
			'intake_complete'        => false,
			'completion'             => 0,
			'missing_required'       => array(),
			'can_advance_stage'      => false,
			'entry_node'             => '',
		);
	}

	/**
	 * @param string               $workflow Workflow key.
	 * @param array<string, mixed> $facts    Facts.
	 * @return string
	 */
	private function entry_node( string $workflow, array $facts ): string {
		$entry = Case_Catalog::entry_node( $workflow, $facts );

		if ( '' !== $entry ) {
			return $entry;
		}

		$sequence = $this->progression->get_node_sequence( $workflow, $facts );

		if ( ! empty( $sequence[0] ) ) {
			return (string) $sequence[0];
		}

		return Vocabulary::NODE_1001_DIVORCE_FILED;
	}

	/**
	 * @param string   $stored_node      Stored node.
	 * @param string   $entry_node       Entry node.
	 * @param string[] $sequence         Node sequence.
	 * @param bool     $intake_complete         Whether required intake facts are complete.
	 * @param int      $completed_stage_count   User-confirmed stage completions.
	 * @return string
	 */
	private function effective_node( string $stored_node, string $entry_node, array $sequence, bool $intake_complete, int $completed_stage_count = 0 ): string {
		if ( empty( $sequence ) ) {
			return '' !== $stored_node ? $stored_node : $entry_node;
		}

		$entry_index = array_search( $entry_node, $sequence, true );

		if ( false === $entry_index ) {
			$entry_index = 0;
		}

		if ( '' === $stored_node ) {
			return $entry_node;
		}

		$stored_index = array_search( $stored_node, $sequence, true );

		if ( false === $stored_index ) {
			return $entry_node;
		}

		if ( $stored_index < $entry_index ) {
			return $entry_node;
		}

		if ( ! $intake_complete ) {
			$max_index = (int) $entry_index + $completed_stage_count;

			if ( $stored_index > $max_index ) {
				return (string) ( $sequence[ $max_index ] ?? $entry_node );
			}
		}

		return $stored_node;
	}

	/**
	 * Resolve stage slug from procedural node and user-confirmed completions.
	 *
	 * When multiple UI stages share one node (e.g. calendar + judgment), completion
	 * count selects the active stage so ChatGPT never receives a stale prior stage.
	 *
	 * @param string               $workflow              Workflow key.
	 * @param string               $effective_node        Effective procedural node.
	 * @param array<string, mixed> $facts                 Plain facts.
	 * @param int                  $completed_stage_count Completed stage count.
	 * @param string[]             $stages                Ordered stage slugs.
	 * @return string
	 */
	private function resolve_current_stage_slug(
		string $workflow,
		string $effective_node,
		array $facts,
		int $completed_stage_count,
		array $stages
	): string {
		$node_stage = $this->progression->get_current_stage( $workflow, $effective_node, $facts );

		if ( empty( $stages ) ) {
			return (string) ( $node_stage ?? 'commencement' );
		}

		if ( $completed_stage_count > 0 ) {
			$index            = min( $completed_stage_count, count( $stages ) - 1 );
			$completion_stage = (string) ( $stages[ $index ] ?? '' );

			if ( '' !== $completion_stage ) {
				$node_index         = false !== $node_stage ? array_search( $node_stage, $stages, true ) : false;
				$completion_index   = array_search( $completion_stage, $stages, true );

				if ( false === $node_index || ( false !== $completion_index && $completion_index > $node_index ) ) {
					return $completion_stage;
				}
			}
		}

		if ( null !== $node_stage && '' !== $node_stage ) {
			return $node_stage;
		}

		return (string) ( $stages[0] ?? 'commencement' );
	}

	/**
	 * @param string               $workflow Workflow key.
	 * @param string               $node     Current node.
	 * @param array<string, mixed> $facts    Facts.
	 * @return bool
	 */
	private function node_can_advance( string $workflow, string $node, array $facts ): bool {
		$stage = $this->progression->get_current_stage( $workflow, $node, $facts );

		if ( null === $stage || '' === $stage ) {
			return false;
		}

		$next = $this->progression->get_next_stage( $workflow, $stage, $facts );

		return null !== $next && '' !== $next;
	}
}
