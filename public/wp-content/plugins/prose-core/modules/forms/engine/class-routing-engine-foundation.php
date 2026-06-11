<?php
/**
 * Routing Engine foundation — indexes imported catalog data for runtime routing.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Forms\Engine;

use ProSe\Core\Forms\Database\Repositories\Node_Repository;
use ProSe\Core\Forms\Database\Repositories\Package_Form_Repository;
use ProSe\Core\Forms\Database\Repositories\Workflow_Repository;
use ProSe\Core\Forms\Package_Repository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Routing_Engine_Foundation
 */
final class Routing_Engine_Foundation {

	public const INDEX_OPTION = 'prose_routing_engine_index';

	/**
	 * Build and persist routing index from imported data.
	 *
	 * @return array<string, mixed>
	 */
	public function prepare(): array {
		$workflows = new Workflow_Repository();
		$nodes     = new Node_Repository();
		$packages  = new Package_Repository();
		$pkg_forms = new Package_Form_Repository();

		$index = array(
			'generated_at' => gmdate( 'c' ),
			'workflows'    => array(),
			'nodes'        => array(),
			'packages'     => array(),
			'form_map'     => array(),
		);

		foreach ( $workflows->list_active() as $wf ) {
			$index['workflows'][ (string) $wf->workflow_key ] = array(
				'workflow_id'   => (int) $wf->workflow_id,
				'court_routing' => (string) $wf->court_routing,
				'nodes'         => array(),
			);
		}

		foreach ( $workflows->all_keys() as $wf_key ) {
			foreach ( $nodes->list_by_workflow( $wf_key ) as $node ) {
				$index['nodes'][ (string) $node->node_key ] = array(
					'node_id'      => (int) $node->node_id,
					'workflow_key' => (string) $node->workflow_key,
					'stage'        => (string) $node->stage,
					'node_type'    => (string) $node->node_type,
				);

				if ( isset( $index['workflows'][ $wf_key ] ) ) {
					$index['workflows'][ $wf_key ]['nodes'][] = (string) $node->node_key;
				}
			}
		}

		$query = new \WP_Query(
			array(
				'post_type'      => 'prose_package',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'   => 'prose_package_is_active',
						'value' => '1',
					),
				),
			)
		);

		foreach ( $query->posts as $post ) {
			$key = (string) get_post_meta( $post->ID, 'prose_package_id', true );
			if ( '' === $key ) {
				continue;
			}

			$index['packages'][ $key ] = array(
				'post_id'       => (int) $post->ID,
				'workflow_id'   => (string) get_post_meta( $post->ID, 'prose_package_workflow_id', true ),
				'workflow_stage' => (string) get_post_meta( $post->ID, 'prose_package_workflow_stage', true ),
				'court'         => (string) get_post_meta( $post->ID, 'prose_package_court', true ),
			);

			$forms_for_pkg = array();
			foreach ( $pkg_forms->get_by_package( (int) $post->ID ) as $link ) {
				$forms_for_pkg[] = array(
					'form_code'   => (string) $link->form_code,
					'requirement' => (string) $link->requirement,
					'form_id'     => $link->form_id ? (int) $link->form_id : null,
				);
			}
			$index['form_map'][ $key ] = $forms_for_pkg;
		}

		update_option( self::INDEX_OPTION, $index, false );

		return array(
			'workflows' => count( $index['workflows'] ),
			'nodes'     => count( $index['nodes'] ),
			'packages'  => count( $index['packages'] ),
			'form_links' => array_sum( array_map( 'count', $index['form_map'] ) ),
		);
	}

	/**
	 * Get the persisted routing index.
	 *
	 * @return array<string, mixed>
	 */
	public function get_index(): array {
		$data = get_option( self::INDEX_OPTION, array() );

		return is_array( $data ) ? $data : array();
	}
}
