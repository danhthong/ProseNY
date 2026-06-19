<?php
/**
 * Maps AI intake interpreter results to the CourtFlow workspace API shape.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Intake\Rest;

use ProSe\Core\Ai_Intake\Intake_State;
use ProSe\Core\Ai_Intake\Required_Fields_Provider;
use ProSe\Core\Intake\Case_Actions_Resolver;
use ProSe\Core\Routing\Workflow_Catalog;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Courtflow_Response_Mapper
 */
final class Courtflow_Response_Mapper {

	/**
	 * Required fields provider.
	 *
	 * @var Required_Fields_Provider
	 */
	private Required_Fields_Provider $fields;

	/**
	 * Case actions resolver.
	 *
	 * @var Case_Actions_Resolver
	 */
	private Case_Actions_Resolver $actions;

	/**
	 * Workflow catalog.
	 *
	 * @var Workflow_Catalog
	 */
	private Workflow_Catalog $workflows;

	/**
	 * Constructor.
	 *
	 * @param Required_Fields_Provider|null $fields    Fields provider.
	 * @param Case_Actions_Resolver|null    $actions   Actions resolver.
	 * @param Workflow_Catalog|null       $workflows Workflow catalog.
	 */
	public function __construct(
		?Required_Fields_Provider $fields = null,
		?Case_Actions_Resolver $actions = null,
		?Workflow_Catalog $workflows = null
	) {
		$this->fields    = $fields ?? new Required_Fields_Provider();
		$this->actions   = $actions ?? new Case_Actions_Resolver();
		$this->workflows = $workflows ?? new Workflow_Catalog();
	}

	/**
	 * Build the workspace state payload (GET /sessions/{id}/state).
	 *
	 * @param array<string, mixed> $session Stored session.
	 * @return array<string, mixed>
	 */
	public function map_session_state( array $session ): array {
		$context = $this->build_context( $session );

		return array_merge(
			$context,
			array(
				'workflow_state' => array(
					'required_forms' => $context['required_forms'],
					'current_node'   => $context['current_node'],
					'requirements'   => $context['requirements'],
				),
			)
		);
	}

	/**
	 * Build the message POST response for courtflow.js.
	 *
	 * @param array<string, mixed> $session         Stored session.
	 * @param array<string, mixed> $service_response AI_Intake_Service response.
	 * @param array<string, mixed> $applied_updates  Fact updates applied this turn.
	 * @return array<string, mixed>
	 */
	public function map_message_response( array $session, array $service_response, array $applied_updates = array() ): array {
		$context = $this->build_context( $session, $service_response, $applied_updates );
		$result  = is_array( $service_response['result'] ?? null ) ? $service_response['result'] : array();
		$message = (string) ( $result['question'] ?? '' );

		if ( '' === $message && isset( $service_response['message'] ) ) {
			$message = (string) $service_response['message'];
		}

		return array_merge(
			$context,
			array(
				'message'         => $message,
				'newly_captured'  => $this->map_newly_captured( $applied_updates ),
				'workflow_state'  => array(
					'required_forms' => $context['required_forms'],
					'current_node'   => $context['current_node'],
					'requirements'   => $context['requirements'],
				),
			)
		);
	}

	/**
	 * Build a 422 payload when package generation is blocked.
	 *
	 * @param array<string, mixed> $session Stored session.
	 * @return array<string, mixed>
	 */
	public function map_generation_blocked( array $session ): array {
		$context = $this->build_context( $session );

		return array(
			'message'      => __( 'A few more details are needed before the filing package can be generated.', 'prose-core' ),
			'missing'      => $context['requirements']['missing'] ?? array(),
			'next'         => $context['requirements']['next'] ?? null,
			'completeness' => (int) ( $context['requirements']['completeness'] ?? 0 ),
			'blockers'     => $context['requirements']['blockers'] ?? array(),
		);
	}

