<?php
/**
 * Seed wp_prose_workflows catalog.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Forms\Database\Seeders;

use ProSe\Core\Forms\Classification\Vocabulary;
use ProSe\Core\Forms\Database\Import\Import_Run_Context;
use ProSe\Core\Forms\Database\Repositories\Workflow_Repository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Workflow_Catalog_Seeder
 */
final class Workflow_Catalog_Seeder {

	/**
	 * Workflow repository.
	 *
	 * @var Workflow_Repository
	 */
	private Workflow_Repository $workflows;

	/**
	 * Constructor.
	 *
	 * @param Workflow_Repository|null $workflows Repository.
	 */
	public function __construct( ?Workflow_Repository $workflows = null ) {
		$this->workflows = $workflows ?? new Workflow_Repository();
	}

	/**
	 * Seed all workflows.
	 *
	 * @return int Number seeded.
	 */
	public function seed(): int {
		$catalog = array(
			array(
				'workflow_key'  => Vocabulary::WF_UNCONTESTED_DIVORCE,
				'workflow_name' => __( 'Uncontested Divorce', 'prose-core' ),
				'court_routing' => Vocabulary::ROUTE_SUPREME_COURT,
				'sort_order'    => 10,
			),
			array(
				'workflow_key'  => Vocabulary::WF_CONTESTED_DIVORCE,
				'workflow_name' => __( 'Contested Divorce', 'prose-core' ),
				'court_routing' => Vocabulary::ROUTE_SUPREME_COURT,
				'sort_order'    => 20,
			),
			array(
				'workflow_key'  => Vocabulary::WF_DEFAULT_DIVORCE,
				'workflow_name' => __( 'Default Divorce', 'prose-core' ),
				'court_routing' => Vocabulary::ROUTE_SUPREME_COURT,
				'sort_order'    => 30,
			),
			array(
				'workflow_key'  => Vocabulary::WF_DISCOVERY,
				'workflow_name' => __( 'Discovery', 'prose-core' ),
				'court_routing' => Vocabulary::ROUTE_SUPREME_COURT,
				'sort_order'    => 100,
			),
			array(
				'workflow_key'  => Vocabulary::WF_MOTION_PRACTICE,
				'workflow_name' => __( 'Motion Practice', 'prose-core' ),
				'court_routing' => Vocabulary::ROUTE_SUPREME_COURT,
				'sort_order'    => 110,
			),
			array(
				'workflow_key'  => Vocabulary::WF_EMERGENCY_RELIEF,
				'workflow_name' => __( 'Emergency Relief', 'prose-core' ),
				'court_routing' => Vocabulary::ROUTE_SUPREME_AND_FAMILY_OVERLAP,
				'sort_order'    => 120,
			),
			array(
				'workflow_key'  => Vocabulary::WF_CUSTODY,
				'workflow_name' => __( 'Custody', 'prose-core' ),
				'court_routing' => Vocabulary::ROUTE_FAMILY_COURT,
				'sort_order'    => 40,
			),
			array(
				'workflow_key'  => Vocabulary::WF_VISITATION,
				'workflow_name' => __( 'Visitation', 'prose-core' ),
				'court_routing' => Vocabulary::ROUTE_FAMILY_COURT,
				'sort_order'    => 50,
			),
			array(
				'workflow_key'  => Vocabulary::WF_CHILD_SUPPORT,
				'workflow_name' => __( 'Child Support', 'prose-core' ),
				'court_routing' => Vocabulary::ROUTE_FAMILY_COURT,
				'sort_order'    => 60,
			),
			array(
				'workflow_key'  => Vocabulary::WF_ORDER_OF_PROTECTION,
				'workflow_name' => __( 'Order of Protection', 'prose-core' ),
				'court_routing' => Vocabulary::ROUTE_FAMILY_COURT,
				'sort_order'    => 70,
			),
			array(
				'workflow_key'  => Vocabulary::WF_ENFORCEMENT,
				'workflow_name' => __( 'Enforcement', 'prose-core' ),
				'court_routing' => Vocabulary::ROUTE_FAMILY_COURT,
				'sort_order'    => 80,
			),
			array(
				'workflow_key'  => Vocabulary::WF_MODIFICATION,
				'workflow_name' => __( 'Modification', 'prose-core' ),
				'court_routing' => Vocabulary::ROUTE_FAMILY_COURT,
				'sort_order'    => 90,
			),
		);

		$count = 0;

		foreach ( $catalog as $row ) {
			$row['description'] = sprintf(
				/* translators: %s: workflow name */
				__( 'NYC %s workflow.', 'prose-core' ),
				$row['workflow_name']
			);
			$row['is_active'] = true;

			if ( $this->workflows->upsert( $row ) > 0 ) {
				++$count;
			}
		}

		return $count;
	}

	/**
	 * Seed workflows from workflow-seeder.json artifact.
	 *
	 * @param array<string, mixed> $artifact Decoded artifact.
	 * @param Import_Run_Context   $context  Import context.
	 * @return array{created: int, updated: int, unchanged: int, archived: int}
	 */
	public function seed_from_artifact( array $artifact, Import_Run_Context $context ): array {
		$stats = array(
			'created'   => 0,
			'updated'   => 0,
			'unchanged' => 0,
			'archived'  => 0,
		);

		$keys = array();

		foreach ( (array) ( $artifact['workflows'] ?? array() ) as $row ) {
			$key = (string) ( $row['workflow_key'] ?? '' );
			if ( '' === $key ) {
				continue;
			}

			$keys[] = $key;
			$result = $this->workflows->upsert_with_context( $row, $context );

			if ( isset( $stats[ $result['action'] ] ) ) {
				++$stats[ $result['action'] ];
			}
		}

		$stats['archived'] = $this->workflows->archive_missing( $keys, $context );

		return $stats;
	}
}
