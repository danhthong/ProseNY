<?php
/**
 * Required Fields Provider — deterministic required field authority.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Ai_Intake;

use ProSe\Core\Intake\Completion_Calculator;
use ProSe\Core\Routing\Case_Profile;
use ProSe\Core\Routing\Routing_Engine;
use ProSe\Core\Routing\Workflow_Catalog;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Required_Fields_Provider
 */
final class Required_Fields_Provider {

	/**
	 * Routing discriminator priorities.
	 *
	 * @var array<string, int>
	 */
	private const ROUTING_PRIORITIES = array(
		'children'                  => 95,
		'spouse_agrees'             => 90,
		'marital_property_resolved' => 88,
		'spouse_responded'          => 85,
		'active_divorce'            => 80,
		'protection_needed'         => 75,
	);

	/**
	 * Base priority for workflow fields.
	 *
	 * @var array<string, int>
	 */
	private const FIELD_PRIORITIES = array(
		'county'                => 100,
		'issue'                 => 98,
		'residency_qualification' => 96,
		'has_minor_children'    => 90,
		'children'              => 90,
		'child_count'           => 88,
		'spouse_agrees'         => 85,
		'marriage_date'         => 70,
		'marriage_location'   => 69,
		'separation_date'       => 68,
		'grounds_for_divorce'   => 65,
		'plaintiff_information' => 60,
		'defendant_information' => 58,
		'petitioner_information' => 60,
		'respondent_information' => 58,
	);

	/**
	 * Routing engine.
	 *
	 * @var Routing_Engine
	 */
	private Routing_Engine $routing;

	/**
	 * Workflow catalog.
	 *
	 * @var Workflow_Catalog
	 */
	private Workflow_Catalog $catalog;

	/**
	 * Completion calculator.
	 *
	 * @var Completion_Calculator
	 */
	private Completion_Calculator $completion;

	/**
	 * Constructor.
	 *
	 * @param Routing_Engine|null        $routing    Routing engine.
	 * @param Workflow_Catalog|null      $catalog    Workflow catalog.
	 * @param Completion_Calculator|null $completion Completion calculator.
	 */
	public function __construct(
		?Routing_Engine $routing = null,
		?Workflow_Catalog $catalog = null,
		?Completion_Calculator $completion = null
	) {
		$this->catalog    = $catalog ?? new Workflow_Catalog();
		$this->routing    = $routing ?? new Routing_Engine( $this->catalog );
		$this->completion = $completion ?? new Completion_Calculator();
	}

	/**
	 * Resolve routing and return priority-ordered required fields.
	 *
	 * @param Intake_State $state       Intake state.
	 * @param string       $message     Latest user message.
	 * @return array{
	 *   fields: array<int, array{field: string, priority: int, question?: string, type?: string}>,
	 *   workflow: ?string,
	 *   issue: ?string,
	 *   court: ?string,
	 *   routing_missing: string[],
	 *   required_field_defs: array<int, array<string, mixed>>
	 * }
	 */
	public function resolve( Intake_State $state, string $message ): array {
		$profile = $this->build_profile( $state );
		$result  = $this->routing->route_profile( $message, $profile );

		$workflow = $profile->workflow();
		$prior    = $state->workflow();

		// Retain a prior workflow only while routing is momentarily ambiguous AND
		// the prior workflow still belongs to the current issue. If the user
		// switched matters (e.g. child support -> divorce), the old workflow must
		// not stick — otherwise the package preview keeps showing the wrong forms.
		if ( ( null === $workflow || '' === $workflow ) && null !== $prior && '' !== $prior
			&& $this->workflow_matches_issue( $prior, $profile->issue() ) ) {
			$workflow = $prior;
		}

		$state->set_workflow( $workflow );
		$state->set_issue( $profile->issue() );
		$state->set_court( $profile->court() );

		$routing_missing = $result->missing_fields();
		$required_defs   = $this->required_field_defs( $workflow );
		$plain_facts     = $state->plain_facts();

		$this->sync_routing_facts( $plain_facts, $required_defs, $state );

		$fields = array();

		if ( null === $workflow || '' === $workflow ) {
			foreach ( $routing_missing as $key ) {
				$fields[] = array(
					'field'    => $key,
					'priority' => self::ROUTING_PRIORITIES[ $key ] ?? 50,
					'question' => $this->resolution_question( $key ),
					'type'     => $this->infer_type( $key ),
				);
			}
		} else {
			$missing_keys = $this->completion->missing_required( $required_defs, $plain_facts );

			foreach ( $required_defs as $def ) {
				$key = (string) ( $def['key'] ?? '' );

				if ( '' === $key || ! in_array( $key, $missing_keys, true ) ) {
					continue;
				}

				$fields[] = array(
					'field'    => $key,
					'priority' => $this->priority_for( $key ),
					'question' => (string) ( $def['question'] ?? '' ),
					'type'     => (string) ( $def['type'] ?? 'string' ),
				);
			}
		}

		usort(
			$fields,
			static function ( array $a, array $b ): int {
				return $b['priority'] <=> $a['priority'];
			}
		);

		return array(
			'fields'              => $fields,
			'workflow'            => $workflow,
			'issue'               => $profile->issue(),
			'court'               => $profile->court(),
			'routing_missing'     => $routing_missing,
			'required_field_defs' => $required_defs,
			'extraction_defs'     => $this->extraction_defs( $workflow, $routing_missing, $result->candidate_workflows() ),
		);
	}

