<?php
/**
 * Question Selector — chooses the single next question to ask.
 *
 * Question text for resolved workflows always comes from workflow metadata
 * (required_fields[].question). Workflow-resolution questions (when the routing
 * engine is still ambiguous) come from an engine-owned map, since discriminator
 * keys are not part of any single workflow's required_fields.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Intake;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Question_Selector
 */
final class Question_Selector {

	/**
	 * Engine-owned resolution questions for routing discriminator keys.
	 *
	 * @var array<string, string>
	 */
	private const RESOLUTION_QUESTIONS = array(
		'children'                  => 'Do you have any children under 21?',
		'spouse_agrees'             => 'Does your spouse agree to the divorce?',
		'marital_property_resolved' => 'Do you and your spouse agree on property and finances?',
		'spouse_responded'          => 'Did your spouse respond to the divorce papers?',
		'active_divorce'            => 'Is there an active divorce case?',
		'protection_needed'         => 'Do you need protection from someone who has harmed or threatened you?',
	);

	/**
	 * Select the next question.
	 *
	 * @param array<int, array<string, mixed>> $required_fields    Workflow required_fields.
	 * @param string[]                         $missing_field_keys Ordered missing required keys.
	 * @param string|null                      $workflow           Resolved workflow key.
	 * @param string[]                         $routing_missing    Routing discriminator missing keys.
	 * @return array{field: string, question: string}
	 */
	public function select( array $required_fields, array $missing_field_keys, ?string $workflow, array $routing_missing = array() ): array {
		if ( null !== $workflow && '' !== $workflow ) {
			return $this->next_required_question( $required_fields, $missing_field_keys );
		}

		return $this->next_resolution_question( $routing_missing );
	}

	/**
	 * First missing required field's question, in workflow order.
	 *
	 * @param array<int, array<string, mixed>> $required_fields    Required fields.
	 * @param string[]                         $missing_field_keys Missing keys.
	 * @return array{field: string, question: string}
	 */
	private function next_required_question( array $required_fields, array $missing_field_keys ): array {
		$missing = array_flip( $missing_field_keys );

		foreach ( $required_fields as $field ) {
			$key = (string) ( $field['key'] ?? '' );

			if ( '' === $key || ! isset( $missing[ $key ] ) ) {
				continue;
			}

			return array(
				'field'    => $key,
				'question' => (string) ( $field['question'] ?? '' ),
			);
		}

		return array(
			'field'    => '',
			'question' => '',
		);
	}

	/**
	 * Next workflow-resolution question for an ambiguous routing result.
	 *
	 * @param string[] $routing_missing Routing discriminator keys.
	 * @return array{field: string, question: string}
	 */
	private function next_resolution_question( array $routing_missing ): array {
		foreach ( $routing_missing as $key ) {
			$key = (string) $key;

			if ( isset( self::RESOLUTION_QUESTIONS[ $key ] ) ) {
				return array(
					'field'    => $key,
					'question' => self::RESOLUTION_QUESTIONS[ $key ],
				);
			}
		}

		if ( ! empty( $routing_missing ) ) {
			return array(
				'field'    => (string) $routing_missing[0],
				'question' => 'Could you tell me a bit more about your situation?',
			);
		}

		return array(
			'field'    => '',
			'question' => '',
		);
	}
}
