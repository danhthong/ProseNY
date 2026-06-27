<?php
/**
 * Workflow form applicability — JSON required_when + procedural overrides.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Forms\Engine;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Workflow_Form_Applicability_Service
 */
final class Workflow_Form_Applicability_Service {

	/**
	 * Initial filing papers (skipped when case already commenced).
	 *
	 * @var string[]
	 */
	private const COMMENCEMENT_FORMS = array(
		'UD-1',
		'UD-2',
		'UD-1a',
		'AUTO-ORDERS',
	);

	/**
	 * Service papers (skipped when service is complete).
	 *
	 * @var string[]
	 */
	private const SERVICE_FORMS = array(
		'UD-3',
	);

	/**
	 * Evaluate whether a form should be offered/generated for the user.
	 *
	 * @param array<string, mixed> $form     Form row from workflow JSON.
	 * @param string               $workflow Workflow key.
	 * @param string               $stage    Stage slug.
	 * @param array<string, mixed> $context  Plain facts.
	 * @return array{applicable: bool, uncertain: bool, reason: string}
	 */
	public function evaluate( array $form, string $workflow, string $stage, array $context = array() ): array {
		$code          = strtoupper( trim( (string) ( $form['code'] ?? '' ) ) );
		$workflow      = trim( $workflow );
		$stage         = sanitize_key( $stage );
		$required_when = trim( (string) ( $form['required_when'] ?? '' ) );

		if ( '' === $code ) {
			return $this->skip( __( 'Form code is missing.', 'prose-core' ) );
		}

		$procedural = $this->evaluate_procedural_overrides( $code, $context );

		if ( null !== $procedural ) {
			return $procedural;
		}

		if ( '' !== $required_when ) {
			return $this->evaluate_required_when( $required_when, $code, $workflow, $stage, $context );
		}

		return $this->evaluate_legacy_code_rules( $code, $workflow, $stage, $context );
	}

	/**
	 * Filter stage forms to those that apply; annotate each row.
	 *
	 * @param array<int, array<string, mixed>> $forms    Raw stage forms.
	 * @param string                           $workflow Workflow key.
	 * @param string                           $stage    Stage slug.
	 * @param array<string, mixed>             $context  Facts.
	 * @return array{applicable: array<int, array<string, mixed>>, pending: array<int, array<string, mixed>>, skipped: array<int, array<string, mixed>>}
	 */
	public function partition_stage_forms( array $forms, string $workflow, string $stage, array $context = array() ): array {
		$applicable = array();
		$pending    = array();
		$skipped    = array();

		foreach ( $forms as $form ) {
			if ( ! is_array( $form ) ) {
				continue;
			}

			$code  = trim( (string) ( $form['code'] ?? '' ) );
			$title = trim( (string) ( $form['title'] ?? $code ) );

			if ( '' === $code ) {
				continue;
			}

			$result = $this->evaluate( $form, $workflow, $stage, $context );
			$row    = array(
				'code'          => $code,
				'title'         => $title,
				'required'      => ! empty( $form['required'] ) && $result['applicable'],
				'required_when' => trim( (string) ( $form['required_when'] ?? '' ) ),
				'applicable'    => $result['applicable'],
				'uncertain'     => $result['uncertain'],
			);

			if ( $result['applicable'] ) {
				$applicable[] = $row;
				continue;
			}

			if ( $result['uncertain'] ) {
				$pending[] = array_merge(
					$row,
					array(
						'reason' => $result['reason'],
					)
				);
				continue;
			}

			$skipped[] = array_merge(
				$row,
				array(
					'reason' => $result['reason'],
				)
			);
		}

		return array(
			'applicable' => $applicable,
			'pending'    => $pending,
			'skipped'    => $skipped,
		);
	}

