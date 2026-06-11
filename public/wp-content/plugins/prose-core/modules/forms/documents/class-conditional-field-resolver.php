<?php
/**
 * Conditional field resolver — decides which conditional fields are active.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Forms\Documents;

use ProSe\Core\Forms\Engine\Case_Event;
use ProSe\Core\Forms\Engine\Case_State;
use ProSe\Core\Forms\Engine\Condition_Evaluator;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Conditional_Field_Resolver
 *
 * The visibility half of the Conditional Field Engine. Given a form's
 * conditional field definitions and a case, it evaluates each condition and
 * reports which CONDITIONAL fields are active (visible + required) and which
 * are hidden (excluded from completeness and validation).
 *
 * A CONDITIONAL field becomes REQUIRED only when its condition evaluates TRUE.
 * When the condition is FALSE the field is hidden and ignored everywhere.
 *
 * Pure and DB-free; reuses the engine's Condition_Evaluator DSL.
 */
final class Conditional_Field_Resolver {

	/**
	 * Condition evaluator.
	 *
	 * @var Condition_Evaluator
	 */
	private Condition_Evaluator $evaluator;

	/**
	 * Constructor.
	 *
	 * @param Condition_Evaluator|null $evaluator Condition evaluator.
	 */
	public function __construct( ?Condition_Evaluator $evaluator = null ) {
		$this->evaluator = $evaluator ?? new Condition_Evaluator();
	}

	/**
	 * Conditional field keys whose condition currently holds (active /
	 * visible / required) for a form.
	 *
	 * @param string     $form_code Form code.
	 * @param Case_State $state     Case state.
	 * @return string[]
	 */
	public function active_fields( string $form_code, Case_State $state ): array {
		return $this->evaluate_fields( Field_Catalog::conditional_fields( $form_code ), $state, true );
	}

	/**
	 * Conditional field keys whose condition is false (hidden / excluded).
	 *
	 * @param string     $form_code Form code.
	 * @param Case_State $state     Case state.
	 * @return string[]
	 */
	public function hidden_fields( string $form_code, Case_State $state ): array {
		return $this->evaluate_fields( Field_Catalog::conditional_fields( $form_code ), $state, false );
	}

	/**
	 * Whether a specific conditional field is active for a case.
	 *
	 * @param string     $form_code Form code.
	 * @param string     $key       Field key.
	 * @param Case_State $state     Case state.
	 * @return bool
	 */
	public function is_active( string $form_code, string $key, Case_State $state ): bool {
		return in_array( $key, $this->active_fields( $form_code, $state ), true );
	}

	/**
	 * Effective classification for a conditional field: REQUIRED when its
	 * condition holds, otherwise it remains CONDITIONAL (and is excluded).
	 *
	 * @param string     $form_code Form code.
	 * @param string     $key       Field key.
	 * @param Case_State $state     Case state.
	 * @return string
	 */
	public function resolved_class( string $form_code, string $key, Case_State $state ): string {
		return $this->is_active( $form_code, $key, $state )
			? Field_Catalog::CLASS_REQUIRED
			: Field_Catalog::CLASS_CONDITIONAL;
	}

	/**
	 * Evaluate a set of conditional definitions, returning the keys whose
	 * condition matches the requested truth value.
	 *
	 * @param array<int, array{field: string, condition: array<string, mixed>}> $conditionals Conditional defs.
	 * @param Case_State                                                         $state        Case state.
	 * @param bool                                                               $want_active  Desired truth value.
	 * @return string[]
	 */
	private function evaluate_fields( array $conditionals, Case_State $state, bool $want_active ): array {
		$ctx  = $this->context( $state );
		$keys = array();

		foreach ( $conditionals as $conditional ) {
			$key       = (string) ( $conditional['field'] ?? '' );
			$condition = (array) ( $conditional['condition'] ?? array() );

			if ( '' === $key ) {
				continue;
			}

			if ( $this->evaluator->evaluate( $condition, $ctx ) === $want_active ) {
				$keys[] = $key;
			}
		}

		return array_values( array_unique( $keys ) );
	}

	/**
	 * Build the condition-evaluator context for a case.
	 *
	 * @param Case_State $state Case state.
	 * @return array<string, mixed>
	 */
	private function context( Case_State $state ): array {
		$package_states = array();

		foreach ( $state->completed_packages() as $package_key ) {
			$package_states[ $package_key ] = 'COMPLETE';
		}

		foreach ( $state->available_packages() as $package_key ) {
			$package_states[ $package_key ] = 'AVAILABLE';
		}

		return array(
			'answers'        => $state->answers(),
			'events'         => $this->recorded_events( $state ),
			'package_states' => $package_states,
		);
	}

	/**
	 * Recorded event types on a case.
	 *
	 * @param Case_State $state Case state.
	 * @return string[]
	 */
	private function recorded_events( Case_State $state ): array {
		$types = array();

		foreach ( $state->events() as $event ) {
			if ( $event instanceof Case_Event ) {
				$types[] = $event->event_type();
			}
		}

		return $types;
	}
}
