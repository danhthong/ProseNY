<?php
/**
 * Seed NYC production package catalog into CPT + relation tables.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Forms\Database\Seeders;

use ProSe\Core\Forms\Database\Repositories\Node_Package_Repository;
use ProSe\Core\Forms\Database\Repositories\Node_Repository;
use ProSe\Core\Forms\Database\Repositories\Package_Form_Repository;
use ProSe\Core\Forms\Database\Repositories\Package_Relation_Repository;
use ProSe\Core\Forms\Form_Repository;
use ProSe\Core\Forms\Package_Meta;
use ProSe\Core\Forms\Package_Repository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Package_Catalog_Seeder
 */
final class Package_Catalog_Seeder {

	/**
	 * Package repository.
	 *
	 * @var Package_Repository
	 */
	private Package_Repository $packages;

	/**
	 * Form repository.
	 *
	 * @var Form_Repository
	 */
	private Form_Repository $forms;

	/**
	 * Node repository.
	 *
	 * @var Node_Repository
	 */
	private Node_Repository $nodes;

	/**
	 * Node-package repository.
	 *
	 * @var Node_Package_Repository
	 */
	private Node_Package_Repository $node_packages;

	/**
	 * Package-form repository.
	 *
	 * @var Package_Form_Repository
	 */
	private Package_Form_Repository $package_forms;

