<?php
/**
 * Taxonomy: prose_case_type (hierarchical, attached to prose_form).
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Forms;

use ProSe\Core\Loader;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Form_Taxonomy
 */
class Form_Taxonomy {

	/**
	 * Taxonomy slug.
	 */
	public const TAXONOMY = 'prose_case_type';

	/**
	 * Register hooks.
	 *
	 * @param Loader $loader Hook loader.
	 * @return void
	 */
	public function register( Loader $loader ): void {
		$loader->add_action( 'init', $this, 'register_taxonomy' );
	}

	/**
	 * Register the prose_case_type taxonomy.
	 *
	 * @return void
	 */
	public function register_taxonomy(): void {
		$labels = array(
			'name'              => _x( 'Case Types', 'taxonomy general name', 'prose-core' ),
			'singular_name'     => _x( 'Case Type', 'taxonomy singular name', 'prose-core' ),
			'search_items'      => __( 'Search Case Types', 'prose-core' ),
			'all_items'         => __( 'All Case Types', 'prose-core' ),
			'parent_item'       => __( 'Parent Case Type', 'prose-core' ),
			'parent_item_colon' => __( 'Parent Case Type:', 'prose-core' ),
			'edit_item'         => __( 'Edit Case Type', 'prose-core' ),
			'update_item'       => __( 'Update Case Type', 'prose-core' ),
			'add_new_item'      => __( 'Add New Case Type', 'prose-core' ),
			'new_item_name'     => __( 'New Case Type Name', 'prose-core' ),
			'menu_name'         => __( 'Case Types', 'prose-core' ),
		);

		register_taxonomy(
			self::TAXONOMY,
			array( Form_CPT::POST_TYPE ),
			array(
				'labels'            => $labels,
				'hierarchical'      => true,
				'public'            => false,
				'show_ui'           => true,
				'show_admin_column' => true,
				'show_in_rest'      => true,
				'rest_base'         => 'prose-case-types',
				'rewrite'           => false,
			)
		);
	}

	/**
	 * Ensure taxonomy terms exist and return their term IDs.
	 *
	 * @param string[] $case_types Case type names.
	 * @return int[]
	 */
	public function ensure_terms( array $case_types ): array {
		$term_ids = array();

		foreach ( $case_types as $case_type ) {
			$case_type = trim( $case_type );

			if ( '' === $case_type ) {
				continue;
			}

			$term = term_exists( $case_type, self::TAXONOMY );

			if ( ! $term ) {
				$term = wp_insert_term( $case_type, self::TAXONOMY );
			}

			if ( is_wp_error( $term ) ) {
				continue;
			}

			$term_ids[] = (int) ( is_array( $term ) ? $term['term_id'] : $term );
		}

		return array_unique( $term_ids );
	}
}
