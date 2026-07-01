<?php
/**
 * Case Memory — authoritative structured snapshot for conversational intake.
 *
 * Every assistant turn updates Case Memory. ChatGPT reads it to decide what to
 * say; the Workflow Engine writes workflow, stage, and missing-fact data.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Ai_Intake;

use ProSe\Core\Routing\Routing_Discriminator_Catalog;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Case_Memory
 */
final class Case_Memory {

	/**
	 * Build case memory from intake state and engine output.
	 *
	 * @param Intake_State         $state            Intake state.
	 * @param array<string, mixed> $missing_payload  Output from Workflow_Engine::get_missing_facts().
	 * @param array<string, mixed> $stage_context    Current stage context.
	 * @param float                $confidence       Model confidence for last turn.
	 * @param array<int, string>   $completed_forms  Completed form codes.
	 * @return array<string, mixed>
	 */
	public static function build(
		Intake_State $state,
		array $missing_payload,
		array $stage_context,
		float $confidence = 0.0,
		array $completed_forms = array(),
		array $workflow_state = array(),
		array $workflow_assessment = array()
	): array {
		$resolved   = is_array( $missing_payload['resolved'] ?? null ) ? $missing_payload['resolved'] : array();
		$workflow   = $state->workflow();
		$all_missing = is_array( $missing_payload['all'] ?? null ) ? $missing_payload['all'] : array();
		$conversation_missing = is_array( $missing_payload['conversation'] ?? null )
			? $missing_payload['conversation']
			: array();

		$canonical_stage = is_array( $workflow_state['current_stage'] ?? null )
			? $workflow_state['current_stage']
			: ( is_array( $stage_context['current_stage'] ?? null ) ? $stage_context['current_stage'] : null );
		$next_stage    = self::resolve_next_stage( $stage_context );

		if ( empty( $workflow_assessment ) ) {
			$workflow_assessment = array(
				'status'             => null !== $workflow && '' !== $workflow ? 'confirmed' : 'gathering',
				'status_label'       => null !== $workflow && '' !== $workflow ? __( 'Confirmed', 'prose-core' ) : __( 'Gathering', 'prose-core' ),
				'workflow'           => (string) ( $workflow ?? '' ),
				'workflow_title'     => '',
				'confidence'         => null !== $workflow && '' !== $workflow ? 1.0 : $confidence,
				'confidence_percent' => null !== $workflow && '' !== $workflow ? 100 : (int) round( $confidence * 100 ),
				'confirmed_facts'    => array(),
				'outstanding'        => array(),
				'candidate_workflows'=> is_array( $resolved['candidate_workflows'] ?? null ) ? $resolved['candidate_workflows'] : array(),
				'reason'             => '',
			);
		}

		return array(
			'workflow'            => $workflow,
			'confidence'          => $confidence,
			'issue'               => $state->issue(),
			'court'               => $state->court(),
			'facts'               => $state->plain_facts(),
			'missing_information' => $conversation_missing,
			'internal_missing'    => self::compact_internal_missing( $all_missing, $workflow ),
			'completed_forms'     => array_values( $completed_forms ),
			'current_stage'       => $canonical_stage,
			'next_stage'          => $next_stage,
			'completion'          => (int) ( $missing_payload['completion'] ?? 0 ),
			'routing_status'      => (string) ( $workflow_assessment['status'] ?? ( null !== $workflow && '' !== $workflow ? 'confirmed' : 'gathering' ) ),
			'workflow_assessment' => $workflow_assessment,
			'candidate_workflows' => is_array( $workflow_assessment['candidate_workflows'] ?? null )
				? $workflow_assessment['candidate_workflows']
				: ( is_array( $resolved['candidate_workflows'] ?? null ) ? $resolved['candidate_workflows'] : array() ),
			'procedural_node'     => (string) ( $workflow_state['procedural_node'] ?? $stage_context['procedural_node'] ?? '' ),
			'workflow_state'      => $workflow_state,
		);
	}

	/**
	 * Topics the assistant may still need to learn (conversation-safe only).
	 *
	 * @param array<string, mixed> $memory Case memory array.
	 * @return array<int, array<string, mixed>>
	 */
	public static function conversation_gaps( array $memory ): array {
		$gaps = is_array( $memory['missing_information'] ?? null ) ? $memory['missing_information'] : array();

		return array_values( $gaps );
	}

	/**
	 * Whether workflow routing is complete.
	 *
	 * @param array<string, mixed> $memory Case memory.
	 * @return bool
	 */
	public static function workflow_resolved( array $memory ): bool {
		$workflow = $memory['workflow'] ?? null;

		return is_string( $workflow ) && '' !== $workflow;
	}

	/**
	 * Compact non-conversational missing keys for forms/completion tracking.
	 *
	 * @param array<int, array<string, mixed>> $missing  All missing fields.
	 * @param string|null                      $workflow Resolved workflow.
	 * @return array<int, array<string, mixed>>
	 */
	private static function compact_internal_missing( array $missing, ?string $workflow ): array {
		$provider = new Required_Fields_Provider();
		$routing  = array_flip( $provider->routing_field_keys() );
		$out      = array();

		foreach ( $missing as $field ) {
			$key = (string) ( $field['field'] ?? '' );

			if ( '' === $key ) {
				continue;
			}

			if ( ( null === $workflow || '' === $workflow ) && isset( $routing[ $key ] ) ) {
				continue;
			}

			$out[] = array(
				'key'  => $key,
				'type' => (string) ( $field['type'] ?? 'string' ),
			);
		}

		return $out;
	}

	/**
	 * @param array<string, mixed> $stage_context Stage context.
	 * @return array<string, mixed>|null
	 */
	private static function resolve_next_stage( array $stage_context ): ?array {
		$next = $stage_context['next_stage'] ?? null;

		if ( is_array( $next ) && ! empty( $next['id'] ?? $next['title'] ?? '' ) ) {
			return $next;
		}

		$roadmap_next = $stage_context['next_action']['stage'] ?? null;

		if ( is_array( $roadmap_next ) ) {
			return $roadmap_next;
		}

		return null;
	}

	/**
	 * Build missing-information rows from raw missing field keys.
	 *
	 * @param array<int, array<string, mixed>> $missing Missing field rows.
	 * @return array<int, array<string, mixed>>
	 */
	public static function topics_from_missing( array $missing ): array {
		$out = array();

		foreach ( $missing as $field ) {
			$key = (string) ( $field['field'] ?? '' );

			if ( '' === $key ) {
				continue;
			}

			$out[] = array(
				'key'      => $key,
				'topic'    => Routing_Discriminator_Catalog::topic_for( $key ),
				'type'     => (string) ( $field['type'] ?? 'string' ),
				'priority' => (int) ( $field['priority'] ?? 0 ),
			);
		}

		return $out;
	}
}
