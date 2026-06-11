<?php
/**
 * Routing service — resolves intake answers into a routing result.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Forms\Engine;

use ProSe\Core\Forms\Database\Repositories\Node_Repository;
use ProSe\Core\Forms\Database\Repositories\Workflow_Repository;
use ProSe\Core\Forms\Package_Repository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Routing_Service
 *
 * Orchestrates the workflow, node, and package resolvers to turn a flat
 * array of intake answers into a single deterministic Routing_Result.
 */
final class Routing_Service {

	/**
	 * Workflow resolver.
	 *
	 * @var Workflow_Resolver
	 */
	private Workflow_Resolver $workflow_resolver;

	/**
	 * Node resolver.
	 *
	 * @var Node_Resolver
	 */
	private Node_Resolver $node_resolver;

	/**
	 * Package resolver.
	 *
	 * @var Package_Resolver
	 */
	private Package_Resolver $package_resolver;

	/**
	 * Constructor.
	 *
	 * Repository-backed resolvers are wired by default; explicit resolvers
	 * may be injected (e.g. for unit tests without a database).
	 *
	 * @param Workflow_Resolver|null $workflow_resolver Workflow resolver.
	 * @param Node_Resolver|null     $node_resolver     Node resolver.
	 * @param Package_Resolver|null  $package_resolver  Package resolver.
	 */
	public function __construct(
		?Workflow_Resolver $workflow_resolver = null,
		?Node_Resolver $node_resolver = null,
		?Package_Resolver $package_resolver = null
	) {
		$this->workflow_resolver = $workflow_resolver ?? new Workflow_Resolver( new Workflow_Repository() );
		$this->node_resolver     = $node_resolver ?? new Node_Resolver( new Node_Repository() );
		$this->package_resolver  = $package_resolver ?? new Package_Resolver( new Package_Repository() );
	}

	/**
	 * Resolve intake answers into a routing result.
	 *
	 * @param array<string, mixed> $answers Intake answers.
	 * @return Routing_Result
	 */
	public function route( array $answers ): Routing_Result {
		$workflow     = $this->workflow_resolver->resolve( $answers );
		$workflow_key = (string) $workflow['workflow_key'];

		if ( '' === $workflow_key ) {
			return new Routing_Result( '', '', array(), 0.0 );
		}

		$node    = $this->node_resolver->resolve( $workflow_key );
		$package = $this->package_resolver->resolve( $workflow_key, $answers );

		$confidence = $this->combine_confidence(
			(float) $workflow['confidence_score'],
			(float) $node['confidence_score'],
			(float) $package['confidence_score']
		);

		return new Routing_Result(
			$workflow_key,
			(string) $node['node_key'],
			(array) $package['available_packages'],
			$confidence
		);
	}

	/**
	 * Combine per-resolver confidences into a single weighted score.
	 *
	 * Workflow resolution dominates (0.5); node and package each contribute
	 * 0.25. The result is rounded to two decimals for stable output.
	 *
	 * @param float $workflow Workflow confidence.
	 * @param float $node     Node confidence.
	 * @param float $package  Package confidence.
	 * @return float
	 */
	private function combine_confidence( float $workflow, float $node, float $package ): float {
		$score = ( $workflow * 0.5 ) + ( $node * 0.25 ) + ( $package * 0.25 );

		return round( $score, 2 );
	}
}
