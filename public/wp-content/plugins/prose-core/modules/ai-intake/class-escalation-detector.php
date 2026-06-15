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
	 * @param string       $message          Latest user message.
	 * @param Intake_State $state            Intake state.
	 * @param float        $latest_confidence Latest extraction confidence.
	 * @return array{needs_review: bool, reason: string}
	 */
	public function detect( string $message, Intake_State $state, float $latest_confidence = 1.0 ): array {
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
}
