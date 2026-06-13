<?php
/**
 * Case Profile — canonical session state for intake routing.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Routing;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Case_Profile
 *
 * Maintains structured routing state across multiple intake interactions.
 */
final class Case_Profile {

	/**
	 * Stable conversation identifier (future persistence/resume key).
	 *
	 * @var string
	 */
	private string $conversation_id = '';

	/**
	 * Resolved issue type.
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
	 * Resolved workflow key.
	 *
	 * @var string|null
	 */
	private ?string $workflow = null;

	/**
	 * Workflow confidence score.
	 *
	 * @var float
	 */
	private float $workflow_confidence = 0.0;

	/**
	 * Fact store.
	 *
	 * @var Fact_Store
	 */
	private Fact_Store $facts;

	/**
	 * Missing discriminator fields.
	 *
	 * @var string[]
	 */
	private array $missing_fields = array();

	/**
	 * Candidate workflows when ambiguous.
	 *
	 * @var string[]
	 */
	private array $candidate_workflows = array();

	/**
	 * Progress placeholder for future workflow tracking.
	 *
	 * @var int
	 */
	private int $progress = 0;

	/**
	 * Constructor.
	 *
	 * @param Fact_Store|null $facts Optional fact store.
	 */
	public function __construct( ?Fact_Store $facts = null ) {
		$this->facts = $facts ?? new Fact_Store();
	}

	/**
	 * Create a profile from an array.
	 *
	 * @param array<string, mixed> $data Profile data.
	 * @return self
	 */
	public static function from_array( array $data ): self {
		$facts = Fact_Store::from_array( is_array( $data['facts'] ?? null ) ? $data['facts'] : array() );
		$profile = new self( $facts );

		$profile->conversation_id      = isset( $data['conversation_id'] ) && is_string( $data['conversation_id'] ) ? $data['conversation_id'] : '';
		$profile->issue                = isset( $data['issue'] ) && is_string( $data['issue'] ) && '' !== $data['issue'] ? $data['issue'] : null;
		$profile->court                = isset( $data['court'] ) && is_string( $data['court'] ) && '' !== $data['court'] ? $data['court'] : null;
		$profile->workflow             = isset( $data['workflow'] ) && is_string( $data['workflow'] ) && '' !== $data['workflow'] ? $data['workflow'] : null;
		$profile->workflow_confidence  = isset( $data['workflow_confidence'] ) ? (float) $data['workflow_confidence'] : 0.0;
		$profile->missing_fields       = array_values( array_map( 'strval', (array) ( $data['missing_fields'] ?? array() ) ) );
		$profile->candidate_workflows  = array_values( array_map( 'strval', (array) ( $data['candidate_workflows'] ?? array() ) ) );
		$profile->progress             = isset( $data['progress'] ) ? (int) $data['progress'] : 0;

		return $profile;
	}

	/**
	 * Conversation identifier.
	 *
	 * @return string
	 */
	public function conversation_id(): string {
		return $this->conversation_id;
	}

	/**
	 * Set the conversation identifier.
	 *
	 * @param string $conversation_id Conversation identifier.
	 * @return void
	 */
	public function set_conversation_id( string $conversation_id ): void {
		$this->conversation_id = $conversation_id;
	}

	/**
	 * Fact store accessor.
	 *
	 * @return Fact_Store
	 */
	public function facts(): Fact_Store {
		return $this->facts;
	}

	/**
	 * Issue type.
	 *
	 * @return string|null
	 */
	public function issue(): ?string {
		return $this->issue;
	}

	/**
	 * Court.
	 *
	 * @return string|null
	 */
	public function court(): ?string {
		return $this->court;
	}

	/**
	 * Workflow key.
	 *
	 * @return string|null
	 */
	public function workflow(): ?string {
		return $this->workflow;
	}

	/**
	 * Workflow confidence.
	 *
	 * @return float
	 */
	public function workflow_confidence(): float {
		return $this->workflow_confidence;
	}

	/**
	 * Missing fields.
	 *
	 * @return string[]
	 */
	public function missing_fields(): array {
		return $this->missing_fields;
	}

	/**
	 * Candidate workflows.
	 *
	 * @return string[]
	 */
	public function candidate_workflows(): array {
		return $this->candidate_workflows;
	}

	/**
	 * Progress value.
	 *
	 * @return int
	 */
	public function progress(): int {
		return $this->progress;
	}

	/**
	 * Apply a routing result to the profile.
	 *
	 * @param Routing_Result $result Routing result.
	 * @return void
	 */
	public function apply_result( Routing_Result $result ): void {
		$this->issue               = $result->issue();
		$this->court               = $result->court();
		$this->workflow            = $result->workflow();
		$this->workflow_confidence = $result->confidence();
		$this->missing_fields      = $result->missing_fields();
		$this->candidate_workflows = $result->candidate_workflows();
	}

	/**
	 * Serialize to array.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array(
			'conversation_id'      => $this->conversation_id,
			'issue'                => $this->issue,
			'court'                => $this->court,
			'workflow'             => $this->workflow,
			'workflow_confidence'  => $this->workflow_confidence,
			'facts'                => $this->facts->export(),
			'missing_fields'       => $this->missing_fields,
			'candidate_workflows'  => $this->candidate_workflows,
			'progress'             => $this->progress,
		);
	}
}
