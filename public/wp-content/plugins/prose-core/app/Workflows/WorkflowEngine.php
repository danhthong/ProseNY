<?php
/**
 * Workflow state machine engine.
 *
 * @package ProseCore
 */

namespace Prose\Core\Workflows;

use Prose\Core\Contracts\RuleEvaluatorInterface;
use Prose\Core\Database\Repositories\EventRepository;
use Prose\Core\Database\Repositories\FactsRepository;
use Prose\Core\Database\Repositories\SessionRepository;
use Prose\Core\Database\Repositories\WorkflowRepository;
use Prose\Core\Forms\FormResolver;
use Prose\Core\Rules\Facts;

final class WorkflowEngine {

	public function __construct(
		private readonly WorkflowRepository $workflows,
		private readonly SessionRepository $sessions,
		private readonly EventRepository $events,
		private readonly RuleEvaluatorInterface $rules,
		private readonly FactsRepository $facts,
		private readonly FormResolver $form_resolver
	) {}

	/**
	 * Advance session state deterministically.
	 *
	 * @return array<string, mixed>
	 */
	public function advance( int $session_id ): array {
		$session = $this->sessions->find( $session_id );
		if ( ! $session ) {
			return array( 'error' => 'session_not_found' );
		}

		$facts_data = $this->facts->get( $session_id );
		$facts      = new Facts( $facts_data );
		$version    = (int) $session['rule_version'];

		$advance_key = $session_id . ':' . ( $facts_data['facts_version'] ?? 1 );
		if ( ( $session['advance_key'] ?? '' ) === $advance_key ) {
			return array( 'skipped' => true, 'reason' => 'idempotent' );
		}

		$actions = $this->rules->evaluate( $facts, (int) ( $session['workflow_id'] ?? 0 ) ?: null, $version );

		$required_forms = $actions->required_forms();
		$goto_node      = $actions->goto_node();

		$new_node_id = (int) ( $session['current_node_id'] ?? 0 );

		if ( $goto_node && $session['workflow_id'] ) {
			$node = $this->workflows->node_by_slug( (int) $session['workflow_id'], $goto_node );
			if ( $node ) {
				$new_node_id = (int) $node['id'];
			}
		} elseif ( ! $new_node_id && $session['workflow_id'] ) {
			$nodes = $this->workflows->nodes( (int) $session['workflow_id'] );
			$new_node_id = (int) ( $nodes[0]['id'] ?? 0 );
		}

		if ( ! $goto_node && $new_node_id && $session['workflow_id'] ) {
			$new_node_id = $this->evaluate_transition( (int) $session['workflow_id'], $new_node_id, $facts );
		}

		$this->sessions->update(
			$session_id,
			array(
				'current_node_id' => $new_node_id ?: null,
				'advance_key'     => $advance_key,
			)
		);

		$this->events->append(
			$session_id,
			'workflow_advanced',
			array(
				'actions'        => $actions->to_array(),
				'required_forms' => $required_forms,
				'current_node'   => $new_node_id,
			)
		);

		return array(
			'current_node_id'  => $new_node_id,
			'required_forms'   => $required_forms,
			'actions'          => $actions->to_array(),
		);
	}

	private function evaluate_transition( int $workflow_id, int $from_node_id, Facts $facts ): int {
		$transitions = $this->workflows->transitions( $workflow_id );
		$logic       = new \Prose\Core\Rules\JsonLogic();

		foreach ( $transitions as $transition ) {
			if ( (int) $transition['from_node_id'] !== $from_node_id ) {
				continue;
			}

			$condition = $transition['condition'] ?? array( 'always' => true );
			if ( $logic->apply( $condition, $facts->all() ) ) {
				return (int) $transition['to_node_id'];
			}
		}

		return $from_node_id;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function get_state( int $session_id ): array {
		$session = $this->sessions->find( $session_id );
		if ( ! $session ) {
			return array();
		}

		$facts = $this->facts->get( $session_id );
		$node  = null;

		if ( $session['current_node_id'] && $session['workflow_id'] ) {
			$nodes = $this->workflows->nodes( (int) $session['workflow_id'] );
			foreach ( $nodes as $n ) {
				if ( (int) $n['id'] === (int) $session['current_node_id'] ) {
					$node = $n;
					break;
				}
			}
		}

		$actions = $this->rules->evaluate(
			new Facts( $facts ),
			(int) ( $session['workflow_id'] ?? 0 ) ?: null,
			(int) $session['rule_version']
		);

		$rule_forms = $actions->required_forms();

		return array(
			'session'         => $session,
			'facts'           => $facts,
			'current_node'    => $node,
			'required_forms'  => $this->form_resolver->resolve(
				$facts,
				array( 'required_forms' => $rule_forms )
			),
			'workflow_id'     => $session['workflow_id'],
		);
	}
}
