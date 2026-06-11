<?php
/**
 * Seed workflow nodes and edges from Vocabulary / Workflow_Node_Mapper constants.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Forms\Database\Seeders;

use ProSe\Core\Forms\Classification\Vocabulary;
use ProSe\Core\Forms\Database\Repositories\Edge_Repository;
use ProSe\Core\Forms\Database\Repositories\Node_Repository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Graph_Seeder
 */
final class Graph_Seeder {

	/**
	 * Node repository.
	 *
	 * @var Node_Repository
	 */
	private Node_Repository $nodes;

	/**
	 * Edge repository.
	 *
	 * @var Edge_Repository
	 */
	private Edge_Repository $edges;

	/**
	 * Constructor.
	 *
	 * @param Node_Repository|null $nodes Node repo.
	 * @param Edge_Repository|null $edges Edge repo.
	 */
	public function __construct( ?Node_Repository $nodes = null, ?Edge_Repository $edges = null ) {
		$this->nodes = $nodes ?? new Node_Repository();
		$this->edges = $edges ?? new Edge_Repository();
	}

	/**
	 * Seed nodes and edges.
	 *
	 * @return array{nodes: int, edges: int}
	 */
	public function seed(): array {
		$node_defs = $this->node_definitions();
		$key_to_id = array();
		$seq       = 0;

		foreach ( $node_defs as $def ) {
			++$seq;
			$def['sequence'] = $seq;
			$id              = $this->nodes->upsert( $def );

			if ( $id > 0 ) {
				$key_to_id[ $def['node_key'] ] = $id;
			}
		}

		$edge_count = $this->seed_edges( $key_to_id );

		return array(
			'nodes' => count( $key_to_id ),
			'edges' => $edge_count,
		);
	}

