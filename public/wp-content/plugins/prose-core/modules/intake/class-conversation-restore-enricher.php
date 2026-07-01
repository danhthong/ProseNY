<?php
/**
 * Restore missing stage-complete transcript turns from case progress.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Intake;

use ProSe\Core\Ai_Intake\Stage_Transition_Guidance_Service;
use ProSe\Core\Routing\Workflow_Catalog;
use ProSe\Core\Routing\Workflow_Engine;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Conversation_Restore_Enricher
 */
final class Conversation_Restore_Enricher {

	public const TRANSCRIPT_KEY = 'stage_transition_transcript';

	/**
	 * @var Workflow_Engine
	 */
	private Workflow_Engine $workflow_engine;

	/**
	 * @var Workflow_Catalog
	 */
	private Workflow_Catalog $workflows;

	/**
	 * @var Stage_Transition_Guidance_Service
	 */
	private Stage_Transition_Guidance_Service $stage_guidance;

	/**
	 * Constructor.
	 *
	 * @param Workflow_Engine|null                 $workflow_engine Workflow engine.
	 * @param Workflow_Catalog|null                $workflows       Workflow catalog.
	 * @param Stage_Transition_Guidance_Service|null $stage_guidance  Stage guidance service.
	 */
	public function __construct(
		?Workflow_Engine $workflow_engine = null,
		?Workflow_Catalog $workflows = null,
		?Stage_Transition_Guidance_Service $stage_guidance = null
	) {
		$this->workflow_engine = $workflow_engine ?? new Workflow_Engine();
		$this->workflows       = $workflows ?? new Workflow_Catalog();
		$this->stage_guidance  = $stage_guidance ?? new Stage_Transition_Guidance_Service();
	}

	/**
	 * Rebuild transcript with intake turns plus stage-complete user/assistant pairs.
	 *
	 * @param array<int, array<string, mixed>> $conversation Conversation turns.
	 * @param array<string, mixed>             $case_profile Case profile snapshot.
	 * @return array<int, array<string, mixed>>
	 */
	public function enrich( array $conversation, array $case_profile ): array {
		$workflow = trim( (string) ( $case_profile['workflow'] ?? '' ) );

		if ( '' === $workflow ) {
			return $conversation;
		}

		$completed = Completed_Stage_Document_Store::completed_stage_count( $case_profile );

		if ( $completed <= 0 ) {
			return $conversation;
		}

		$intake_turns = $this->extract_intake_turns( $conversation );
		$transitions  = $this->resolve_transitions( $case_profile, $completed );
		$merged       = $intake_turns;

		foreach ( $transitions as $transition ) {
			$user_label = trim( (string) ( $transition['user'] ?? '' ) );

			if ( '' === $user_label ) {
				continue;
			}

			$merged[] = array(
				'role'    => 'user',
				'content' => $user_label,
				'source'  => 'stage_complete',
			);

			$assistant = trim( (string) ( $transition['assistant'] ?? '' ) );

			if ( '' !== $assistant ) {
				$merged[] = array(
					'role'    => 'assistant',
					'content' => $assistant,
				);
			}
		}

		return $merged;
	}

	/**
	 * @param array<int, array<string, mixed>> $conversation Conversation turns.
	 * @return array<int, array<string, mixed>>
	 */
	private function extract_intake_turns( array $conversation ): array {
		$intake = array();

		foreach ( $conversation as $turn ) {
			if ( ! is_array( $turn ) ) {
				continue;
			}

			$content = trim( (string) ( $turn['content'] ?? '' ) );

			if ( '' === $content ) {
				continue;
			}

			if ( 'user' === ( $turn['role'] ?? '' ) && preg_match( '/^I completed this step\b/i', $content ) ) {
				continue;
			}

			$intake[] = $turn;
		}

		return $intake;
	}

	/**
	 * @param array<string, mixed> $case_profile Case profile snapshot.
	 * @param int                  $completed      Completed stage count.
	 * @return array<int, array{user: string, assistant: string}>
	 */
	private function resolve_transitions( array $case_profile, int $completed ): array {
		$stored = is_array( $case_profile[ self::TRANSCRIPT_KEY ] ?? null )
			? $case_profile[ self::TRANSCRIPT_KEY ]
			: array();
		$transitions = array();

		if ( ! empty( $stored ) ) {
			foreach ( $stored as $row ) {
				if ( ! is_array( $row ) ) {
					continue;
				}

				$user = trim( (string) ( $row['user'] ?? '' ) );

				if ( '' === $user ) {
					continue;
				}

				$transitions[] = array(
					'user'      => $user,
					'assistant' => trim( (string) ( $row['assistant'] ?? '' ) ),
				);
			}

			if ( count( $transitions ) >= $completed ) {
				return array_slice( $transitions, 0, $completed );
			}
		}

		$facts         = is_array( $case_profile['facts'] ?? null ) ? $case_profile['facts'] : array();
		$required_defs = $this->required_field_defs( (string) ( $case_profile['workflow'] ?? '' ) );
		$stored_node   = trim( (string) ( $case_profile['procedural_node'] ?? '' ) );

		for ( $index = 0; $index < $completed; $index++ ) {
			$stored_row = $stored[ $index ] ?? null;
			$user_label = is_array( $stored_row ) ? trim( (string) ( $stored_row['user'] ?? '' ) ) : '';

			if ( '' === $user_label ) {
				$workflow_state = $this->workflow_engine->resolve_state(
					(string) ( $case_profile['workflow'] ?? '' ),
					$facts,
					$stored_node,
					$required_defs,
					$index + 1
				);
				$next_title     = trim( (string) ( $workflow_state['current_stage']['title'] ?? '' ) );

				if ( '' === $next_title ) {
					continue;
				}

				/* translators: %s: next procedural stage title. */
				$user_label = sprintf( __( 'I completed this step — continue to %s', 'prose-core' ), $next_title );
			}

			$assistant = is_array( $stored_row ) ? trim( (string) ( $stored_row['assistant'] ?? '' ) ) : '';

			if ( '' === $assistant ) {
				$assistant = $this->find_assistant_after_label( $case_profile, $user_label );
			}

			if ( '' === $assistant ) {
				$assistant = $this->stage_guidance->restored_transition_guidance( $case_profile, $index );
			}

			$transitions[] = array(
				'user'      => $user_label,
				'assistant' => $assistant,
			);
		}

		return $transitions;
	}

	/**
	 * @param array<string, mixed> $case_profile Case profile.
	 * @param string               $user_label   Stage-complete user label.
	 * @return string
	 */
	private function find_assistant_after_label( array $case_profile, string $user_label ): string {
		$stored = is_array( $case_profile[ self::TRANSCRIPT_KEY ] ?? null )
			? $case_profile[ self::TRANSCRIPT_KEY ]
			: array();

		foreach ( $stored as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			if ( trim( (string) ( $row['user'] ?? '' ) ) !== $user_label ) {
				continue;
			}

			return trim( (string) ( $row['assistant'] ?? '' ) );
		}

		return '';
	}

	/**
	 * @param string $workflow Workflow key.
	 * @return array<int, array<string, mixed>>
	 */
	private function required_field_defs( string $workflow ): array {
		$definition = $this->workflows->by_key( $workflow );
		$fields     = is_array( $definition['required_fields'] ?? null ) ? $definition['required_fields'] : array();

		return $fields;
	}
}
