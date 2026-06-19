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
	 * All courts involved (primary first).
	 *
	 * @var string[]
	 */
	private array $courts;

	/**
	 * Whether multiple courts apply.
	 *
	 * @var bool
	 */
	private bool $overlap;

	/**
	 * Machine-readable overlap reason key.
	 *
	 * @var string|null
	 */
	private ?string $overlap_reason;

	/**
	 * User-facing overlap explanation.
	 *
	 * @var string
	 */
	private string $routing_explanation;

	/**
	 * User-facing single-court redirect note.
	 *
	 * @var string
	 */
	private string $routing_note;

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
	 * @param string[]    $courts               Courts involved.
	 * @param bool        $overlap              Overlap flag.
	 * @param string|null $overlap_reason       Overlap reason key.
	 * @param string      $routing_explanation  Overlap explanation.
	 * @param string      $routing_note         Redirect note.
	 */
	public function __construct(
		?string $issue = null,
		?string $court = null,
		?string $workflow = null,
		float $confidence = 0.0,
		array $candidate_workflows = array(),
		array $missing_fields = array(),
		array $required_form_codes = array(),
		array $courts = array(),
		bool $overlap = false,
		?string $overlap_reason = null,
		string $routing_explanation = '',
		string $routing_note = ''
	) {
		$this->issue                = $issue;
		$this->court                = $court;
		$this->workflow             = $workflow;
		$this->confidence           = $confidence;
		$this->candidate_workflows  = array_values( array_map( 'strval', $candidate_workflows ) );
		$this->missing_fields       = array_values( array_map( 'strval', $missing_fields ) );
		$this->required_form_codes  = array_values( array_map( 'strval', $required_form_codes ) );
		$this->courts               = array_values( array_map( 'strval', $courts ) );
		$this->overlap              = $overlap;
		$this->overlap_reason       = $overlap_reason;
		$this->routing_explanation  = $routing_explanation;
		$this->routing_note         = $routing_note;
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
	 * Courts involved in this matter.
	 *
	 * @return string[]
	 */
	public function courts(): array {
		return $this->courts;
	}

	/**
	 * Whether multiple courts apply.
	 *
	 * @return bool
	 */
	public function overlap(): bool {
		return $this->overlap;
	}

	/**
	 * Overlap reason key.
	 *
	 * @return string|null
	 */
	public function overlap_reason(): ?string {
		return $this->overlap_reason;
	}

	/**
	 * User-facing overlap explanation.
	 *
	 * @return string
	 */
	public function routing_explanation(): string {
		return $this->routing_explanation;
	}

	/**
	 * User-facing routing redirect note.
	 *
	 * @return string
	 */
	public function routing_note(): string {
		return $this->routing_note;
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
			'courts'               => $this->courts,
			'overlap'              => $this->overlap,
			'overlap_reason'       => $this->overlap_reason,
			'routing_explanation'  => $this->routing_explanation,
			'routing_note'         => $this->routing_note,
		);
	}
}
