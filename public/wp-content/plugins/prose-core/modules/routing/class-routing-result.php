<?php
/**
 * Routing Result — standardized outcome of the routing pipeline.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Routing;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Routing_Result
 */
final class Routing_Result {

	/**
	 * Resolved issue type.
	 *
	 * @var string|null
	 */
	private ?string $issue;

	/**
	 * Resolved court.
	 *
	 * @var string|null
	 */
	private ?string $court;

	/**
	 * Resolved workflow key.
	 *
	 * @var string|null
	 */
	private ?string $workflow;

	/**
	 * Confidence score.
	 *
	 * @var float
	 */
	private float $confidence;

	/**
	 * Candidate workflows when ambiguous.
	 *
	 * @var string[]
	 */
	private array $candidate_workflows;

	/**
	 * Missing discriminator fields.
	 *
	 * @var string[]
	 */
	private array $missing_fields;

	/**
	 * Required form codes from the resolved workflow.
	 *
	 * @var string[]
	 */
	private array $required_form_codes;

	/**
	 * Constructor.
	 *
	 * @param string|null $issue                Issue type.
	 * @param string|null $court                Court.
	 * @param string|null $workflow             Workflow key.
	 * @param float       $confidence           Confidence score.
	 * @param string[]    $candidate_workflows  Candidate workflows.
	 * @param string[]    $missing_fields       Missing fields.
	 * @param string[]    $required_form_codes  Required form codes.
	 */
	public function __construct(
		?string $issue = null,
		?string $court = null,
		?string $workflow = null,
		float $confidence = 0.0,
		array $candidate_workflows = array(),
		array $missing_fields = array(),
		array $required_form_codes = array()
	) {
		$this->issue                = $issue;
		$this->court                = $court;
		$this->workflow             = $workflow;
		$this->confidence           = $confidence;
		$this->candidate_workflows  = array_values( array_map( 'strval', $candidate_workflows ) );
		$this->missing_fields       = array_values( array_map( 'strval', $missing_fields ) );
		$this->required_form_codes  = array_values( array_map( 'strval', $required_form_codes ) );
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
	 * Confidence score.
	 *
	 * @return float
	 */
	public function confidence(): float {
		return $this->confidence;
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
	 * Missing fields.
	 *
	 * @return string[]
	 */
	public function missing_fields(): array {
		return $this->missing_fields;
	}

	/**
	 * Required form codes.
	 *
	 * @return string[]
	 */
	public function required_form_codes(): array {
		return $this->required_form_codes;
	}

	/**
	 * Whether a workflow was resolved.
	 *
	 * @return bool
	 */
	public function is_resolved(): bool {
		return null !== $this->workflow && '' !== $this->workflow;
	}

	/**
	 * Serialize to array.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array(
			'issue'                => $this->issue,
			'court'                => $this->court,
			'workflow'             => $this->workflow,
			'confidence'           => $this->confidence,
			'candidate_workflows'  => $this->candidate_workflows,
			'missing_fields'       => $this->missing_fields,
			'required_form_codes'  => $this->required_form_codes,
		);
	}
}
