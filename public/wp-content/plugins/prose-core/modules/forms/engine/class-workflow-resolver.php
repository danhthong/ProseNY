<?php
/**
 * Workflow resolver — maps intake answers to a workflow key.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Forms\Engine;

use ProSe\Core\Forms\Classification\Vocabulary;
use ProSe\Core\Forms\Database\Repositories\Workflow_Repository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Workflow_Resolver
 *
 * Deterministic decision tree that resolves a single workflow key from a
 * normalized set of intake answers. No AI, no randomness: the same answers
 * always yield the same workflow.
 */
final class Workflow_Resolver {

	use Answer_Normalizer;

	/**
	 * Direct issue token to workflow key mapping (non-divorce tracks).
	 *
	 * @var array<string, string>
	 */
	private const ISSUE_WORKFLOWS = array(
		'custody'             => Vocabulary::WF_CUSTODY,
		'child_support'       => Vocabulary::WF_CHILD_SUPPORT,
		'order_of_protection' => Vocabulary::WF_ORDER_OF_PROTECTION,
	);

	/**
	 * Optional workflow repository for active-key validation.
	 *
	 * @var Workflow_Repository|null
	 */
	private ?Workflow_Repository $workflows;

	/**
	 * Constructor.
	 *
	 * @param Workflow_Repository|null $workflows Workflow repository.
	 */
	public function __construct( ?Workflow_Repository $workflows = null ) {
		$this->workflows = $workflows;
	}

	/**
	 * Resolve a workflow key from intake answers.
	 *
	 * @param array<string, mixed> $answers Intake answers.
	 * @return array{workflow_key: string, confidence_score: float}
	 */
	public function resolve( array $answers ): array {
		$issue = $this->normalize_token( $answers['issue'] ?? '' );

		if ( '' !== $issue && isset( self::ISSUE_WORKFLOWS[ $issue ] ) ) {
			return $this->finalize( self::ISSUE_WORKFLOWS[ $issue ], 1.0 );
		}

		if ( '' === $issue || 'divorce' === $issue ) {
			return $this->resolve_divorce( $answers );
		}

		return $this->finalize( '', 0.0 );
	}

	/**
	 * Resolve the divorce sub-track from answers.
	 *
	 * @param array<string, mixed> $answers Intake answers.
	 * @return array{workflow_key: string, confidence_score: float}
	 */
	private function resolve_divorce( array $answers ): array {
		$spouse_agrees    = $this->to_bool( $answers['spouse_agrees'] ?? null );
		$spouse_responded = $this->to_bool( $answers['spouse_responded'] ?? null );
		$is_default       = $this->to_bool( $answers['default'] ?? ( $answers['is_default'] ?? null ) );

		// A non-responding spouse routes to the default judgment track.
		if ( false === $spouse_responded || true === $is_default ) {
			return $this->finalize( Vocabulary::WF_DEFAULT_DIVORCE, 0.9 );
		}

		if ( true === $spouse_agrees ) {
			return $this->finalize( Vocabulary::WF_UNCONTESTED_DIVORCE, 1.0 );
		}

		if ( false === $spouse_agrees ) {
			return $this->finalize( Vocabulary::WF_CONTESTED_DIVORCE, 1.0 );
		}

		return $this->finalize( '', 0.0 );
	}

	/**
	 * Finalize a resolution, optionally validating against the repository.
	 *
	 * @param string $workflow_key Resolved key.
	 * @param float  $confidence   Base confidence.
	 * @return array{workflow_key: string, confidence_score: float}
	 */
	private function finalize( string $workflow_key, float $confidence ): array {
		if ( '' !== $workflow_key && $this->workflows instanceof Workflow_Repository ) {
			if ( ! $this->workflows->key_exists( $workflow_key ) ) {
				$confidence = min( $confidence, 0.5 );
			}
		}

		return array(
			'workflow_key'     => $workflow_key,
			'confidence_score' => $confidence,
		);
	}
}
