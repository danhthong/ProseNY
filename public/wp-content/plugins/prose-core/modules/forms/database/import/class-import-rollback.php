<?php
/**
 * import_run_id based rollback (V1).
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Forms\Database\Import;

use ProSe\Core\Forms\Database\Database_Installer;
use ProSe\Core\Forms\Database\Repositories\Edge_Repository;
use ProSe\Core\Forms\Database\Repositories\Node_Package_Repository;
use ProSe\Core\Forms\Database\Repositories\Node_Repository;
use ProSe\Core\Forms\Database\Repositories\Package_Form_Repository;
use ProSe\Core\Forms\Database\Repositories\Package_Relation_Repository;
use ProSe\Core\Forms\Database\Repositories\Workflow_Repository;
use ProSe\Core\Forms\Package_Repository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Import_Rollback
 */
final class Import_Rollback {

	/**
	 * Roll back all records affected by an import run.
	 *
	 * Order: form mapping, alias, package relations, node packages, packages, nodes, workflows.
	 *
	 * @param string $import_run_id Import run ID.
	 * @return array<string, int> Counts per domain.
	 */
	public function rollback( string $import_run_id ): array {
		$manifest = Import_Run_Context::load_manifest( $import_run_id );

		if ( null === $manifest ) {
			return array( 'error' => 0 );
		}

		$domains = (array) ( $manifest['domains'] ?? array() );
		$counts  = array(
			'package_forms'     => 0,
			'alias'             => 0,
			'package_relations' => 0,
			'node_packages'     => 0,
			'packages'          => 0,
			'nodes'             => 0,
			'edges'             => 0,
			'workflows'         => 0,
		);

		global $wpdb;

		// 1. Form Mapping.
		$counts['package_forms'] = $this->rollback_domain_rows(
			'package_forms',
			$domains,
			Database_Installer::table( 'prose_package_forms' ),
			'id'
		);

		// 2. Alias Registry.
		$alias_before = $manifest['alias_snapshot'] ?? null;
		if ( is_array( $alias_before ) ) {
			$registry = new Alias_Registry();
			$registry->restore_option( $alias_before );
			++$counts['alias'];
		}

		// 3. Package Relations.
		$counts['package_relations'] = $this->rollback_domain_rows(
			'package_relations',
			$domains,
			Database_Installer::table( 'prose_package_relations' ),
			'id'
		);

		// 4. Node Packages.
		$counts['node_packages'] = $this->rollback_domain_rows(
			'node_packages',
			$domains,
			Database_Installer::table( 'prose_node_packages' ),
			'id'
		);

		// 5. Packages (CPT).
		$counts['packages'] = $this->rollback_packages( $domains );

		// 6. Nodes + edges.
		$counts['edges'] = $this->rollback_domain_rows(
			'edges',
			$domains,
			Database_Installer::table( 'prose_workflow_edges' ),
			'edge_id'
		);
		$counts['nodes'] = $this->rollback_domain_rows(
			'nodes',
			$domains,
			Database_Installer::table( 'prose_workflow_nodes' ),
			'node_id'
		);

		// 7. Workflows.
		$counts['workflows'] = $this->rollback_domain_rows(
			'workflows',
			$domains,
			Database_Installer::table( 'prose_workflows' ),
			'workflow_id'
		);

		delete_option( Import_Run_Context::MANIFEST_PREFIX . $import_run_id );

		return $counts;
	}

	/**
	 * Roll back rows for a manifest domain.
	 *
	 * @param string               $domain   Domain key in manifest.
	 * @param array<string, mixed> $domains  All domains.
	 * @param string               $table    Table name.
	 * @param string               $id_col   Primary key column.
	 * @return int Number of rows affected.
	 */
	private function rollback_domain_rows( string $domain, array $domains, string $table, string $id_col ): int {
		global $wpdb;

		if ( ! isset( $domains[ $domain ] ) || ! is_array( $domains[ $domain ] ) ) {
			return 0;
		}

		$count = 0;

		foreach ( $domains[ $domain ] as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}

			$action = (string) ( $entry['action'] ?? '' );
			$before = (array) ( $entry['before'] ?? array() );
			$after  = (array) ( $entry['after'] ?? array() );

			if ( 'create' === $action && ! empty( $after[ $id_col ] ) ) {
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$wpdb->delete( $table, array( $id_col => (int) $after[ $id_col ] ) );
				++$count;
				continue;
			}

			if ( in_array( $action, array( 'update', 'archive' ), true ) && ! empty( $before[ $id_col ] ) ) {
				$row = $before;
				unset( $row[ $id_col ] );
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$wpdb->update( $table, $row, array( $id_col => (int) $before[ $id_col ] ) );
				++$count;
			}
		}

		return $count;
	}

	/**
	 * Roll back prose_package CPT entries.
	 *
	 * @param array<string, mixed> $domains Manifest domains.
	 * @return int
	 */
	private function rollback_packages( array $domains ): int {
		if ( ! isset( $domains['packages'] ) || ! is_array( $domains['packages'] ) ) {
			return 0;
		}

		$count = 0;

		foreach ( $domains['packages'] as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}

			$action = (string) ( $entry['action'] ?? '' );
			$before = (array) ( $entry['before'] ?? array() );
			$after  = (array) ( $entry['after'] ?? array() );

			if ( 'create' === $action && ! empty( $after['post_id'] ) ) {
				wp_delete_post( (int) $after['post_id'], true );
				++$count;
			}
		}

		return $count;
	}
}