	/**
	 * Field definitions used for bulk extraction (broader than missing-only).
	 *
	 * @param string|null $workflow            Resolved workflow.
	 * @param string[]    $routing_missing     Routing missing keys.
	 * @param string[]    $candidate_workflows Candidate workflows.
	 * @return array<int, array<string, mixed>>
	 */
	public function extraction_defs( ?string $workflow, array $routing_missing, array $candidate_workflows ): array {
		$defs = array();
		$seen = array();

		if ( null !== $workflow && '' !== $workflow ) {
			foreach ( $this->required_field_defs( $workflow ) as $def ) {
				$key = (string) ( $def['key'] ?? '' );

				if ( '' === $key || isset( $seen[ $key ] ) ) {
					continue;
				}

				$seen[ $key ] = true;
				$defs[]       = $def;
			}
		}

		foreach ( $candidate_workflows as $candidate ) {
			foreach ( $this->required_field_defs( (string) $candidate ) as $def ) {
				$key = (string) ( $def['key'] ?? '' );

				if ( '' === $key || isset( $seen[ $key ] ) ) {
					continue;
				}

				$seen[ $key ] = true;
				$defs[]       = $def;
			}
		}

		foreach ( $routing_missing as $key ) {
			$key = (string) $key;

			if ( '' === $key || isset( $seen[ $key ] ) ) {
				continue;
			}

			$seen[ $key ] = true;
			$defs[]       = array(
				'key'      => $key,
				'type'     => $this->infer_type( $key ),
				'required' => true,
				'question' => $this->resolution_question( $key ),
			);
		}

		return $defs;
	}

	/**
	 * Get missing fields as priority-ordered field keys.
	 *
	 * @param array<int, array{field: string, priority: int}> $fields Priority fields.
	 * @param Intake_State                                      $state  Intake state.
	 * @return array<int, array{field: string, priority: int}>
	 */
	public function missing_prioritized( array $fields, Intake_State $state ): array {
		$missing = array();

		foreach ( $fields as $field ) {
			$key = (string) $field['field'];

			if ( ! $state->is_filled( $key ) ) {
				$missing[] = $field;
			}
		}

		return $missing;
	}

	/**
	 * Fields the intake chat may ask — routing discriminators only.
	 *
	 * Document-phase fields (county, names, dates, case status, etc.) are
	 * collected when the user fills forms later, not in the routing chat.
	 *
	 * @param array<int, array<string, mixed>> $missing  Priority-ordered missing fields.
	 * @param string|null                      $workflow Resolved workflow key.
	 * @return array<int, array<string, mixed>>
	 */
	public function conversation_missing_fields( array $missing, ?string $workflow ): array {
		if ( null !== $workflow && '' !== $workflow ) {
			return array();
		}

		$routing_keys = array_flip( $this->routing_field_keys() );

		return array_values(
			array_filter(
				$missing,
				static function ( array $field ) use ( $routing_keys ): bool {
					$key = (string) ( $field['field'] ?? '' );

					return '' !== $key && isset( $routing_keys[ $key ] );
				}
			)
		);
	}

