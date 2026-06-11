<?php
/**
 * Seed wp_prose_deadline_rules (templates).
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Forms\Database\Seeders;

use ProSe\Core\Forms\Classification\Vocabulary;
use ProSe\Core\Forms\Database\Repositories\Deadline_Rule_Repository;
use ProSe\Core\Forms\Database\Repositories\Node_Repository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Deadline_Rules_Seeder
 */
final class Deadline_Rules_Seeder {

	/**
	 * Deadline rule repository.
	 *
	 * @var Deadline_Rule_Repository
	 */
	private Deadline_Rule_Repository $rules;

	/**
	 * Node repository.
	 *
	 * @var Node_Repository
	 */
	private Node_Repository $nodes;

	/**
	 * Constructor.
	 *
	 * @param Deadline_Rule_Repository|null $rules Rules repo.
	 * @param Node_Repository|null          $nodes Nodes repo.
	 */
	public function __construct( ?Deadline_Rule_Repository $rules = null, ?Node_Repository $nodes = null ) {
		$this->rules = $rules ?? new Deadline_Rule_Repository();
		$this->nodes = $nodes ?? new Node_Repository();
	}

	/**
	 * Seed canonical NYC deadline rules.
	 *
	 * @return int Number seeded.
	 */
	public function seed(): int {
		$defs  = $this->definitions();
		$count = 0;

		foreach ( $defs as $def ) {
			if ( ! empty( $def['node_key'] ) ) {
				$def['node_id'] = $this->nodes->get_id_by_key( (string) $def['node_key'] );
				unset( $def['node_key'] );
			}

			if ( $this->rules->upsert( $def ) > 0 ) {
				++$count;
			}
		}

		return $count;
	}

	/**
	 * Deadline rule definitions.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function definitions(): array {
		return array(
			array(
				'deadline_key'  => 'DL_ANSWER_AFTER_SERVICE',
				'workflow_key'  => Vocabulary::WF_CONTESTED_DIVORCE,
				'node_key'      => Vocabulary::NODE_1003_ANSWER_FILED,
				'trigger_event' => 'SERVICE_COMPLETE',
				'offset_days'   => 20,
				'day_type'      => 'calendar',
				'direction'     => 'after',
				'label'         => __( 'Answer Due', 'prose-core' ),
				'description'   => __( 'Defendant must answer within 20 days after service.', 'prose-core' ),
				'statute_ref'   => 'CPLR 3012',
			),
			array(
				'deadline_key'  => 'DL_DEFAULT_AFTER_SERVICE',
				'workflow_key'  => Vocabulary::WF_DEFAULT_DIVORCE,
				'node_key'      => Vocabulary::NODE_1010_JUDGMENT,
				'trigger_event' => 'SERVICE_COMPLETE',
				'offset_days'   => 40,
				'day_type'      => 'calendar',
				'direction'     => 'after',
				'label'         => __( 'Default Judgment Eligible', 'prose-core' ),
				'description'   => __( 'Plaintiff may seek default judgment 40 days after service if no answer.', 'prose-core' ),
			),
			array(
				'deadline_key'  => 'DL_RJI_AFTER_COMMENCEMENT',
				'workflow_key'  => Vocabulary::WF_CONTESTED_DIVORCE,
				'node_key'      => Vocabulary::NODE_1005_PRELIMINARY_CONFERENCE,
				'trigger_event' => 'ACTION_COMMENCED',
				'offset_days'   => 45,
				'day_type'      => 'calendar',
				'direction'     => 'after',
				'label'         => __( 'RJI Due', 'prose-core' ),
				'description'   => __( 'Request for Judicial Intervention due within 45 days of commencement.', 'prose-core' ),
			),
			array(
				'deadline_key'  => 'DL_NOTE_OF_ISSUE',
				'workflow_key'  => Vocabulary::WF_CONTESTED_DIVORCE,
				'node_key'      => Vocabulary::NODE_1009_TRIAL,
				'trigger_event' => 'DISCOVERY_COMPLETE',
				'offset_days'   => 0,
				'day_type'      => 'calendar',
				'direction'     => 'after',
				'label'         => __( 'Note of Issue', 'prose-core' ),
				'description'   => __( 'File Note of Issue when ready for trial.', 'prose-core' ),
			),
			array(
				'deadline_key'  => 'DL_OP_HEARING',
				'workflow_key'  => Vocabulary::WF_ORDER_OF_PROTECTION,
				'node_key'      => Vocabulary::NODE_4002_TEMP_OP,
				'trigger_event' => 'PETITION_FILED',
				'offset_days'   => 0,
				'day_type'      => 'calendar',
				'direction'     => 'after',
				'label'         => __( 'OP Hearing', 'prose-core' ),
				'description'   => __( 'Family Court hearing on order of protection petition.', 'prose-core' ),
			),
		);
	}
}
