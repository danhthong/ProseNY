<?php
/**
 * Generate a short AI-style summary (deterministic, no external API).
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Forms\Classification;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Ai_Summarizer
 */
final class Ai_Summarizer {

	/**
	 * Maximum summary length.
	 */
	private const MAX_LENGTH = 200;

	/**
	 * Generate summary text.
	 *
	 * @param array<string, mixed> $ctx Classification context.
	 * @return string
	 */
	public function summarize( array $ctx ): string {
		$court    = (string) ( $ctx['court'] ?? '' );
		$case     = (string) ( $ctx['case_type'] ?? '' );
		$stage    = (string) ( $ctx['workflow_stage'] ?? '' );
		$form     = (string) ( $ctx['form_code'] ?? '' );
		$title    = (string) ( $ctx['title'] ?? '' );

		$parts = array();

		if ( '' !== $form ) {
			$parts[] = $form;
		}

		if ( '' !== $title && $title !== $form ) {
			$parts[] = $title;
		}

		$summary = '';

		if ( '' !== $case && '' !== $court ) {
			$summary = sprintf(
				/* translators: 1: case type, 2: court name */
				__( 'This form is used in a %1$s matter in %2$s.', 'prose-core' ),
				strtolower( $case ),
				$court
			);
		} elseif ( '' !== $court ) {
			$summary = sprintf(
				/* translators: %s: court name */
				__( 'This form is filed in %s.', 'prose-core' ),
				$court
			);
		} elseif ( ! empty( $parts ) ) {
			$summary = sprintf(
				/* translators: %s: form identifier */
				__( 'Court form: %s.', 'prose-core' ),
				implode( ' — ', $parts )
			);
		}

		if ( '' !== $stage ) {
			$summary .= ' ' . sprintf(
				/* translators: %s: workflow stage */
				__( 'Workflow stage: %s.', 'prose-core' ),
				$stage
			);
		}

		$summary = trim( preg_replace( '/\s+/', ' ', $summary ) ?? '' );

		if ( strlen( $summary ) > self::MAX_LENGTH ) {
			$summary = substr( $summary, 0, self::MAX_LENGTH - 3 ) . '...';
		}

		/**
		 * Filter AI summary text.
		 *
		 * @param string               $summary Summary text.
		 * @param array<string, mixed> $ctx     Classification context.
		 */
		return apply_filters( 'prose_core_ai_summary', $summary, $ctx );
	}
}
