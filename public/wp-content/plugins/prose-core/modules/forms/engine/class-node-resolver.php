<?php
/**
 * Node resolver — maps a workflow key to its entry node.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Forms\Engine;

use ProSe\Core\Forms\Classification\Vocabulary;
use ProSe\Core\Forms\Database\Repositories\Node_Repository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Node_Resolver
 *
 * Resolves the entry node for a workflow. When a node repository is
 * available it reads the seeded graph (entry flag, then lowest sequence);
 * otherwise it falls back to the canonical entry-node map so routing stays
 * deterministic and unit-testable without a database.
 */
final class Node_Resolver {

	/**
	 * Canonical entry node per workflow.
	 *
	 * @var array<string, string>
	 */
	private const ENTRY_NODES = array(
		Vocabulary::WF_UNCONTESTED_DIVORCE => Vocabulary::NODE_1001_DIVORCE_FILED,
		Vocabulary::WF_CONTESTED_DIVORCE   => Vocabulary::NODE_1001_DIVORCE_FILED,
		Vocabulary::WF_DEFAULT_DIVORCE     => Vocabulary::NODE_1001_DIVORCE_FILED,
		Vocabulary::WF_CUSTODY             => Vocabulary::NODE_2001_CUSTODY_PETITION,
		Vocabulary::WF_CHILD_SUPPORT       => Vocabulary::NODE_3001_SUPPORT_PETITION,
		Vocabulary::WF_ORDER_OF_PROTECTION => Vocabulary::NODE_4001_FAMILY_OFFENSE,
	);

	/**
	 * Optional node repository.
	 *
	 * @var Node_Repository|null
	 */
	private ?Node_Repository $nodes;

	/**
	 * Constructor.
	 *
	 * @param Node_Repository|null $nodes Node repository.
	 */
	public function __construct( ?Node_Repository $nodes = null ) {
		$this->nodes = $nodes;
	}

	/**
	 * Resolve the entry node for a workflow key.
	 *
	 * @param string $workflow_key Workflow key.
	 * @return array{node_key: string, confidence_score: float}
	 */
	public function resolve( string $workflow_key ): array {
		if ( '' === $workflow_key ) {
			return array(
				'node_key'         => '',
				'confidence_score' => 0.0,
			);
		}

		if ( $this->nodes instanceof Node_Repository ) {
			$node_key = $this->select_entry_node( $workflow_key );

			if ( '' !== $node_key ) {
				return array(
					'node_key'         => $node_key,
					'confidence_score' => 1.0,
				);
			}
		}

		if ( isset( self::ENTRY_NODES[ $workflow_key ] ) ) {
			return array(
				'node_key'         => self::ENTRY_NODES[ $workflow_key ],
				'confidence_score' => 0.9,
			);
		}

		return array(
			'node_key'         => '',
			'confidence_score' => 0.0,
		);
	}

	/**
	 * Select the entry node from the seeded graph.
	 *
	 * @param string $workflow_key Workflow key.
	 * @return string
	 */
	private function select_entry_node( string $workflow_key ): string {
		$rows     = $this->nodes->list_by_workflow( $workflow_key );
		$fallback = '';
		$best_seq = PHP_INT_MAX;

		foreach ( $rows as $row ) {
			if ( ! empty( $row->is_entry ) ) {
				return (string) $row->node_key;
			}

			$sequence = (int) ( $row->sequence ?? 0 );

			if ( $sequence < $best_seq ) {
				$best_seq = $sequence;
				$fallback = (string) $row->node_key;
			}
		}

		return $fallback;
	}
}
