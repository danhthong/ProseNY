<?php
/**
 * Rule-based document classifier — keyword matching on filename and text.
 *
 * Classification is engine-owned; AI may summarize but never routes workflow.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Documents;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Document_Classifier
 */
final class Document_Classifier {

	/**
	 * Document type taxonomy with keyword rules.
	 *
	 * @var array<string, array{label: string, filename: string[], text: string[], stage: string}>
	 */
	private const RULES = array(
		'order_to_show_cause' => array(
			'label'    => 'Order to Show Cause',
			'filename' => array( 'osc', 'order to show cause', 'order-to-show-cause', 'show cause' ),
			'text'     => array( 'order to show cause', 'show cause', 'return date', 'osc' ),
			'stage'    => 'temporary_relief',
		),
		'answer'              => array(
			'label'    => 'Answer',
			'filename' => array( 'answer', 'verified answer', 'response' ),
			'text'     => array( 'verified answer', 'answer to', 'responds to the complaint', 'affirmative defenses' ),
			'stage'    => 'response',
		),
		'order'               => array(
			'label'    => 'Court Order',
			'filename' => array( 'order', 'so ordered', 'decree' ),
			'text'     => array( 'so ordered', 'it is ordered', 'this court orders', 'order of the court' ),
			'stage'    => 'order',
		),
		'motion'              => array(
			'label'    => 'Motion',
			'filename' => array( 'motion', 'notice of motion', 'order to show cause motion' ),
			'text'     => array( 'notice of motion', 'motion for', 'moving party', 'relief requested' ),
			'stage'    => 'motion_practice',
		),
		'judgment'            => array(
			'label'    => 'Judgment',
			'filename' => array( 'judgment', 'decree of divorce', 'final judgment' ),
			'text'     => array( 'judgment of divorce', 'final judgment', 'decree of divorce', 'judgment is entered' ),
			'stage'    => 'judgment',
		),
	);

	/**
	 * Classify a document from filename and optional extracted text.
	 *
	 * @param string $filename Original filename.
	 * @param string $text     Optional document text (OCR or pasted excerpt).
	 * @return array<string, mixed>
	 */
	public function classify( string $filename, string $text = '' ): array {
		$filename_norm = $this->normalize( $filename );
		$text_norm     = $this->normalize( $text );
		$best_type     = 'unknown';
		$best_score    = 0;
		$best_rule     = null;

		foreach ( self::RULES as $type => $rule ) {
			$score = $this->score_rule( $rule, $filename_norm, $text_norm );

			if ( $score > $best_score ) {
				$best_score = $score;
				$best_type  = $type;
				$best_rule  = $rule;
			}
		}

		if ( null === $best_rule || $best_score <= 0 ) {
			return array(
				'type'        => 'unknown',
				'label'       => __( 'Unknown document', 'prose-core' ),
				'confidence'  => 0.0,
				'stage'       => null,
				'next_step'   => __( 'Describe the document or upload a clearer copy so we can identify it.', 'prose-core' ),
				'matched_on'  => array(),
			);
		}

		return array(
			'type'       => $best_type,
			'label'      => (string) $best_rule['label'],
			'confidence' => min( 1.0, $best_score / 3.0 ),
			'stage'      => (string) $best_rule['stage'],
			'next_step'  => $this->next_step_for_type( $best_type ),
			'matched_on' => $this->matched_keywords( $best_rule, $filename_norm, $text_norm ),
		);
	}

	/**
	 * @param array{label: string, filename: string[], text: string[], stage: string} $rule          Rule row.
	 * @param string                                                                    $filename_norm Normalized filename.
	 * @param string                                                                    $text_norm     Normalized text.
	 * @return int
	 */
	private function score_rule( array $rule, string $filename_norm, string $text_norm ): int {
		$score = 0;

		foreach ( $rule['filename'] as $keyword ) {
			if ( '' !== $filename_norm && str_contains( $filename_norm, $this->normalize( $keyword ) ) ) {
				$score += 2;
			}
		}

		foreach ( $rule['text'] as $keyword ) {
			if ( '' !== $text_norm && str_contains( $text_norm, $this->normalize( $keyword ) ) ) {
				$score += 1;
			}
		}

		return $score;
	}

	/**
	 * @param array{label: string, filename: string[], text: string[], stage: string} $rule          Rule row.
	 * @param string                                                                    $filename_norm Normalized filename.
	 * @param string                                                                    $text_norm     Normalized text.
	 * @return string[]
	 */
	private function matched_keywords( array $rule, string $filename_norm, string $text_norm ): array {
		$matched = array();

		foreach ( array_merge( $rule['filename'], $rule['text'] ) as $keyword ) {
			$needle = $this->normalize( $keyword );

			if ( ( '' !== $filename_norm && str_contains( $filename_norm, $needle ) )
				|| ( '' !== $text_norm && str_contains( $text_norm, $needle ) ) ) {
				$matched[] = $keyword;
			}
		}

		return array_values( array_unique( $matched ) );
	}

	/**
	 * @param string $type Document type key.
	 * @return string
	 */
	private function next_step_for_type( string $type ): string {
		$steps = array(
			'order_to_show_cause' => __( 'Review the return date and prepare any required response or appearance.', 'prose-core' ),
			'answer'              => __( 'Track the answer deadline and prepare your reply if you are the moving party.', 'prose-core' ),
			'order'               => __( 'Review the order requirements and note any compliance deadlines.', 'prose-core' ),
			'motion'              => __( 'Note the return date and whether a written opposition or reply is required.', 'prose-core' ),
			'judgment'            => __( 'Review judgment terms and any post-judgment filing or compliance steps.', 'prose-core' ),
		);

		return $steps[ $type ] ?? '';
	}

	/**
	 * @param string $value Raw value.
	 * @return string
	 */
	private function normalize( string $value ): string {
		$value = strtolower( trim( $value ) );
		$value = preg_replace( '/[^a-z0-9\s\-_\.]/', ' ', $value ) ?? $value;
		$value = preg_replace( '/\s+/', ' ', $value ) ?? $value;

		return trim( $value );
	}
}
