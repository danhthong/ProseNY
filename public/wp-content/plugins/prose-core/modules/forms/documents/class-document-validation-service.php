<?php
/**
 * Document validation service.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Forms\Documents;

use ProSe\Core\Forms\Classification\Vocabulary;
use ProSe\Core\Forms\Engine\Case_Event;
use ProSe\Core\Forms\Engine\Case_State;
use ProSe\Core\Forms\Engine\Condition_Evaluator;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Document_Validation_Service
 *
 * Validates a resolved form against five rule classes:
 *   - required fields        (must be resolved)
 *   - conditional fields     (required only when their condition holds)
 *   - workflow requirements  (lifecycle events that must be recorded)
 *   - package requirements   (the form belongs to the package's form set)
 *   - county requirements    (a filing county is present when the form needs one)
 *
 * Pure and DB-free; reuses the engine's Condition_Evaluator for the
 * conditional-field DSL.
 */
final class Document_Validation_Service {

	/**
	 * Condition evaluator.
	 *
	 * @var Condition_Evaluator
	 */
	private Condition_Evaluator $evaluator;

	/**
	 * Conditional field/validation engine.
	 *
	 * @var Conditional_Validation_Service
	 */
	private Conditional_Validation_Service $conditional;

	/**
	 * Constructor.
	 *
	 * @param Condition_Evaluator|null            $evaluator   Condition evaluator.
	 * @param Conditional_Validation_Service|null $conditional Conditional engine.
	 */
	public function __construct(
		?Condition_Evaluator $evaluator = null,
		?Conditional_Validation_Service $conditional = null
	) {
		$this->evaluator   = $evaluator ?? new Condition_Evaluator();
		$this->conditional = $conditional ?? new Conditional_Validation_Service(
			new Conditional_Field_Resolver( $this->evaluator )
		);
	}

	/**
	 * Validate a resolved form.
	 *
	 * @param string                  $form_code   Form code.
	 * @param Field_Resolution_Result $resolution  Resolved fields.
	 * @param Case_State              $state       Case state.
	 * @param string                  $package_key Source package key (optional).
	 * @return Document_Validation_Result
	 */
	public function validate(
		string $form_code,
		Field_Resolution_Result $resolution,
		Case_State $state,
		string $package_key = ''
	): Document_Validation_Result {
		$missing_required    = $this->check_required( $form_code, $resolution );
		$missing_conditional = $this->check_conditional( $form_code, $resolution, $state );
		$workflow_errors     = $this->check_workflow( $form_code, $state );
		$package_errors      = $this->check_package( $form_code, $package_key );
		$county_errors       = $this->check_county( $form_code, $resolution );

		return new Document_Validation_Result(
			$missing_required,
			$missing_conditional,
			$workflow_errors,
			$package_errors,
			$county_errors
		);
	}

	/**
	 * Conditional field keys whose condition currently holds (and are
	 * therefore required for this case).
	 *
	 * @param string     $form_code Form code.
	 * @param Case_State $state     Case state.
	 * @return string[]
	 */
	public function active_conditional_fields( string $form_code, Case_State $state ): array {
		return $this->conditional->active_fields( $form_code, $state );
	}

	/**
	 * Conditional field keys whose condition is false (hidden / excluded).
	 *
	 * @param string     $form_code Form code.
	 * @param Case_State $state     Case state.
	 * @return string[]
	 */
	public function hidden_conditional_fields( string $form_code, Case_State $state ): array {
		return $this->conditional->hidden_fields( $form_code, $state );
	}

	/**
	 * Required field check.
	 *
	 * Only fields classified as REQUIRED gate validation. OPTIONAL,
	 * COURT_ASSIGNED and SYSTEM_GENERATED fields are excluded; CONDITIONAL
	 * fields are handled separately by check_conditional().
	 *
	 * @param string                  $form_code  Form code.
	 * @param Field_Resolution_Result $resolution Resolved fields.
	 * @return string[]
	 */
	private function check_required( string $form_code, Field_Resolution_Result $resolution ): array {
		$missing = array();

		foreach ( Field_Catalog::classes_for( $form_code ) as $key => $field_class ) {
			if ( Field_Catalog::CLASS_REQUIRED !== $field_class ) {
				continue;
			}

			if ( ! $resolution->is_resolved( $key ) ) {
				$missing[] = $key;
			}
		}

		return $missing;
	}

	/**
	 * Conditional field check.
	 *
	 * @param string                  $form_code  Form code.
	 * @param Field_Resolution_Result $resolution Resolved fields.
	 * @param Case_State              $state      Case state.
	 * @return string[]
	 */
	private function check_conditional( string $form_code, Field_Resolution_Result $resolution, Case_State $state ): array {
		return $this->conditional->missing_required( $form_code, $resolution, $state );
	}

	/**
	 * Workflow requirement check (required lifecycle events recorded).
	 *
	 * @param string     $form_code Form code.
	 * @param Case_State $state     Case state.
	 * @return string[]
	 */
	private function check_workflow( string $form_code, Case_State $state ): array {
		$recorded = $this->recorded_events( $state );
		$errors   = array();

		foreach ( Field_Catalog::workflow_requirements( $form_code ) as $event_type ) {
			if ( ! in_array( $event_type, $recorded, true ) ) {
				$errors[] = $event_type;
			}
		}

		return $errors;
	}

	/**
	 * Package requirement check (form belongs to the package's form set).
	 *
	 * @param string $form_code   Form code.
	 * @param string $package_key Package key.
	 * @return string[]
	 */
	private function check_package( string $form_code, string $package_key ): array {
		if ( '' === $package_key ) {
			return array();
		}

		$catalog    = Vocabulary::package_catalog();
		$definition = $catalog[ $package_key ] ?? null;

		if ( null === $definition ) {
			return array();
		}

		$forms = array_merge(
			(array) ( $definition['required_forms'] ?? array() ),
			(array) ( $definition['optional_forms'] ?? array() ),
			(array) ( $definition['supporting_documents'] ?? array() )
		);

		if ( ! in_array( $form_code, $forms, true ) ) {
			return array( 'form_not_in_package' );
		}

		return array();
	}

	/**
	 * County requirement check.
	 *
	 * @param string                  $form_code  Form code.
	 * @param Field_Resolution_Result $resolution Resolved fields.
	 * @return string[]
	 */
	private function check_county( string $form_code, Field_Resolution_Result $resolution ): array {
		$requires_county = in_array( 'county', Field_Catalog::required_fields( $form_code ), true );

		if ( $requires_county && ! $resolution->is_resolved( 'county' ) ) {
			return array( 'county_required' );
		}

		return array();
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
