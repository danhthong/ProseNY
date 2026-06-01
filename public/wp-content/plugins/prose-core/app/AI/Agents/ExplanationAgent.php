<?php
/**
 * Procedural explanation agent.
 *
 * Reads the requirement resolver output from workflow_state and steers the
 * LLM toward a structured intake interview: acknowledge → recap → ONE next
 * question (or readiness summary when nothing is missing).
 *
 * @package ProseCore
 */

namespace Prose\Core\AI\Agents;

use Prose\Core\AI\Gateway\LLMGateway;
use Prose\Core\AI\Gateway\LLMRequest;
use Prose\Core\Contracts\AgentInterface;
use Prose\Core\Security\Disclaimer;

final class ExplanationAgent implements AgentInterface {

	public function __construct(
		private readonly LLMGateway $gateway
	) {}

	public function name(): string {
		return 'explanation';
	}

	public function handle( AgentContext $context ): AgentResult {
		$state         = $context->workflow_state;
		$requirements  = $state['requirements'] ?? array();
		$turn_meta     = $state['turn_meta'] ?? array();
		$next_question = $requirements['next'] ?? null;
		$completeness  = (int) ( $requirements['completeness'] ?? 0 );
		$still_missing = ! empty( $turn_meta['still_missing'] );
		$newly_captured = $turn_meta['newly_captured'] ?? array();

		if ( $still_missing && is_array( $next_question ) ) {
			$text = $this->still_missing_reply( $turn_meta, $next_question, $completeness );
			$text .= "\n\n---\n" . Disclaimer::text();
			return new AgentResult( null, $text );
		}

		if ( ! empty( $newly_captured ) && is_array( $next_question ) && $this->should_use_deterministic( $newly_captured, $next_question ) ) {
			$text = $this->deterministic_reply( $newly_captured, $next_question, $completeness, $requirements );
			$text .= "\n\n---\n" . Disclaimer::text();
			return new AgentResult( null, $text );
		}

		$system_prompt = $this->load_prompt();

		$response = $this->gateway->complete(
			new LLMRequest(
				$this->name(),
				array(
					array(
						'role'    => 'system',
						'content' => $system_prompt,
					),
					array(
						'role'    => 'user',
						'content' => wp_json_encode(
							array(
								'facts'          => $context->facts,
								'workflow_state' => $state,
								'validation'     => $context->validation_report,
								'user_message'   => $context->user_message,
								'directives'     => array(
									'next_question'     => $next_question,
									'completeness'      => $completeness,
									'ready_to_generate' => (bool) ( $requirements['ready_to_generate'] ?? false ),
									'missing_count'     => (int) ( $requirements['summary']['missing_count'] ?? 0 ),
								),
							)
						),
					),
				),
				null,
				array(),
				$context->session_id,
				$context->case_id
			)
		);

		$text = trim( (string) $response->content );

		if ( '' === $text ) {
			$text = $this->fallback_text( $next_question, $completeness, $requirements );
		}

		$text .= "\n\n---\n" . Disclaimer::text();

		return new AgentResult( null, $text );
	}

	private function load_prompt(): string {
		$path = defined( 'PROSE_CORE_PATH' )
			? PROSE_CORE_PATH . 'app/AI/Prompts/explanation/v1.md'
			: '';

		if ( $path && file_exists( $path ) ) {
			$contents = file_get_contents( $path );
			if ( false !== $contents && '' !== $contents ) {
				return $contents;
			}
		}

		return 'You are the CourtFlow procedural intake assistant. Acknowledge what you learned, then ask only the single field named in directives.next_question.prompt. Never give legal advice. Never ask multiple questions at once.';
	}

	/**
	 * Deterministic safety net when the LLM returns nothing (e.g. provider outage).
	 *
	 * @param array<string, mixed>|null $next
	 * @param array<string, mixed>      $requirements
	 */
	/**
	 * @param array<int, array<string, mixed>> $captured
	 * @param array<string, mixed>             $next
	 * @param array<string, mixed>             $requirements
	 */
	private function deterministic_reply( array $captured, array $next, int $completeness, array $requirements ): string {
		$parts = array();
		foreach ( $captured as $item ) {
			$label = $this->human_label( (string) ( $item['path'] ?? '' ) );
			$val   = (string) ( $item['value'] ?? '' );
			$parts[] = $label . ': ' . $val;
		}

		$ack = sprintf(
			/* translators: %s: comma-separated list of recorded fields */
			__( 'Thank you — I\'ve recorded %s.', 'prose-core' ),
			implode( '; ', $parts )
		);

		if ( ! empty( $requirements['ready_to_generate'] ) ) {
			return $ack . ' ' . __( 'Your intake is complete. Review your case summary and generate the filing package when you are ready.', 'prose-core' );
		}

		return $ack . ' ' . sprintf(
			/* translators: 1: completeness percent, 2: next question prompt */
			__( 'Your intake is %1$d%% complete. %2$s', 'prose-core' ),
			$completeness,
			(string) ( $next['prompt'] ?? '' )
		);
	}

	/**
	 * @param array<string, mixed> $turn_meta
	 * @param array<string, mixed> $next
	 */
	private function still_missing_reply( array $turn_meta, array $next, int $completeness ): string {
		$answered = (string) ( $turn_meta['pending_path'] ?? $turn_meta['answered_path'] ?? '' );
		$label    = $this->human_label( $answered );

		return sprintf(
			/* translators: 1: field label, 2: completeness percent, 3: follow-up prompt */
			__( 'I wasn\'t able to save your answer for %1$s yet — I may need a bit more detail. Your intake is %2$d%% complete. %3$s', 'prose-core' ),
			$label,
			$completeness,
			(string) ( $next['prompt'] ?? '' )
		);
	}

	/**
	 * @param array<int, array<string, mixed>> $captured
	 */
	private function should_use_deterministic( array $captured, array $next ): bool {
		return count( $captured ) >= 1 && ! empty( $next['prompt'] );
	}

	private function human_label( string $path ): string {
		$parts = explode( '.', $path );
		$leaf  = $parts ? (string) end( $parts ) : $path;
		return ucwords( str_replace( '_', ' ', $leaf ) );
	}

	private function fallback_text( ?array $next, int $completeness, array $requirements ): string {
		if ( ! empty( $requirements['ready_to_generate'] ) ) {
			return __( 'I have everything I need for your filing. Review the case summary on the right, then click Generate Filing Package when you are ready.', 'prose-core' );
		}

		if ( is_array( $next ) && ! empty( $next['prompt'] ) ) {
			return sprintf(
				/* translators: 1: completeness percent, 2: next-question prompt */
				__( 'Thank you. Your intake is %1$d%% complete. %2$s', 'prose-core' ),
				$completeness,
				(string) $next['prompt']
			);
		}

		return __( 'Thank you. Please share any additional details you have about your case.', 'prose-core' );
	}
}