	/**
	 * @param string               $required_when Condition token from workflow JSON.
	 * @param string               $code          Form code.
	 * @param string               $workflow      Workflow key.
	 * @param string               $stage         Stage slug.
	 * @param array<string, mixed> $context       Facts.
	 * @return array{applicable: bool, uncertain: bool, reason: string}
	 */
	private function evaluate_required_when(
		string $required_when,
		string $code,
		string $workflow,
		string $stage,
		array $context
	): array {
		$token = strtolower( trim( $required_when ) );

		if ( 'always' === $token ) {
			return $this->include_form();
		}

		if ( 'religious_barrier_exists' === $token ) {
			return $this->evaluate_religious_barrier( $context );
		}

		if ( 'defendant_executes_affirmation' === $token ) {
			return $this->evaluate_defendant_affirmation( $workflow, $context );
		}

		if ( 'defendant_not_participating' === $token ) {
			return str_starts_with( $workflow, 'default_divorce' )
				? $this->include_form()
				: $this->skip( __( 'This form applies only when the defendant did not participate in the case.', 'prose-core' ) );
		}

		if ( in_array( $token, array( 'has_minor_children', 'has_minor_children == true', 'has_minor_children=true' ), true ) ) {
			return $this->evaluate_has_minor_children( $context );
		}

		if ( 'maintenance_requested' === $token ) {
			return $this->evaluate_maintenance_requested( $context );
		}

		if ( 'qmsco_required' === $token ) {
			return $this->evaluate_qmsco_required( $context );
		}

		if ( 'child_support_services_enrollment' === $token ) {
			return $this->evaluate_child_support_enrollment( $context );
		}

		if ( in_array( $token, array( 'existing_order_exists', 'existing_support_order', 'support_agreement_exists', 'address_confidentiality_requested', 'prior_family_court_case' ), true ) ) {
			return $this->evaluate_boolean_fact( $token, $context );
		}

		if ( in_array( $token, array( 'final_papers_ready', 'court_approves_package' ), true ) ) {
			return $this->include_form();
		}

		if ( str_contains( $token, '=' ) ) {
			return $this->evaluate_fact_condition( $required_when, $context );
		}

		return $this->include_form();
	}

	/**
	 * Procedural timing overrides that apply regardless of required_when.
	 *
	 * @param string               $code    Form code.
	 * @param array<string, mixed> $context Facts.
	 * @return array{applicable: bool, uncertain: bool, reason: string}|null
	 */
	private function evaluate_procedural_overrides( string $code, array $context ): ?array {
		if ( $this->is_commencement_form( $code ) && $this->case_already_commenced( $context ) ) {
			return $this->skip(
				__( 'Your divorce case has already been started, so initial filing papers are not regenerated for this step.', 'prose-core' )
			);
		}

		if ( $this->is_service_form( $code ) && $this->service_already_completed( $context ) ) {
			return $this->skip(
				__( 'Service appears to be complete, so the Affirmation of Service is not needed again unless you are correcting service.', 'prose-core' )
			);
		}

		return null;
	}

	/**
	 * Legacy rules for workflows without required_when metadata.
	 *
	 * @param string               $code     Form code.
	 * @param string               $workflow Workflow key.
	 * @param string               $stage    Stage slug.
	 * @param array<string, mixed> $context  Facts.
	 * @return array{applicable: bool, uncertain: bool, reason: string}
	 */
	private function evaluate_legacy_code_rules( string $code, string $workflow, string $stage, array $context ): array {
		if ( 'UD-7' === $code ) {
			return $this->evaluate_defendant_affirmation( $workflow, $context );
		}

		if ( 'UD-4' === $code ) {
			return $this->evaluate_religious_barrier( $context );
		}

		if ( in_array( $code, array( 'UD-8(1)', 'UD-8(3)', 'UD-8a', 'UD-8b', 'LDSS-5258' ), true ) ) {
			return $this->evaluate_has_minor_children( $context );
		}

		if ( 'UD-8(2)' === $code ) {
			return $this->evaluate_maintenance_requested( $context );
		}

		return $this->include_form();
	}

	/**
	 * @param array<string, mixed> $context Facts.
	 * @return array{applicable: bool, uncertain: bool, reason: string}
	 */
	private function evaluate_religious_barrier( array $context = array() ): array {
		$barriers = $this->barriers_to_remarriage( $context );

		if ( false === $barriers ) {
			return $this->skip(
				__( 'UD-4 generally applies only when removal of barriers to remarriage is required (for example, certain religious marriages).', 'prose-core' )
			);
		}

		if ( null === $barriers ) {
			return $this->uncertain(
				__( 'UD-4 may be needed only for certain religious marriages — please say whether your marriage was religious or performed by a judge or civil officiant.', 'prose-core' )
			);
		}

		return $this->include_form();
	}

