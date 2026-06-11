<?php
/**
 * Conditional validation service — validate only active conditional fields.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Forms\Documents;

use ProSe\Core\Forms\Engine\Case_State;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Conditional_Validation_Service
 *
 * The validation half of the Conditional Field Engine. It enforces the rule
 * that a CONDITIONAL field is required only when its condition holds:
 *
 *   - condition TRUE  -> field is required; an unresolved value is an error.
 *   - condition FALSE -> field is hidden and ignored (no error, no slot).
 *
 * Pure and DB-free; delegates condition evaluation to the
 * Conditional_Field_Resolver.
 */
final class Conditional_Validation_Service {

	/**
	 * Conditional field resolver.
	 *
	 * @var Conditional_Field_Resolver
	 */
	private Conditional_Field_Resolver $resolver;

	/**
	 * Constructor.
	 *
	 * @param Conditional_Field_Resolver|null $resolver Conditional field resolver.
	 */
	public function __construct( ?Conditional_Field_Resolver $resolver = null ) {
		$this->resolver = $resolver ?? new Conditional_Field_Resolver();
	}

	/**
	 * Conditional fields that are active (required) for a case.
	 *
	 * @param string     $form_code Form code.
	 * @param Case_State $state     Case state.
	 * @return string[]
	 */
	public function active_fields( string $form_code, Case_State $state ): array {
		return $this->resolver->active_fields( $form_code, $state );
	}

	/**
	 * Conditional fields that are hidden (excluded) for a case.
	 *
	 * @param string     $form_code Form code.
	 * @param Case_State $state     Case state.
	 * @return string[]
	 */
	public function hidden_fields( string $form_code, Case_State $state ): array {
		return $this->resolver->hidden_fields( $form_code, $state );
	}

	/**
	 * Active conditional fields that did not resolve to a value — these are
	 * the conditional validation errors.
	 *
	 * @param string                  $form_code  Form code.
	 * @param Field_Resolution_Result $resolution Resolved fields.
	 * @param Case_State              $state      Case state.
	 * @return string[]
	 */
	public function missing_required( string $form_code, Field_Resolution_Result $resolution, Case_State $state ): array {
		$missing = array();

		foreach ( $this->resolver->active_fields( $form_code, $state ) as $key ) {
			if ( ! $resolution->is_resolved( $key ) ) {
				$missing[] = $key;
			}
		}

		return array_values( array_unique( $missing ) );
	}
}
