<?php
/**
 * Backfill form/package meta from graph tables.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Forms\Database;

use ProSe\Core\Forms\Database\Repositories\Node_Repository;
use ProSe\Core\Forms\Database\Repositories\Package_Form_Repository;
use ProSe\Core\Forms\Form_CPT;
use ProSe\Core\Forms\Form_Meta;
use ProSe\Core\Forms\Form_Repository;
use ProSe\Core\Forms\Package_CPT;
use ProSe\Core\Forms\Package_Meta;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Graph_Backfill
 */
final class Graph_Backfill {

	/**
	 * Backfill forms with node keys from classification meta.
	 *
	 * @return int Forms updated.
	 */
	public function backfill_forms(): int {
		$forms  = get_posts(
			array(
				'post_type'      => Form_CPT::POST_TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'fields'         => 'ids',
			)
		);

		$nodes  = new Node_Repository();
		$count  = 0;

		foreach ( $forms as $post_id ) {
			$post_id = (int) $post_id;
			$raw     = get_post_meta( $post_id, Form_Meta::META_WORKFLOW_NODES, true );
			$decoded = is_string( $raw ) ? json_decode( $raw, true ) : $raw;

			if ( ! is_array( $decoded ) || empty( $decoded ) ) {
				continue;
			}

			$node_ids = array();

			foreach ( $decoded as $node_key ) {
				$id = $nodes->get_id_by_key( (string) $node_key );

				if ( $id > 0 ) {
					$node_ids[] = $id;
				}
			}

			if ( ! empty( $node_ids ) ) {
				update_post_meta( $post_id, Form_Meta::META_WORKFLOW_NODES, wp_json_encode( $decoded ) );
				update_post_meta( $post_id, Form_Meta::META_WORKFLOW_NODE_IDS, wp_json_encode( $node_ids ) );
				++$count;
			}
		}

		return $count;
	}

	/**
	 * Resolve form_id references in package_forms junction table.
	 *
	 * @return int Rows updated.
	 */
	public function backfill_package_forms(): int {
		global $wpdb;

		$table    = Database_Installer::table( 'prose_package_forms' );
		$forms    = new Form_Repository();
		$repo     = new Package_Form_Repository();
		$count    = 0;

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( "SELECT id, form_code, package_id FROM {$table} WHERE form_id IS NULL OR form_id = 0" );

		if ( ! is_array( $rows ) ) {
			return 0;
		}

		foreach ( $rows as $row ) {
			$form_post = $forms->get_by_form_code( (string) $row->form_code );

			if ( ! $form_post ) {
				continue;
			}

			$id = $repo->upsert(
				array(
					'package_id'  => (int) $row->package_id,
					'form_code'   => (string) $row->form_code,
					'form_id'     => $form_post->ID,
					'requirement' => 'required',
				)
			);

			if ( $id > 0 ) {
				++$count;
			}
		}

		return $count;
	}

	/**
	 * Backfill existing packages with version v1 defaults.
	 *
	 * @return int Packages updated.
	 */
	public function backfill_package_versions(): int {
		$posts = get_posts(
			array(
				'post_type'      => Package_CPT::POST_TYPE,
				'post_status'    => 'any',
				'posts_per_page' => -1,
			)
		);

		$count = 0;

		foreach ( $posts as $post ) {
			if ( ! $post instanceof \WP_Post ) {
				continue;
			}

			$version = get_post_meta( $post->ID, Package_Meta::META_PACKAGE_VERSION, true );

			if ( '' !== (string) $version ) {
				continue;
			}

			update_post_meta( $post->ID, Package_Meta::META_PACKAGE_VERSION, 1 );
			update_post_meta( $post->ID, Package_Meta::META_PACKAGE_IS_ACTIVE, true );
			update_post_meta( $post->ID, Package_Meta::META_PACKAGE_EFFECTIVE_FROM, gmdate( 'Y-m-d', strtotime( $post->post_date ) ) );
			update_post_meta( $post->ID, Package_Meta::META_PACKAGE_EFFECTIVE_TO, '' );
			++$count;
		}

		return $count;
	}
}