	/**
	 * Shared workspace context derived from the stored session.
	 *
	 * @param array<string, mixed>      $session         Session.
	 * @param array<string, mixed>|null $service_response Optional fresh service response.
	 * @param array<string, mixed>      $applied_updates  Optional applied updates.
	 * @return array<string, mixed>
	 */
	private function build_context( array $session, ?array $service_response = null, array $applied_updates = array() ): array {
		unset( $applied_updates );

		$case_profile = is_array( $session['case_profile'] ?? null ) ? $session['case_profile'] : array();
		$result       = is_array( $service_response['result'] ?? null ) ? $service_response['result'] : array();
		$interpret    = ! empty( $result ) ? $result : $this->latest_interpret_snapshot( $session );

		$intake_state = Intake_State::from_array(
			is_array( $session['intake_state'] ?? null ) ? $session['intake_state'] : array()
		);
		$resolved     = $this->fields->resolve( $intake_state, '' );
		$missing      = $this->fields->missing_prioritized( $resolved['fields'], $intake_state );
		$completion   = (int) ( $interpret['completion'] ?? $case_profile['progress'] ?? 0 );
		$workflow     = trim( (string) ( $interpret['workflow'] ?? $case_profile['workflow'] ?? $resolved['workflow'] ?? '' ) );
		$actions      = is_array( $session['actions'] ?? null ) ? $session['actions'] : array();

		if ( empty( $actions ) ) {
			$actions = $this->actions->resolve( $case_profile, $interpret );
		}

		$contradictions = is_array( $interpret['contradictions'] ?? null ) ? $interpret['contradictions'] : array();
		$requirements   = $this->build_requirements( $missing, $completion, $workflow, $actions, $contradictions );
		$facts          = $this->build_facts( $case_profile, $interpret, $actions );
		$required_forms = $this->required_forms_for_workflow( $workflow );
		$current_node   = $this->current_node( $completion, $workflow, $missing, $facts );

		return array(
			'facts'          => $facts,
			'validation'     => $this->build_validation( $contradictions, $requirements ),
			'requirements'   => $requirements,
			'required_forms' => $required_forms,
			'current_node'   => $current_node,
			'actions'        => $actions,
			'court_routing'  => is_array( $actions['court_routing'] ?? null ) ? $actions['court_routing'] : array(),
		);
	}

	/**
	 * @param array<string, mixed> $session Session.
	 * @return array<string, mixed>
	 */
	private function latest_interpret_snapshot( array $session ): array {
		if ( is_array( $session['last_interpret'] ?? null ) && ! empty( $session['last_interpret'] ) ) {
			return $session['last_interpret'];
		}

		return array(
			'completion'     => (int) ( $session['case_profile']['progress'] ?? 0 ),
			'workflow'       => (string) ( $session['case_profile']['workflow'] ?? '' ),
			'contradictions' => array(),
			'missing_fields' => array(),
		);
	}

	/**
	 * @param array<int, array<string, mixed>> $missing        Missing field defs.
	 * @param int                              $completion     Completion percent.
	 * @param string                           $workflow       Workflow key.
	 * @param array<string, mixed>             $actions        Case actions.
	 * @param array<int, array<string, mixed>> $contradictions Contradictions.
	 * @return array<string, mixed>
	 */
	private function build_requirements( array $missing, int $completion, string $workflow, array $actions, array $contradictions ): array {
		$missing_rows = array();
		$required     = array();
		$collected    = array();

		foreach ( $missing as $field ) {
			$key  = (string) ( $field['field'] ?? '' );
			$path = 'case.' . $key;

			$row = array(
				'path'   => $path,
				'label'  => $this->humanize( $key ),
				'prompt' => (string) ( $field['question'] ?? '' ),
			);

			$missing_rows[] = $row;
			$required[]     = $row;
		}

		$next = ! empty( $missing_rows ) ? $missing_rows[0] : null;

		$ready = ! empty( $actions['intake_complete'] )
			&& ! empty( $actions['workflow_resolved'] )
			&& empty( $contradictions );

		return array(
			'required'          => $required,
			'collected'         => $collected,
			'missing'           => $missing_rows,
			'next'              => $next,
			'completeness'      => max( 0, min( 100, $completion ) ),
			'threshold'         => 80,
			'ready_to_generate' => $ready,
			'blockers'          => $this->blockers_from_contradictions( $contradictions ),
			'summary'           => array(
				'collected_count' => max( 0, count( $required ) - count( $missing_rows ) ),
				'required_count'  => count( $required ),
				'missing_count'   => count( $missing_rows ),
			),
		);
	}

	/**
	 * @param array<string, mixed> $case_profile Case profile.
	 * @param array<string, mixed> $interpret    Interpreter result.
	 * @param array<string, mixed> $actions      Case actions.
	 * @return array<string, mixed>
	 */
	private function build_facts( array $case_profile, array $interpret, array $actions ): array {
		$plain = is_array( $case_profile['facts'] ?? null ) ? $case_profile['facts'] : array();
		$state = is_array( $interpret['state']['facts'] ?? null ) ? $interpret['state']['facts'] : array();

		foreach ( $state as $key => $entry ) {
			if ( is_array( $entry ) && array_key_exists( 'value', $entry ) ) {
				$plain[ (string) $key ] = $entry['value'];
			}
		}

		$case = array();

		foreach ( $plain as $key => $value ) {
			if ( null === $value || '' === $value ) {
				continue;
			}

			$case[ (string) $key ] = $value;
		}

		if ( ! empty( $actions['workflow'] ) ) {
			$case['workflow'] = (string) $actions['workflow'];
		} elseif ( ! empty( $interpret['workflow'] ) ) {
			$case['workflow'] = (string) $interpret['workflow'];
		}

		if ( ! empty( $actions['issue'] ) ) {
			$case['issue'] = (string) $actions['issue'];
		}

		if ( ! empty( $actions['workflow_title'] ) ) {
			$case['workflow_title'] = (string) $actions['workflow_title'];
		}

		return array(
			'case' => $case,
			'user' => array(),
		);
	}