	/**
	 * Node catalog definitions.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function node_definitions(): array {
		return array(
			array(
				'node_key'      => Vocabulary::NODE_1001_DIVORCE_FILED,
				'workflow_key'  => Vocabulary::WF_UNCONTESTED_DIVORCE,
				'stage'         => Vocabulary::STAGE_COMMENCEMENT,
				'court_routing' => Vocabulary::ROUTE_SUPREME_COURT,
				'node_type'     => 'filing',
				'label'         => __( 'Divorce Commenced', 'prose-core' ),
				'is_entry'      => true,
				'trigger_events' => array( 'ACTION_COMMENCED' ),
			),
			array(
				'node_key'      => Vocabulary::NODE_1002_SERVICE_COMPLETE,
				'workflow_key'  => Vocabulary::WF_UNCONTESTED_DIVORCE,
				'stage'         => Vocabulary::STAGE_SERVICE,
				'court_routing' => Vocabulary::ROUTE_SUPREME_COURT,
				'node_type'     => 'service',
				'label'         => __( 'Service Complete', 'prose-core' ),
				'completion_events' => array( 'SERVICE_COMPLETE' ),
			),
			array(
				'node_key'      => Vocabulary::NODE_1003_ANSWER_FILED,
				'workflow_key'  => Vocabulary::WF_CONTESTED_DIVORCE,
				'stage'         => Vocabulary::STAGE_RESPONSE,
				'court_routing' => Vocabulary::ROUTE_SUPREME_COURT,
				'node_type'     => 'response',
				'label'         => __( 'Answer Filed', 'prose-core' ),
				'completion_events' => array( 'ANSWER_FILED' ),
			),
			array(
				'node_key'      => Vocabulary::NODE_1004_OSC_FILED,
				'workflow_key'  => Vocabulary::WF_MOTION_PRACTICE,
				'stage'         => Vocabulary::STAGE_TEMPORARY_RELIEF,
				'court_routing' => Vocabulary::ROUTE_SUPREME_COURT,
				'node_type'     => 'motion',
				'label'         => __( 'Order to Show Cause Filed', 'prose-core' ),
			),
			array(
				'node_key'      => Vocabulary::NODE_1005_PRELIMINARY_CONFERENCE,
				'workflow_key'  => Vocabulary::WF_CONTESTED_DIVORCE,
				'stage'         => Vocabulary::STAGE_PRELIMINARY_CONFERENCE,
				'court_routing' => Vocabulary::ROUTE_SUPREME_COURT,
				'node_type'     => 'conference',
				'label'         => __( 'Preliminary Conference', 'prose-core' ),
				'completion_events' => array( 'PRELIM_CONFERENCE_HELD' ),
			),
			array(
				'node_key'      => Vocabulary::NODE_1006_DISCOVERY,
				'workflow_key'  => Vocabulary::WF_DISCOVERY,
				'stage'         => Vocabulary::STAGE_DISCOVERY,
				'court_routing' => Vocabulary::ROUTE_SUPREME_COURT,
				'node_type'     => 'discovery',
				'label'         => __( 'Discovery', 'prose-core' ),
				'completion_events' => array( 'DISCOVERY_COMPLETE' ),
			),
			array(
				'node_key'      => Vocabulary::NODE_1007_COMPLIANCE_CONFERENCE,
				'workflow_key'  => Vocabulary::WF_DISCOVERY,
				'stage'         => Vocabulary::STAGE_COMPLIANCE_CONFERENCE,
				'court_routing' => Vocabulary::ROUTE_SUPREME_COURT,
				'node_type'     => 'conference',
				'label'         => __( 'Compliance Conference', 'prose-core' ),
			),
			array(
				'node_key'      => Vocabulary::NODE_1008_SETTLEMENT,
				'workflow_key'  => Vocabulary::WF_CONTESTED_DIVORCE,
				'stage'         => Vocabulary::STAGE_SETTLEMENT,
				'court_routing' => Vocabulary::ROUTE_SUPREME_COURT,
				'node_type'     => 'settlement',
				'label'         => __( 'Settlement', 'prose-core' ),
				'completion_events' => array( 'SETTLEMENT_REACHED' ),
			),
			array(
				'node_key'      => Vocabulary::NODE_1009_TRIAL,
				'workflow_key'  => Vocabulary::WF_CONTESTED_DIVORCE,
				'stage'         => Vocabulary::STAGE_TRIAL,
				'court_routing' => Vocabulary::ROUTE_SUPREME_COURT,
				'node_type'     => 'trial',
				'label'         => __( 'Trial', 'prose-core' ),
			),
			array(
				'node_key'      => Vocabulary::NODE_1010_JUDGMENT,
				'workflow_key'  => Vocabulary::WF_UNCONTESTED_DIVORCE,
				'stage'         => Vocabulary::STAGE_JUDGMENT,
				'court_routing' => Vocabulary::ROUTE_SUPREME_COURT,
				'node_type'     => 'judgment',
				'label'         => __( 'Judgment Entered', 'prose-core' ),
				'is_terminal'   => true,
				'completion_events' => array( 'JUDGMENT_ENTERED' ),
			),
			array(
				'node_key'      => Vocabulary::NODE_2001_CUSTODY_PETITION,
				'workflow_key'  => Vocabulary::WF_CUSTODY,
				'stage'         => Vocabulary::STAGE_PETITION,
				'court_routing' => Vocabulary::ROUTE_FAMILY_COURT,
				'node_type'     => 'petition',
				'label'         => __( 'Custody Petition Filed', 'prose-core' ),
				'is_entry'      => true,
				'trigger_events' => array( 'PETITION_FILED' ),
			),
			array(
				'node_key'      => Vocabulary::NODE_2002_CUSTODY_HEARING,
				'workflow_key'  => Vocabulary::WF_CUSTODY,
				'stage'         => Vocabulary::STAGE_HEARING,
				'court_routing' => Vocabulary::ROUTE_FAMILY_COURT,
				'node_type'     => 'hearing',
				'label'         => __( 'Custody Hearing', 'prose-core' ),
				'completion_events' => array( 'HEARING_HELD' ),
			),
			array(
				'node_key'      => Vocabulary::NODE_2003_CUSTODY_ORDER,
				'workflow_key'  => Vocabulary::WF_CUSTODY,
				'stage'         => Vocabulary::STAGE_ORDER,
				'court_routing' => Vocabulary::ROUTE_FAMILY_COURT,
				'node_type'     => 'order',
				'label'         => __( 'Custody Order', 'prose-core' ),
				'is_terminal'   => true,
				'completion_events' => array( 'ORDER_ENTERED' ),
			),
			array(
				'node_key'      => Vocabulary::NODE_3001_SUPPORT_PETITION,
				'workflow_key'  => Vocabulary::WF_CHILD_SUPPORT,
				'stage'         => Vocabulary::STAGE_PETITION,
				'court_routing' => Vocabulary::ROUTE_FAMILY_COURT,
				'node_type'     => 'petition',
				'label'         => __( 'Support Petition Filed', 'prose-core' ),
				'is_entry'      => true,
				'trigger_events' => array( 'PETITION_FILED' ),
			),
			array(
				'node_key'      => Vocabulary::NODE_3002_SUPPORT_ORDER,
				'workflow_key'  => Vocabulary::WF_CHILD_SUPPORT,
				'stage'         => Vocabulary::STAGE_ORDER,
				'court_routing' => Vocabulary::ROUTE_FAMILY_COURT,
				'node_type'     => 'order',
				'label'         => __( 'Support Order', 'prose-core' ),
				'is_terminal'   => true,
				'completion_events' => array( 'ORDER_ENTERED' ),
			),
			array(
				'node_key'      => Vocabulary::NODE_4001_FAMILY_OFFENSE,
				'workflow_key'  => Vocabulary::WF_ORDER_OF_PROTECTION,
				'stage'         => Vocabulary::STAGE_PETITION,
				'court_routing' => Vocabulary::ROUTE_FAMILY_COURT,
				'node_type'     => 'petition',
				'label'         => __( 'Family Offense Petition', 'prose-core' ),
				'is_entry'      => true,
			),
			array(
				'node_key'      => Vocabulary::NODE_4002_TEMP_OP,
				'workflow_key'  => Vocabulary::WF_ORDER_OF_PROTECTION,
				'stage'         => Vocabulary::STAGE_TEMPORARY_ORDER,
				'court_routing' => Vocabulary::ROUTE_FAMILY_COURT,
				'node_type'     => 'order',
				'label'         => __( 'Temporary Order of Protection', 'prose-core' ),
				'completion_events' => array( 'TEMP_ORDER_ISSUED' ),
			),
			array(
				'node_key'      => Vocabulary::NODE_4003_FINAL_OP,
				'workflow_key'  => Vocabulary::WF_ORDER_OF_PROTECTION,
				'stage'         => Vocabulary::STAGE_FINAL_ORDER,
				'court_routing' => Vocabulary::ROUTE_FAMILY_COURT,
				'node_type'     => 'order',
				'label'         => __( 'Final Order of Protection', 'prose-core' ),
				'is_terminal'   => true,
				'completion_events' => array( 'ORDER_ENTERED' ),
			),
			array(
				'node_key'      => 'NODE_5001_ENFORCEMENT_FILED',
				'workflow_key'  => Vocabulary::WF_ENFORCEMENT,
				'stage'         => Vocabulary::STAGE_VIOLATION,
				'court_routing' => Vocabulary::ROUTE_FAMILY_COURT,
				'node_type'     => 'petition',
				'label'         => __( 'Violation Petition Filed', 'prose-core' ),
				'is_entry'      => true,
				'trigger_events' => array( 'VIOLATION_FILED' ),
			),
			array(
				'node_key'      => 'NODE_5002_ENFORCEMENT_ORDER',
				'workflow_key'  => Vocabulary::WF_ENFORCEMENT,
				'stage'         => Vocabulary::STAGE_ENFORCEMENT,
				'court_routing' => Vocabulary::ROUTE_FAMILY_COURT,
				'node_type'     => 'order',
				'label'         => __( 'Enforcement Order', 'prose-core' ),
				'is_terminal'   => true,
				'completion_events' => array( 'ORDER_ENTERED' ),
			),
			array(
				'node_key'      => 'NODE_6001_MODIFICATION_FILED',
				'workflow_key'  => Vocabulary::WF_MODIFICATION,
				'stage'         => Vocabulary::STAGE_MODIFICATION,
				'court_routing' => Vocabulary::ROUTE_FAMILY_COURT,
				'node_type'     => 'petition',
				'label'         => __( 'Modification Petition Filed', 'prose-core' ),
				'is_entry'      => true,
				'trigger_events' => array( 'PETITION_FILED' ),
			),
			array(
				'node_key'      => 'NODE_6002_MODIFICATION_ORDER',
				'workflow_key'  => Vocabulary::WF_MODIFICATION,
				'stage'         => Vocabulary::STAGE_ORDER,
				'court_routing' => Vocabulary::ROUTE_FAMILY_COURT,
				'node_type'     => 'order',
				'label'         => __( 'Modified Order', 'prose-core' ),
				'is_terminal'   => true,
				'completion_events' => array( 'ORDER_ENTERED' ),
			),
		);
	}

	/**
	 * Seed edges from NODE_NEXT map.
	 *
	 * @param array<string, int> $key_to_id Node key => ID map.
	 * @return int Edge count.
	 */
	private function seed_edges( array $key_to_id ): int {
		$node_next = array(
			Vocabulary::NODE_1001_DIVORCE_FILED          => array( Vocabulary::NODE_1002_SERVICE_COMPLETE ),
			Vocabulary::NODE_1002_SERVICE_COMPLETE       => array( Vocabulary::NODE_1003_ANSWER_FILED ),
			Vocabulary::NODE_1003_ANSWER_FILED           => array( Vocabulary::NODE_1005_PRELIMINARY_CONFERENCE ),
			Vocabulary::NODE_1004_OSC_FILED              => array( Vocabulary::NODE_1005_PRELIMINARY_CONFERENCE ),
			Vocabulary::NODE_1005_PRELIMINARY_CONFERENCE => array( Vocabulary::NODE_1006_DISCOVERY ),
			Vocabulary::NODE_1006_DISCOVERY              => array( Vocabulary::NODE_1007_COMPLIANCE_CONFERENCE ),
			Vocabulary::NODE_1007_COMPLIANCE_CONFERENCE  => array( Vocabulary::NODE_1008_SETTLEMENT, Vocabulary::NODE_1009_TRIAL ),
			Vocabulary::NODE_1008_SETTLEMENT             => array( Vocabulary::NODE_1010_JUDGMENT ),
			Vocabulary::NODE_1009_TRIAL                  => array( Vocabulary::NODE_1010_JUDGMENT ),
			Vocabulary::NODE_2001_CUSTODY_PETITION       => array( Vocabulary::NODE_2002_CUSTODY_HEARING ),
			Vocabulary::NODE_2002_CUSTODY_HEARING        => array( Vocabulary::NODE_2003_CUSTODY_ORDER ),
			Vocabulary::NODE_3001_SUPPORT_PETITION       => array( Vocabulary::NODE_3002_SUPPORT_ORDER ),
			Vocabulary::NODE_4001_FAMILY_OFFENSE         => array( Vocabulary::NODE_4002_TEMP_OP ),
			Vocabulary::NODE_4002_TEMP_OP                => array( Vocabulary::NODE_4003_FINAL_OP ),
			'NODE_5001_ENFORCEMENT_FILED'                 => array( 'NODE_5002_ENFORCEMENT_ORDER' ),
			'NODE_6001_MODIFICATION_FILED'                => array( 'NODE_6002_MODIFICATION_ORDER' ),
		);

		$count = 0;
		$seq   = 0;

		foreach ( $node_next as $from_key => $to_keys ) {
			if ( ! isset( $key_to_id[ $from_key ] ) ) {
				continue;
			}

			$from_row = $this->nodes->get_by_key( $from_key );
			$wf_key   = $from_row ? (string) $from_row->workflow_key : '';

			foreach ( (array) $to_keys as $to_key ) {
				if ( ! isset( $key_to_id[ $to_key ] ) ) {
					continue;
				}

				++$seq;
				$id = $this->edges->upsert(
					array(
						'from_node_id' => $key_to_id[ $from_key ],
						'to_node_id'   => $key_to_id[ $to_key ],
						'workflow_key' => $wf_key,
						'edge_type'    => 'next',
						'sequence'     => $seq,
					)
				);

				if ( $id > 0 ) {
					++$count;
				}
			}
		}

		return $count;
	}
}
