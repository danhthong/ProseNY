<?php
/**
 * Intake state — structured facts with confidence and session metadata.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Ai_Intake;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Intake_State
 */
final class Intake_State {

	/**
	 * Confidence threshold for accepting facts.
	 */
	public const CONFIDENCE_THRESHOLD = 0.80;

	/**
	 * Fact entries keyed by field name.
	 *
	 * @var array<string, array{value: mixed, confidence: float, confirmed: bool}>
	 */
	private array $facts = array();

	/**
	 * Field awaiting user response.
	 *
	 * @var string
	 */
	private string $pending_field = '';

	/**
	 * Rolling conversation summary.
	 *
	 * @var string
	 */
	private string $conversation_summary = '';

	/**
	 * Clarification attempt counts per field.
	 *
	 * @var array<string, int>
	 */
	private array $clarification_attempts = array();

	/**
	 * Stable conversation id.
	 *
	 * @var string
	 */
	private string $conversation_id = '';

	/**
	 * Resolved workflow key (from routing).
	 *
	 * @var string|null
	 */
	private ?string $workflow = null;

	/**
	 * Resolved issue.
	 *
	 * @var string|null
	 */
	private ?string $issue = null;

	/**
	 * Resolved court.
	 *
	 * @var string|null
	 */
	private ?string $court = null;

	/**
	 * Create from array.
	 *
	 * @param array<string, mixed> $data State data.
	 * @return self
	 */
	public static function from_array( array $data ): self {
		$state = new self();

		if ( isset( $data['facts'] ) && is_array( $data['facts'] ) ) {
			foreach ( $data['facts'] as $key => $entry ) {
				if ( is_array( $entry ) && array_key_exists( 'value', $entry ) ) {
					$state->facts[ (string) $key ] = array(
						'value'      => $entry['value'],
						'confidence' => (float) ( $entry['confidence'] ?? 1.0 ),
						'confirmed'  => (bool) ( $entry['confirmed'] ?? false ),
					);
				} else {
					$state->facts[ (string) $key ] = array(
						'value'      => $entry,
						'confidence' => 1.0,
						'confirmed'  => true,
					);
				}
			}
		}

		$state->pending_field         = (string) ( $data['pending_field'] ?? '' );
		$state->conversation_summary  = (string) ( $data['conversation_summary'] ?? '' );
		$state->conversation_id       = (string) ( $data['conversation_id'] ?? '' );
		$state->workflow              = isset( $data['workflow'] ) ? (string) $data['workflow'] : null;
		$state->issue                 = isset( $data['issue'] ) ? (string) $data['issue'] : null;
		$state->court                 = isset( $data['court'] ) ? (string) $data['court'] : null;

		if ( isset( $data['clarification_attempts'] ) && is_array( $data['clarification_attempts'] ) ) {
			foreach ( $data['clarification_attempts'] as $key => $count ) {
				$state->clarification_attempts[ (string) $key ] = (int) $count;
			}
		}

		return $state;
	}

	/**
	 * Import legacy case_profile facts (plain values).
	 *
	 * @param array<string, mixed> $case_profile Case profile.
	 * @return void
	 */
	public function import_case_profile( array $case_profile ): void {
		if ( isset( $case_profile['facts'] ) && is_array( $case_profile['facts'] ) ) {
			foreach ( $case_profile['facts'] as $key => $value ) {
				if ( ! isset( $this->facts[ (string) $key ] ) ) {
					$this->facts[ (string) $key ] = array(
						'value'      => $value,
						'confidence' => 1.0,
						'confirmed'  => true,
					);
				}
			}
		}

		if ( ! empty( $case_profile['pending_field'] ) && '' === $this->pending_field ) {
			$this->pending_field = (string) $case_profile['pending_field'];
		}

		if ( ! empty( $case_profile['conversation_id'] ) && '' === $this->conversation_id ) {
			$this->conversation_id = (string) $case_profile['conversation_id'];
		}

		if ( ! empty( $case_profile['workflow'] ) && null === $this->workflow ) {
			$this->workflow = (string) $case_profile['workflow'];
		}

		if ( ! empty( $case_profile['issue'] ) && null === $this->issue ) {
			$this->issue = (string) $case_profile['issue'];
		}

		if ( ! empty( $case_profile['court'] ) && null === $this->court ) {
			$this->court = (string) $case_profile['court'];
		}
	}

