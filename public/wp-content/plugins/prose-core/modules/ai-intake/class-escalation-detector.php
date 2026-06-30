<?php
/**
 * Escalation detector — flags repeated uncertainty for human review.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Ai_Intake;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Escalation_Detector
 */
final class Escalation_Detector {

	/**
	 * Clarification attempts before escalation.
	 */
	private const ATTEMPT_THRESHOLD = 3;

	/**
	 * Uncertainty phrase patterns.
	 *
	 * @var string[]
	 */
	private const UNCERTAINTY_PATTERNS = array(
		"/\bi don'?t know\b/i",
		'/\bnot sure\b/i',
		'/\bmaybe\b/i',
		"/\bit'?s complicated\b/i",
		'/\bunsure\b/i',
		'/\bhard to say\b/i',
	);

	/**
	 * Detect whether intake should be escalated.
	 *
	 * Escalation applies only during early routing intake — not after a workflow
	 * is resolved and the user is in self-serve procedural guidance.
	 *
	 * @param string       $message            Latest user message.
	 * @param Intake_State $state              Intake state.
	 * @param float        $latest_confidence  Latest extraction confidence.
	 * @param bool         $workflow_resolved  Whether routing has resolved a workflow.
	 * @return array{needs_review: bool, reason: string}
	 */
	public function detect(
		string $message,
		Intake_State $state,
		float $latest_confidence = 1.0,
		bool $workflow_resolved = false
	): array {
		if ( $workflow_resolved || $this->is_guidance_question( $message ) ) {
			return array(
				'needs_review' => false,
				'reason'       => '',
			);
		}

		$pending = $state->pending_field();

		if ( $this->is_uncertain_message( $message ) ) {
			$count = $state->increment_clarification( $pending );

			if ( $count >= self::ATTEMPT_THRESHOLD ) {
				return array(
					'needs_review' => true,
					'reason'       => 'repeated_uncertainty',
				);
			}
		}

		if ( $latest_confidence < Intake_State::CONFIDENCE_THRESHOLD && '' !== $pending ) {
			$count = $state->increment_clarification( $pending );

			if ( $count >= self::ATTEMPT_THRESHOLD ) {
				return array(
					'needs_review' => true,
					'reason'       => 'persistent_low_confidence',
				);
			}
		}

		if ( '' !== $pending && $state->clarification_count( $pending ) >= self::ATTEMPT_THRESHOLD ) {
			return array(
				'needs_review' => true,
				'reason'       => 'clarification_exhausted',
			);
		}

		return array(
			'needs_review' => false,
			'reason'       => '',
		);
	}

	/**
	 * Whether the message expresses uncertainty.
	 *
	 * @param string $message User message.
	 * @return bool
	 */
	private function is_uncertain_message( string $message ): bool {
		foreach ( self::UNCERTAINTY_PATTERNS as $pattern ) {
			if ( preg_match( $pattern, $message ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Procedural / next-step questions are not intake clarification failures.
	 *
	 * @param string $message User message.
	 * @return bool
	 */
	private function is_guidance_question( string $message ): bool {
		$text = strtolower( trim( $message ) );

		foreach ( array(
			'how do i file',
			'how to file',
			'what happens next',
			'what do i do next',
			'what do i need to do',
			'what need to do',
			'what is need to do',
			'what to do next',
			'what should i do',
			'what now',
			'need to do now',
			'need to do next',
			'next step',
			'next steps',
			'how to start',
			'which form',
			'what form',
			'which forms',
			'what forms',
		) as $phrase ) {
			if ( str_contains( $text, $phrase ) ) {
				return true;
			}
		}

		return false;
	}
}