	/**
	 * Routing discriminator keys used before workflow resolution.
	 *
	 * @return string[]
	 */
	public function routing_field_keys(): array {
		return array_keys( self::ROUTING_PRIORITIES );
	}

	/**
	 * Build a Case_Profile from intake state.
	 *
	 * @param Intake_State $state Intake state.
	 * @return Case_Profile
	 */
	private function build_profile( Intake_State $state ): Case_Profile {
		$data = $state->to_case_profile();
		$data['facts'] = $state->plain_facts();

		return Case_Profile::from_array( $data );
	}

	/**
	 * Load workflow required field definitions.
	 *
	 * @param string|null $workflow Workflow key.
	 * @return array<int, array<string, mixed>>
	 */
	private function required_field_defs( ?string $workflow ): array {
		if ( null === $workflow || '' === $workflow ) {
			return array();
		}

		$definition = $this->catalog->by_key( $workflow );

		if ( null === $definition ) {
			return array();
		}

		$fields = $definition['required_fields'] ?? array();

		return is_array( $fields ) ? $fields : array();
	}

	/**
	 * Priority for a field key.
	 *
	 * @param string $key Field key.
	 * @return int
	 */
	private function priority_for( string $key ): int {
		return self::FIELD_PRIORITIES[ $key ] ?? 50;
	}

	/**
	 * Resolution question for routing discriminator.
	 *
	 * @param string $key Discriminator key.
	 * @return string
	 */
	private function resolution_question( string $key ): string {
		$map = array(
			'children'                  => 'Do you have any children under 21?',
			'spouse_agrees'             => 'Does your spouse agree to the divorce?',
			'marital_property_resolved' => 'Do you and your spouse agree on property and finances?',
			'spouse_responded'          => 'Did your spouse respond to the divorce papers?',
			'active_divorce'            => 'Is there an active divorce case?',
			'protection_needed'         => 'Do you need protection from someone who has harmed or threatened you?',
		);

		return (string) ( $map[ $key ] ?? 'Could you tell me a bit more about your situation?' );
	}

	/**
	 * Infer field type for routing discriminators.
	 *
	 * @param string $key Field key.
	 * @return string
	 */
	private function infer_type( string $key ): string {
		if ( in_array( $key, array( 'children', 'spouse_agrees', 'marital_property_resolved', 'spouse_responded', 'active_divorce', 'protection_needed' ), true ) ) {
			return 'boolean';
		}

		if ( 'child_count' === $key ) {
			return 'integer';
		}

		return 'string';
	}

	/**
	 * Whether a workflow still belongs to the currently resolved issue.
	 *
	 * @param string      $workflow Workflow key.
	 * @param string|null $issue    Resolved issue.
	 * @return bool
	 */
	private function workflow_matches_issue( string $workflow, ?string $issue ): bool {
		if ( null === $issue || '' === $issue ) {
			return true;
		}

		$definition = $this->catalog->by_key( $workflow );

		if ( null === $definition ) {
			return false;
		}

		$workflow_issue = (string) ( $definition['issue_type'] ?? '' );

		return $this->base_issue( $workflow_issue ) === $this->base_issue( $issue );
	}

	/**
	 * Reduce an issue to its base form (divorce* -> divorce).
	 *
	 * @param string $issue Issue type.
	 * @return string
	 */
	private function base_issue( string $issue ): string {
		return str_starts_with( $issue, 'divorce' ) ? 'divorce' : $issue;
	}

	/**
	 * Mirror routing facts onto workflow keys.
	 *
	 * @param array<string, mixed>             $plain_facts Plain facts.
	 * @param array<int, array<string, mixed>>   $required    Required defs.
	 * @param Intake_State                       $state       State to update.
	 * @return void
	 */
	private function sync_routing_facts( array $plain_facts, array $required, Intake_State $state ): void {
		$keys = array();

		foreach ( $required as $field ) {
			$key = (string) ( $field['key'] ?? '' );

			if ( '' !== $key ) {
				$keys[ $key ] = true;
			}
		}

		if ( isset( $keys['has_minor_children'] ) && isset( $plain_facts['children'] ) && ! isset( $plain_facts['has_minor_children'] ) ) {
			$state->merge_updates(
				array(
					'has_minor_children' => array(
						'value'      => (bool) $plain_facts['children'],
						'confidence' => 1.0,
					),
				)
			);
		}
	}
}