	/**
	 * Merge fact updates with confidence rules.
	 *
	 * @param array<string, array{value: mixed, confidence: float}> $updates Fact updates.
	 * @return array<string, array{value: mixed, confidence: float}> Applied updates.
	 */
	public function merge_updates( array $updates ): array {
		$applied = array();

		foreach ( $updates as $key => $update ) {
			if ( ! is_array( $update ) || ! array_key_exists( 'value', $update ) ) {
				continue;
			}

			$confidence = (float) ( $update['confidence'] ?? 0.0 );
			$value      = $update['value'];

			if ( null === $value || ( is_string( $value ) && '' === trim( $value ) ) ) {
				continue;
			}

			if ( $confidence < self::CONFIDENCE_THRESHOLD ) {
				continue;
			}

			$existing = $this->facts[ $key ] ?? null;

			if ( null !== $existing && ! empty( $existing['confirmed'] ) ) {
				continue;
			}

			if ( null !== $existing && $confidence < (float) $existing['confidence'] ) {
				continue;
			}

			$this->facts[ $key ] = array(
				'value'      => $value,
				'confidence' => $confidence,
				'confirmed'  => $confidence >= 0.95,
			);

			$applied[ $key ] = $this->facts[ $key ];
		}

		return $applied;
	}

	/**
	 * Export plain fact values for routing/completion.
	 *
	 * @return array<string, mixed>
	 */
	public function plain_facts(): array {
		$plain = array();

		foreach ( $this->facts as $key => $entry ) {
			$plain[ $key ] = $entry['value'];
		}

		return $plain;
	}

	/**
	 * Serialize to array.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array(
			'conversation_id'        => $this->conversation_id,
			'workflow'                 => $this->workflow,
			'issue'                    => $this->issue,
			'court'                    => $this->court,
			'facts'                    => $this->facts,
			'pending_field'            => $this->pending_field,
			'conversation_summary'     => $this->conversation_summary,
			'clarification_attempts'   => $this->clarification_attempts,
		);
	}

	/**
	 * Export as legacy case_profile for widget compatibility.
	 *
	 * @param int $completion Completion percentage.
	 * @return array<string, mixed>
	 */
	public function to_case_profile( int $completion = 0 ): array {
		return array(
			'conversation_id' => $this->conversation_id,
			'issue'           => $this->issue,
			'court'           => $this->court,
			'workflow'        => $this->workflow,
			'facts'           => $this->plain_facts(),
			'pending_field'   => $this->pending_field,
			'progress'        => $completion,
		);
	}

	/**
	 * Get fact entry.
	 *
	 * @param string $key Field key.
	 * @return array{value: mixed, confidence: float, confirmed: bool}|null
	 */
	public function get_fact( string $key ): ?array {
		return $this->facts[ $key ] ?? null;
	}

	/**
	 * Whether a field is filled with sufficient confidence.
	 *
	 * @param string $key Field key.
	 * @return bool
	 */
	public function is_filled( string $key ): bool {
		$fact = $this->get_fact( $key );

		if ( null === $fact ) {
			return false;
		}

		return (float) $fact['confidence'] >= self::CONFIDENCE_THRESHOLD
			&& null !== $fact['value']
			&& ( ! is_string( $fact['value'] ) || '' !== trim( (string) $fact['value'] ) );
	}

	/**
	 * Increment clarification attempts for a field.
	 *
	 * @param string $field Field key.
	 * @return int New count.
	 */
	public function increment_clarification( string $field ): int {
		if ( '' === $field ) {
			return 0;
		}

		$this->clarification_attempts[ $field ] = ( $this->clarification_attempts[ $field ] ?? 0 ) + 1;

		return $this->clarification_attempts[ $field ];
	}

	/**
	 * Get clarification attempt count.
	 *
	 * @param string $field Field key.
	 * @return int
	 */
	public function clarification_count( string $field ): int {
		return $this->clarification_attempts[ $field ] ?? 0;
	}

	/**
	 * @return string
	 */
	public function pending_field(): string {
		return $this->pending_field;
	}

	/**
	 * @param string $field Pending field.
	 * @return void
	 */
	public function set_pending_field( string $field ): void {
		$this->pending_field = $field;
	}

	/**
	 * @return string
	 */
	public function conversation_summary(): string {
		return $this->conversation_summary;
	}

	/**
	 * @param string $summary Summary text.
	 * @return void
	 */
	public function set_conversation_summary( string $summary ): void {
		$this->conversation_summary = $summary;
	}

	/**
	 * @return string
	 */
	public function conversation_id(): string {
		return $this->conversation_id;
	}

	/**
	 * @param string $id Conversation id.
	 * @return void
	 */
	public function set_conversation_id( string $id ): void {
		$this->conversation_id = $id;
	}

	/**
	 * @return string|null
	 */
	public function workflow(): ?string {
		return $this->workflow;
	}

	/**
	 * @param string|null $workflow Workflow key.
	 * @return void
	 */
	public function set_workflow( ?string $workflow ): void {
		$this->workflow = $workflow;
	}

	/**
	 * @param string|null $issue Issue type.
	 * @return void
	 */
	public function set_issue( ?string $issue ): void {
		$this->issue = $issue;
	}

	/**
	 * @param string|null $court Court type.
	 * @return void
	 */
	public function set_court( ?string $court ): void {
		$this->court = $court;
	}

	/**
	 * @return array<string, array{value: mixed, confidence: float, confirmed: bool}>
	 */
	public function facts(): array {
		return $this->facts;
	}
}