	/**
	 * Package relation repository.
	 *
	 * @var Package_Relation_Repository
	 */
	private Package_Relation_Repository $relations;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->packages       = new Package_Repository();
		$this->forms          = new Form_Repository();
		$this->nodes          = new Node_Repository();
		$this->node_packages  = new Node_Package_Repository();
		$this->package_forms  = new Package_Form_Repository();
		$this->relations      = new Package_Relation_Repository();
	}

	/**
	 * Seed full package catalog.
	 *
	 * @return array{packages: int, relations: int, forms: int, node_links: int}
	 */
	public function seed(): array {
		$catalog  = Package_Catalog_Data::packages();
		$node_map = Package_Catalog_Data::node_map();
		$key_ids  = array();

		$pkg_count = 0;

		foreach ( $catalog as $def ) {
			$post_id = $this->upsert_package_cpt( $def );

			if ( $post_id <= 0 ) {
				continue;
			}

			$key_ids[ $def['package_key'] ] = $post_id;
			++$pkg_count;
		}

		$form_count  = $this->seed_forms( $catalog, $key_ids );
		$rel_count   = $this->seed_relations( $catalog, $key_ids );
		$node_count  = $this->seed_node_links( $node_map, $key_ids );

		return array(
			'packages'   => $pkg_count,
			'relations'  => $rel_count,
			'forms'      => $form_count,
			'node_links' => $node_count,
		);
	}

	/**
	 * Upsert prose_package CPT with versioning defaults.
	 *
	 * @param array<string, mixed> $def Package definition.
	 * @return int Post ID.
	 */
	private function upsert_package_cpt( array $def ): int {
		$key = (string) ( $def['package_key'] ?? '' );

		$result = $this->packages->create_or_update(
			$key,
			array(
				'package_name'          => (string) ( $def['package_name'] ?? $key ),
				'court'                 => (string) ( $def['court_routing'] ?? '' ),
				'workflow_id'           => (string) ( $def['workflow'] ?? '' ),
				'workflow_stage'        => (string) ( $def['workflow_stage'] ?? '' ),
				'required_forms'        => $def['required_forms'] ?? array(),
				'optional_forms'        => $def['optional_forms'] ?? array(),
				'trigger_conditions'    => $def['trigger_conditions'] ?? array(),
				'completion_conditions' => $def['completion_conditions'] ?? array(),
				'next_package_ids'      => $def['next_packages'] ?? array(),
				'service_required'      => $def['service_required'] ?? true,
				'filing_required'       => $def['filing_required'] ?? true,
				'summary'               => (string) ( $def['package_name'] ?? '' ),
				'package_version'       => 1,
				'effective_from'        => gmdate( 'Y-m-d' ),
				'effective_to'          => '',
				'is_active'             => true,
				'package_order'         => (int) ( $def['package_order'] ?? 0 ),
			)
		);

		if ( is_wp_error( $result ) ) {
			return 0;
		}

		return (int) $result['post_id'];
	}

	/**
	 * Seed package-form links.
	 *
	 * @param array<int, array<string, mixed>> $catalog Package catalog.
	 * @param array<string, int>               $key_ids Package key => post ID.
	 * @return int Link count.
	 */
	private function seed_forms( array $catalog, array $key_ids ): int {
		$count = 0;

		foreach ( $catalog as $def ) {
			$key = (string) ( $def['package_key'] ?? '' );

			if ( ! isset( $key_ids[ $key ] ) ) {
				continue;
			}

			$package_id = $key_ids[ $key ];
			$seq        = 0;

			foreach ( (array) ( $def['required_forms'] ?? array() ) as $form_code ) {
				++$seq;
				$form_post = $this->forms->get_by_form_code( (string) $form_code );

				$id = $this->package_forms->upsert(
					array(
						'package_id'  => $package_id,
						'form_code'   => (string) $form_code,
						'form_id'     => $form_post ? $form_post->ID : null,
						'requirement' => 'required',
						'sequence'    => $seq,
					)
				);

				if ( $id > 0 ) {
					++$count;
				}
			}

			foreach ( (array) ( $def['optional_forms'] ?? array() ) as $form_code ) {
				++$seq;
				$form_post = $this->forms->get_by_form_code( (string) $form_code );

				$id = $this->package_forms->upsert(
					array(
						'package_id'  => $package_id,
						'form_code'   => (string) $form_code,
						'form_id'     => $form_post ? $form_post->ID : null,
						'requirement' => 'optional',
						'sequence'    => $seq,
					)
				);

				if ( $id > 0 ) {
					++$count;
				}
			}
		}

		return $count;
	}

	/**
	 * Seed package relations.
	 *
	 * @param array<int, array<string, mixed>> $catalog Catalog.
	 * @param array<string, int>               $key_ids Key map.
	 * @return int Relation count.
	 */
	private function seed_relations( array $catalog, array $key_ids ): int {
		$count = 0;
		$seq   = 0;

		foreach ( $catalog as $def ) {
			$from_key = (string) ( $def['package_key'] ?? '' );

			foreach ( (array) ( $def['next_packages'] ?? array() ) as $to_key ) {
				++$seq;
				$id = $this->relations->upsert(
					array(
						'from_package_key' => $from_key,
						'to_package_key'   => (string) $to_key,
						'from_package_id'  => $key_ids[ $from_key ] ?? null,
						'to_package_id'    => $key_ids[ $to_key ] ?? null,
						'relation_type'    => 'next',
						'sequence'         => $seq,
					)
				);

				if ( $id > 0 ) {
					++$count;
				}
			}
		}

		return $count;
	}

	/**
	 * Seed node-package links.
	 *
	 * @param array<string, string> $node_map Node map.
	 * @param array<string, int>    $key_ids  Package IDs.
	 * @return int Link count.
	 */
	private function seed_node_links( array $node_map, array $key_ids ): int {
		$count = 0;

		foreach ( $node_map as $package_key => $node_key ) {
			$node_id = $this->nodes->get_id_by_key( $node_key );

			if ( $node_id <= 0 ) {
				continue;
			}

			$id = $this->node_packages->upsert(
				array(
					'node_id'     => $node_id,
					'package_key' => $package_key,
					'package_id'  => $key_ids[ $package_key ] ?? null,
					'role'        => 'satisfies',
				)
			);

			if ( $id > 0 ) {
				++$count;
			}
		}

		return $count;
	}
}