	/**
	 * @param array<int, array<string, mixed>> $contradictions Contradictions.
	 * @param array<string, mixed>             $requirements   Requirements block.
	 * @return array<string, mixed>
	 */
	private function build_validation( array $contradictions, array $requirements ): array {
		$errors   = array();
		$warnings = array();

		foreach ( $contradictions as $item ) {
			$warnings[] = array(
				'path'    => 'case.' . (string) ( $item['field'] ?? '' ),
				'message' => (string) ( $item['message'] ?? '' ),
			);
		}

		if ( ! empty( $requirements['blockers'] ) ) {
			foreach ( $requirements['blockers'] as $blocker ) {
				$errors[] = array(
					'path'    => (string) ( $blocker['path'] ?? '' ),
					'message' => (string) ( $blocker['message'] ?? '' ),
				);
			}
		}

		return array(
			'valid'    => empty( $errors ),
			'errors'   => $errors,
			'warnings' => $warnings,
		);
	}

	/**
	 * @param string                           $workflow Workflow key.
	 * @return array<int, string>
	 */
	private function required_forms_for_workflow( string $workflow ): array {
		if ( '' === $workflow ) {
			return array();
		}

		$definition = $this->workflows->by_key( $workflow );

		if ( null === $definition ) {
			return array();
		}

		return $this->workflows->required_form_codes( $definition );
	}

	/**
	 * @param int                              $completion Completion percent.
	 * @param string                           $workflow   Workflow key.
	 * @param array<int, array<string, mixed>> $missing    Missing fields.
	 * @param array<string, mixed>             $facts      Workspace facts.
	 * @return array<string, mixed>
	 */
	private function current_node( int $completion, string $workflow, array $missing, array $facts ): array {
		$slug  = 'intake_basics';
		$label = __( 'Intake', 'prose-core' );

		if ( $completion >= 100 && '' !== $workflow ) {
			$slug  = 'collect_marriage_info';
			$label = __( 'Case details', 'prose-core' );
		} elseif ( $this->missing_targets_children( $missing, $facts ) ) {
			$slug  = 'collect_child_information';
			$label = __( 'Child information', 'prose-core' );
		} elseif ( '' !== $workflow ) {
			$slug  = 'collect_marriage_info';
			$label = __( 'Case details', 'prose-core' );
		}

		return array(
			'id'    => $slug,
			'slug'  => $slug,
			'label' => $label,
		);
	}

	/**
	 * @param array<int, array<string, mixed>> $missing Missing fields.
	 * @param array<string, mixed>             $facts   Facts block.
	 * @return bool
	 */
	private function missing_targets_children( array $missing, array $facts ): bool {
		foreach ( $missing as $field ) {
			$key = (string) ( $field['field'] ?? '' );

			if ( preg_match( '/^child_|^children|^has_minor_children|^minor_children/', $key ) ) {
				return true;
			}
		}

		$case = is_array( $facts['case'] ?? null ) ? $facts['case'] : array();

		if ( ! empty( $case['child_count'] ) || ! empty( $case['has_minor_children'] ) || ! empty( $case['children'] ) ) {
			return true;
		}

		return false;
	}

	/**
	 * @param array<string, mixed> $applied_updates Applied fact updates.
	 * @return array<int, array{path: string, value: mixed}>
	 */
	private function map_newly_captured( array $applied_updates ): array {
		$rows = array();

		foreach ( $applied_updates as $key => $update ) {
			if ( ! is_array( $update ) || ! array_key_exists( 'value', $update ) ) {
				continue;
			}

			$value = $update['value'];

			if ( null === $value || '' === $value ) {
				continue;
			}

			$rows[] = array(
				'path'  => 'case.' . (string) $key,
				'value' => $value,
			);
		}

		return $rows;
	}

	/**
	 * @param array<int, array<string, mixed>> $contradictions Contradictions.
	 * @return array<int, array{path: string, message: string}>
	 */
	private function blockers_from_contradictions( array $contradictions ): array {
		$blockers = array();

		foreach ( $contradictions as $item ) {
			$blockers[] = array(
				'path'    => 'case.' . (string) ( $item['field'] ?? '' ),
				'message' => (string) ( $item['message'] ?? '' ),
			);
		}

		return $blockers;
	}

	/**
	 * @param string $key Field key.
	 * @return string
	 */
	private function humanize( string $key ): string {
		$key = str_replace( '_', ' ', $key );

		return ucwords( trim( $key ) );
	}
}
