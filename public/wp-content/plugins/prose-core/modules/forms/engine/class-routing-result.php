<?php
/**
 * Routing result DTO — immutable outcome of intake routing.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Forms\Engine;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Routing_Result
 *
 * Value object returned by the Routing_Service. Carries the resolved
 * workflow, entry node, the packages available at intake, and a
 * deterministic confidence score in the 0.0–1.0 range.
 */
final class Routing_Result {

	/**
	 * Resolved workflow key.
	 *
	 * @var string
	 */
	private string $workflow_key;

	/**
	 * Resolved entry node key.
	 *
	 * @var string
	 */
	private string $node_key;

	/**
	 * Available package keys.
	 *
	 * @var string[]
	 */
	private array $available_packages;

	/**
	 * Confidence score (0.0–1.0).
	 *
	 * @var float
	 */
	private float $confidence_score;

	/**
	 * Constructor.
	 *
	 * @param string   $workflow_key       Workflow key.
	 * @param string   $node_key           Entry node key.
	 * @param string[] $available_packages Available package keys.
	 * @param float    $confidence_score   Confidence score.
	 */
	public function __construct(
		string $workflow_key = '',
		string $node_key = '',
		array $available_packages = array(),
		float $confidence_score = 0.0
	) {
		$this->workflow_key       = $workflow_key;
		$this->node_key           = $node_key;
		$this->available_packages = array_values( array_map( 'strval', $available_packages ) );
		$this->confidence_score   = $confidence_score;
	}

	/**
	 * Workflow key.
	 *
	 * @return string
	 */
	public function workflow_key(): string {
		return $this->workflow_key;
	}

	/**
	 * Entry node key.
	 *
	 * @return string
	 */
	public function node_key(): string {
		return $this->node_key;
	}

	/**
	 * Available package keys.
	 *
	 * @return string[]
	 */
	public function available_packages(): array {
		return $this->available_packages;
	}

	/**
	 * Confidence score.
	 *
	 * @return float
	 */
	public function confidence_score(): float {
		return $this->confidence_score;
	}

	/**
	 * Whether a workflow was resolved.
	 *
	 * @return bool
	 */
	public function is_resolved(): bool {
		return '' !== $this->workflow_key;
	}

	/**
	 * Serialize to array.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array(
			'workflow_key'       => $this->workflow_key,
			'node_key'           => $this->node_key,
			'available_packages' => $this->available_packages,
			'confidence_score'   => $this->confidence_score,
		);
	}
}
