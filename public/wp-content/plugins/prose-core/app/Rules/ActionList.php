<?php
/**
 * Collected rule actions after evaluation.
 *
 * @package ProseCore
 */

namespace Prose\Core\Rules;

final class ActionList {

	/**
	 * @param array<int, array<string, mixed>> $actions
	 */
	public function __construct(
		private array $actions = array()
	) {}

	public function add( array $action ): void {
		$this->actions[] = $action;
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public function all(): array {
		return $this->actions;
	}

	/**
	 * @return array<int, string>
	 */
	public function required_forms(): array {
		$forms = array();
		foreach ( $this->actions as $action ) {
			if ( ( $action['type'] ?? '' ) === 'attach_forms' ) {
				$forms = array_merge( $forms, $action['forms'] ?? array() );
			}
		}
		return array_values( array_unique( $forms ) );
	}

	public function goto_node(): ?string {
		foreach ( $this->actions as $action ) {
			if ( ( $action['type'] ?? '' ) === 'goto_node' ) {
				return $action['node'] ?? null;
			}
		}
		return null;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array(
			'actions'        => $this->actions,
			'required_forms' => $this->required_forms(),
			'goto_node'      => $this->goto_node(),
		);
	}
}