	/**
	 * @param string               $workflow Workflow key.
	 * @param array<string, mixed> $context  Facts.
	 * @return array{applicable: bool, uncertain: bool, reason: string}
	 */
	private function evaluate_defendant_affirmation( string $workflow, array $context ): array {
		if ( str_starts_with( $workflow, 'default_divorce' ) ) {
			return $this->skip(
				__( 'The defendant did not participate in this default divorce, so a Defendant Affirmation is not used.', 'prose-core' )
			);
		}

		if ( $this->defendant_affidavit_completed( $context ) ) {
			return $this->skip(
				__( 'The defendant affidavit appears to have already been completed.', 'prose-core' )
			);
		}

		if ( array_key_exists( 'defendant_executes_affirmation', $context ) ) {
			return $this->to_bool( $context['defendant_executes_affirmation'] )
				? $this->include_form()
				: $this->skip( __( 'The defendant is not executing an affirmation in this case.', 'prose-core' ) );
		}

		if ( ! empty( $context['spouse_agrees'] ) ) {
			return $this->uncertain(
				__( 'Please confirm whether your spouse will sign the Defendant Affirmation (UD-7).', 'prose-core' )
			);
		}

		return $this->uncertain(
			__( 'UD-7 applies only when the defendant executes an affirmation — please confirm whether your spouse will sign.', 'prose-core' )
		);
	}

	/**
	 * @param array<string, mixed> $context Facts.
	 * @return array{applicable: bool, uncertain: bool, reason: string}
	 */
	private function evaluate_has_minor_children( array $context = array() ): array {
		if ( $this->has_no_minor_children( $context ) ) {
			return $this->skip(
				__( 'You indicated there are no children under 21.', 'prose-core' )
			);
		}

		if ( ! $this->has_minor_children( $context ) ) {
			return $this->uncertain(
				__( 'Child-related forms apply only when there are children under 21 — please confirm whether any children are involved.', 'prose-core' )
			);
		}

		return $this->include_form();
	}

	/**
	 * @param array<string, mixed> $context Facts.
	 * @return array{applicable: bool, uncertain: bool, reason: string}
	 */
	private function evaluate_maintenance_requested( array $context = array() ): array {
		if ( $this->maintenance_waived( $context ) ) {
			return $this->skip(
				__( 'Maintenance is not being requested.', 'prose-core' )
			);
		}

		if ( ! $this->maintenance_requested( $context ) ) {
			return $this->uncertain(
				__( 'The Maintenance Guidelines Worksheet applies only if spousal maintenance is requested — please confirm whether maintenance is part of your case.', 'prose-core' )
			);
		}

		return $this->include_form();
	}

	/**
	 * @param array<string, mixed> $context Facts.
	 * @return array{applicable: bool, uncertain: bool, reason: string}
	 */
	private function evaluate_qmsco_required( array $context ): array {
		if ( $this->has_no_minor_children( $context ) ) {
			return $this->skip(
				__( 'You indicated there are no children under 21.', 'prose-core' )
			);
		}

		if ( ! empty( $context['qmsco_required'] ) ) {
			return $this->include_form();
		}

		if ( ! $this->has_minor_children( $context ) ) {
			return $this->uncertain(
				__( 'UD-8b applies only when a Qualified Medical Child Support Order is required.', 'prose-core' )
			);
		}

		return $this->uncertain(
			__( 'UD-8b applies only when a Qualified Medical Child Support Order is required.', 'prose-core' )
		);
	}

	/**
	 * @param array<string, mixed> $context Facts.
	 * @return array{applicable: bool, uncertain: bool, reason: string}
	 */
	private function evaluate_child_support_enrollment( array $context ): array {
		if ( $this->has_no_minor_children( $context ) ) {
			return $this->skip(
				__( 'You indicated there are no children under 21.', 'prose-core' )
			);
		}

		if ( ! empty( $context['child_support_services_enrollment'] ) ) {
			return $this->include_form();
		}

		return $this->uncertain(
			__( 'LDSS-5258 applies only when the child support order must be sent to an employer or insurer.', 'prose-core' )
		);
	}

	/**
	 * @param string               $condition key=value style condition.
	 * @param array<string, mixed> $context   Facts.
	 * @return array{applicable: bool, uncertain: bool, reason: string}
	 */
	private function evaluate_fact_condition( string $condition, array $context ): array {
		$parts = preg_split( '/\s*==\s*|\s*=\s*/', trim( $condition ), 2 );

		if ( ! is_array( $parts ) || 2 !== count( $parts ) ) {
			return $this->include_form();
		}

		$key      = trim( $parts[0] );
		$expected = $this->coerce_value( trim( $parts[1] ) );

		if ( ! array_key_exists( $key, $context ) ) {
			if ( 'has_minor_children' === $key ) {
				return $this->evaluate_has_minor_children( $context );
			}

			return $this->uncertain(
				sprintf(
					/* translators: %s: fact key */
					__( 'More information is needed to determine whether this form applies (%s).', 'prose-core' ),
					$key
				)
			);
		}

		$actual = $context[ $key ];

		if ( $this->values_equal( $actual, $expected ) ) {
			return $this->include_form();
		}

		return $this->skip(
			sprintf(
				/* translators: 1: form fact label, 2: expected value */
				__( 'This form does not apply because %1$s is not %2$s for your case.', 'prose-core' ),
				$key,
				is_bool( $expected ) ? ( $expected ? 'true' : 'false' ) : (string) $expected
			)
		);
	}

