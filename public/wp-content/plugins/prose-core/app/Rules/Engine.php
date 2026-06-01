<?php
/**
 * Forward-chaining procedural rules engine.
 *
 * @package ProseCore
 */

namespace Prose\Core\Rules;

use Prose\Core\Contracts\RuleEvaluatorInterface;
use Prose\Core\Database\Repositories\RuleRepository;
use RuntimeException;

/**
 * Deterministic rules evaluator.
 *
 * Each rule may fire at most once per evaluation. Actions only mark the
 * working facts as "changed" if they actually mutate state — this guarantees
 * the loop converges even when rule conditions remain satisfied after firing.
 */
final class Engine implements RuleEvaluatorInterface {

	private const MAX_ITERATIONS = 32;

	public function __construct(
		private readonly RuleRepository $rules
	) {}

	public function evaluate( Facts $facts, ?int $workflow_id = null, ?int $version = null ): ActionList {
		$rule_rows  = $this->rules->enabled( $workflow_id, $version );
		$json_logic = new JsonLogic();
		$actions    = new ActionList();
		$current    = $facts;
		$fired      = array();
		$iterations = 0;

		do {
			$changed = false;
			$iterations++;

			foreach ( $rule_rows as $row ) {
				$key = ( $row['slug'] ?? '' ) . ':' . ( $row['version'] ?? 1 );

				if ( isset( $fired[ $key ] ) ) {
					continue;
				}

				$conditions   = json_decode( $row['conditions'] ?? '{}', true ) ?: array();
				$rule_actions = json_decode( $row['actions'] ?? '[]', true ) ?: array();

				if ( ! $this->conditions_match( $conditions, $current, $json_logic ) ) {
					continue;
				}

				$fired[ $key ] = true;

				foreach ( $rule_actions as $action ) {
					$normalized = $this->normalize_action( $action );
					$actions->add( $normalized );
					$result = $this->apply_action_to_facts( $current, $normalized );
					if ( $result['changed'] ) {
						$current = $result['facts'];
						$changed = true;
					}
				}
			}
		} while ( $changed && $iterations < self::MAX_ITERATIONS );

		if ( $iterations >= self::MAX_ITERATIONS ) {
			throw new RuntimeException( 'Rules engine exceeded maximum iterations.' );
		}

		return $actions;
	}

	/**
	 * @param array<string, mixed> $conditions
	 */
	private function conditions_match( array $conditions, Facts $facts, JsonLogic $logic ): bool {
		if ( empty( $conditions ) ) {
			return true;
		}

		if ( isset( $conditions['always'] ) && $conditions['always'] ) {
			return true;
		}

		return (bool) $logic->apply( $conditions, $facts->all() );
	}

	/**
	 * @param array<string, mixed> $action
	 * @return array<string, mixed>
	 */
	private function normalize_action( array $action ): array {
		if ( isset( $action['set'] ) ) {
			return array(
				'type'  => 'set',
				'path'  => $action['set'][0] ?? '',
				'value' => $action['set'][1] ?? null,
			);
		}

		if ( isset( $action['attach_forms'] ) ) {
			return array(
				'type'  => 'attach_forms',
				'forms' => (array) $action['attach_forms'],
			);
		}

		if ( isset( $action['goto_node'] ) ) {
			return array(
				'type' => 'goto_node',
				'node' => $action['goto_node'],
			);
		}

		if ( isset( $action['require_question'] ) ) {
			return array(
				'type'     => 'require_question',
				'question' => $action['require_question'],
			);
		}

		return array_merge( array( 'type' => 'unknown' ), $action );
	}

	/**
	 * Apply an action and report whether facts actually changed.
	 *
	 * @param array<string, mixed> $action
	 * @return array{facts: Facts, changed: bool}
	 */
	private function apply_action_to_facts( Facts $facts, array $action ): array {
		if ( ( $action['type'] ?? '' ) !== 'set' ) {
			return array( 'facts' => $facts, 'changed' => false );
		}

		$path = (string) ( $action['path'] ?? '' );
		if ( '' === $path ) {
			return array( 'facts' => $facts, 'changed' => false );
		}

		$existing = $facts->get( $path );
		$new      = $action['value'] ?? null;

		if ( $existing === $new ) {
			return array( 'facts' => $facts, 'changed' => false );
		}

		return array(
			'facts'   => $facts->with_set( $path, $new ),
			'changed' => true,
		);
	}
}