	/**
	 * @param string               $key     Fact key.
	 * @param array<string, mixed> $context Facts.
	 * @return array{applicable: bool, uncertain: bool, reason: string}
	 */
	private function evaluate_boolean_fact( string $key, array $context ): array {
		$value = $this->resolve_boolean_fact( $key, $context );

		if ( null === $value ) {
			return $this->uncertain(
				sprintf(
					/* translators: %s: fact key */
					__( 'More information is needed to determine whether this form applies (%s).', 'prose-core' ),
					$key
				)
			);
		}

		if ( $value ) {
			return $this->include_form();
		}

		return $this->skip(
			sprintf(
				/* translators: %s: fact key */
				__( 'This form does not apply because %s is not true for your case.', 'prose-core' ),
				$key
			)
		);
	}

	/**
	 * @param string               $key     Fact key.
	 * @param array<string, mixed> $context Facts.
	 * @return bool|null
	 */
	private function resolve_boolean_fact( string $key, array $context ): ?bool {
		if ( array_key_exists( $key, $context ) ) {
			return $this->to_bool( $context[ $key ] );
		}

		if ( 'existing_order_exists' === $key ) {
			if ( array_key_exists( 'existing_orders', $context ) ) {
				$orders = $context['existing_orders'];

				if ( is_array( $orders ) ) {
					return count( $orders ) > 0;
				}

				return $this->to_bool( $orders );
			}

			return null;
		}

		if ( 'existing_support_order' === $key ) {
			if ( array_key_exists( 'existing_orders', $context ) ) {
				$orders = $context['existing_orders'];

				if ( is_array( $orders ) ) {
					return count( $orders ) > 0;
				}

				return $this->to_bool( $orders );
			}

			return null;
		}

		return null;
	}

	/**
	 * @return array{applicable: bool, uncertain: bool, reason: string}
	 */
	private function include_form(): array {
		return array(
			'applicable' => true,
			'uncertain'  => false,
			'reason'     => '',
		);
	}

	/**
	 * @param string $reason Skip reason.
	 * @return array{applicable: bool, uncertain: bool, reason: string}
	 */
	private function skip( string $reason ): array {
		return array(
			'applicable' => false,
			'uncertain'  => false,
			'reason'     => $reason,
		);
	}

	/**
	 * @param string $reason Uncertainty reason.
	 * @return array{applicable: bool, uncertain: bool, reason: string}
	 */
	private function uncertain( string $reason ): array {
		return array(
			'applicable' => false,
			'uncertain'  => true,
			'reason'     => $reason,
		);
	}

	/**
	 * @param string $code Form code.
	 * @return bool
	 */
	private function is_commencement_form( string $code ): bool {
		return in_array( $code, self::COMMENCEMENT_FORMS, true );
	}

	/**
	 * @param string $code Form code.
	 * @return bool
	 */
	private function is_service_form( string $code ): bool {
		return in_array( $code, self::SERVICE_FORMS, true );
	}

	/**
	 * @param array<string, mixed> $context Facts.
	 * @return bool
	 */
	private function case_already_commenced( array $context ): bool {
		if ( ! empty( $context['existing_case'] ) || ! empty( $context['active_divorce'] ) ) {
			$status = strtoupper( trim( (string) ( $context['case_status'] ?? '' ) ) );

			if ( '' === $status || 'NOT_STARTED' !== $status ) {
				return true;
			}
		}

		$status = strtoupper( trim( (string) ( $context['case_status'] ?? '' ) ) );

		return in_array(
			$status,
			array(
				'FILED',
				'SERVED',
				'DEFAULT_ELIGIBLE',
				'READY_FOR_FINAL_PAPERS',
				'READY_FOR_CALENDAR',
				'UNDER_REVIEW',
				'JUDGMENT_SIGNED',
				'JUDGMENT_ENTERED',
				'NOTICE_OF_ENTRY_FILED',
				'CLOSED',
			),
			true
		);
	}

	/**
	 * @param array<string, mixed> $context Facts.
	 * @return bool
	 */
	private function service_already_completed( array $context ): bool {
		if ( ! empty( $context['service_completed'] ) ) {
			return true;
		}

		$status = strtoupper( trim( (string) ( $context['case_status'] ?? '' ) ) );

		return in_array(
			$status,
			array(
				'SERVED',
				'DEFAULT_ELIGIBLE',
				'READY_FOR_FINAL_PAPERS',
				'READY_FOR_CALENDAR',
				'UNDER_REVIEW',
				'JUDGMENT_SIGNED',
				'JUDGMENT_ENTERED',
				'NOTICE_OF_ENTRY_FILED',
				'CLOSED',
			),
			true
		);
	}

	/**
	 * @param array<string, mixed> $context Facts.
	 * @return bool
	 */
	private function defendant_affidavit_completed( array $context ): bool {
		return ! empty( $context['defendant_affidavit_complete'] )
			|| ! empty( $context['spouse_signed_affidavit'] )
			|| ! empty( $context['defendant_affidavit_signed'] );
	}

	/**
	 * @param array<string, mixed> $context Facts.
	 * @return bool
	 */
	private function has_minor_children( array $context ): bool {
		if ( ! empty( $context['children'] ) || ! empty( $context['has_minor_children'] ) ) {
			return true;
		}

		foreach ( array( 'child_count', 'children_count' ) as $key ) {
			if ( isset( $context[ $key ] ) && (int) $context[ $key ] > 0 ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @param array<string, mixed> $context Facts.
	 * @return bool
	 */
	private function has_no_minor_children( array $context ): bool {
		if ( array_key_exists( 'children', $context ) && false === $this->to_bool( $context['children'] ) ) {
			return true;
		}

		if ( array_key_exists( 'has_minor_children', $context ) && false === $this->to_bool( $context['has_minor_children'] ) ) {
			return true;
		}

		foreach ( array( 'child_count', 'children_count' ) as $key ) {
			if ( array_key_exists( $key, $context ) && 0 === (int) $context[ $key ] ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @param array<string, mixed> $context Facts.
	 * @return bool
	 */
	private function maintenance_requested( array $context ): bool {
		return ! empty( $context['maintenance_requested'] )
			|| ! empty( $context['spousal_maintenance_requested'] );
	}

	/**
	 * @param array<string, mixed> $context Facts.
	 * @return bool
	 */
	private function maintenance_waived( array $context ): bool {
		return ! empty( $context['spousal_support_waived'] )
			|| ! empty( $context['maintenance_waived'] );
	}

	/**
	 * @param array<string, mixed> $context Facts.
	 * @return bool|null
	 */
	private function barriers_to_remarriage( array $context ): ?bool {
		if ( array_key_exists( 'barriers_to_remarriage', $context ) ) {
			return $this->to_bool( $context['barriers_to_remarriage'] );
		}

		if ( array_key_exists( 'religious_marriage', $context ) ) {
			return $this->to_bool( $context['religious_marriage'] );
		}

		if ( array_key_exists( 'religious_barrier_exists', $context ) ) {
			return $this->to_bool( $context['religious_barrier_exists'] );
		}

		return null;
	}

	/**
	 * @param string $value Raw value.
	 * @return mixed
	 */
	private function coerce_value( string $value ) {
		$lower = strtolower( $value );

		if ( 'true' === $lower ) {
			return true;
		}

		if ( 'false' === $lower ) {
			return false;
		}

		if ( is_numeric( $value ) ) {
			return str_contains( $value, '.' ) ? (float) $value : (int) $value;
		}

		return $value;
	}

	/**
	 * @param mixed $actual   Actual value.
	 * @param mixed $expected Expected value.
	 * @return bool
	 */
	private function values_equal( $actual, $expected ): bool {
		if ( is_bool( $expected ) ) {
			return $this->to_bool( $actual ) === $expected;
		}

		return (string) $actual === (string) $expected;
	}

	/**
	 * @param mixed $value Raw value.
	 * @return bool
	 */
	private function to_bool( $value ): bool {
		if ( is_bool( $value ) ) {
			return $value;
		}

		if ( is_numeric( $value ) ) {
			return (int) $value > 0;
		}

		$lower = strtolower( trim( (string) $value ) );

		return in_array( $lower, array( 'true', 'yes', '1' ), true );
	}
}
