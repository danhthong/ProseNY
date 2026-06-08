<?php
/**
 * Form taxonomies: case type, court, workflow stage.
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
	 * Taxonomy slug: case type.
	 */
	public const TAXONOMY_CASE_TYPE = 'prose_case_type';

	/**
	 * Taxonomy slug: court.
	 */
	public const TAXONOMY_COURT = 'prose_court';

	/**
	 * Taxonomy slug: workflow stage.
	 */
	public const TAXONOMY_WORKFLOW_STAGE = 'prose_workflow_stage';

	/**
	 * Legacy alias for case type taxonomy.
	 */
	public const TAXONOMY = self::TAXONOMY_CASE_TYPE;

	/**
	 * Register hooks.
	 *
	 * @param Loader $loader Hook loader.
	 * @return void
	 */
	public function register( Loader $loader ): void {
		$loader->add_action( 'init', $this, 'register_taxonomies' );
	}

	/**
	 * Register all form taxonomies.
	 *
	 * @return void
	 */
	public function register_taxonomies(): void {
		$this->register_case_type_taxonomy();
		$this->register_court_taxonomy();
		$this->register_workflow_stage_taxonomy();
	}

	/**
	 * Register the prose_case_type taxonomy.
	 *
	 * @return void
	 */
	public function register_case_type_taxonomy(): void {
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
			self::TAXONOMY_CASE_TYPE,
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
	 * Register the prose_court taxonomy.
	 *
	 * @return void
	 */
	public function register_court_taxonomy(): void {
		$labels = array(
			'name'              => _x( 'Courts', 'taxonomy general name', 'prose-core' ),
			'singular_name'     => _x( 'Court', 'taxonomy singular name', 'prose-core' ),
			'search_items'      => __( 'Search Courts', 'prose-core' ),
			'all_items'         => __( 'All Courts', 'prose-core' ),
			'parent_item'       => __( 'Parent Court', 'prose-core' ),
			'parent_item_colon' => __( 'Parent Court:', 'prose-core' ),
			'edit_item'         => __( 'Edit Court', 'prose-core' ),
			'update_item'       => __( 'Update Court', 'prose-core' ),
			'add_new_item'      => __( 'Add New Court', 'prose-core' ),
			'new_item_name'     => __( 'New Court Name', 'prose-core' ),
			'menu_name'         => __( 'Courts', 'prose-core' ),
		);

		register_taxonomy(
			self::TAXONOMY_COURT,
			array( Form_CPT::POST_TYPE ),
			array(
				'labels'            => $labels,
				'hierarchical'      => true,
				'public'            => false,
				'show_ui'           => true,
				'show_admin_column' => true,
				'show_in_rest'      => true,
				'rest_base'         => 'prose-courts',
				'rewrite'           => false,
			)
		);
	}

	/**
	 * Register the prose_workflow_stage taxonomy.
	 *
	 * @return void
	 */
	public function register_workflow_stage_taxonomy(): void {
		$labels = array(
			'name'              => _x( 'Workflow Stages', 'taxonomy general name', 'prose-core' ),
			'singular_name'     => _x( 'Workflow Stage', 'taxonomy singular name', 'prose-core' ),
			'search_items'      => __( 'Search Workflow Stages', 'prose-core' ),
			'all_items'         => __( 'All Workflow Stages', 'prose-core' ),
			'parent_item'       => __( 'Parent Workflow Stage', 'prose-core' ),
			'parent_item_colon' => __( 'Parent Workflow Stage:', 'prose-core' ),
			'edit_item'         => __( 'Edit Workflow Stage', 'prose-core' ),
			'update_item'       => __( 'Update Workflow Stage', 'prose-core' ),
			'add_new_item'      => __( 'Add New Workflow Stage', 'prose-core' ),
			'new_item_name'     => __( 'New Workflow Stage Name', 'prose-core' ),
			'menu_name'         => __( 'Workflow Stages', 'prose-core' ),
		);

		register_taxonomy(
			self::TAXONOMY_WORKFLOW_STAGE,
			array( Form_CPT::POST_TYPE ),
			array(
				'labels'            => $labels,
				'hierarchical'      => true,
				'public'            => false,
				'show_ui'           => true,
				'show_admin_column' => true,
				'show_in_rest'      => true,
				'rest_base'         => 'prose-workflow-stages',
				'rewrite'           => false,
			)
		);
	}

	/**
	 * Legacy method for activation hook.
	 *
	 * @return void
	 */
	public function register_taxonomy(): void {
		$this->register_taxonomies();
	}

	/**
	 * Seed default court and workflow stage terms.
	 *
	 * @return void
	 */
	public function seed_terms(): void {
		$courts = array(
			__( 'Supreme Court', 'prose-core' ),
			__( 'Family Court', 'prose-core' ),
		);

		foreach ( $courts as $court ) {
			$this->ensure_term( $court, self::TAXONOMY_COURT );
		}

		$workflow_stages = array(
			'Divorce' => array(
				__( 'Commencement', 'prose-core' ),
				__( 'Service', 'prose-core' ),
				__( 'Response', 'prose-core' ),
				__( 'Settlement', 'prose-core' ),
				__( 'Judgment', 'prose-core' ),
				__( 'Post-Judgment', 'prose-core' ),
			),
			'Family Court' => array(
				__( 'Petition', 'prose-core' ),
				__( 'Hearing', 'prose-core' ),
				__( 'Order', 'prose-core' ),
				__( 'Enforcement', 'prose-core' ),
				__( 'Modification', 'prose-core' ),
			),
		);

		foreach ( $workflow_stages as $parent => $children ) {
			$parent_id = $this->ensure_term( $parent, self::TAXONOMY_WORKFLOW_STAGE );

			if ( ! $parent_id ) {
				continue;
			}

			foreach ( $children as $child ) {
				$this->ensure_term( $child, self::TAXONOMY_WORKFLOW_STAGE, $parent_id );
			}
		}
	}

	/**
	 * Ensure taxonomy terms exist and return their term IDs.
	 *
	 * @param string[] $terms    Term names.
	 * @param string   $taxonomy Taxonomy slug.
	 * @return int[]
	 */
	public function ensure_terms( array $terms, string $taxonomy = self::TAXONOMY_CASE_TYPE ): array {
		$term_ids = array();

		foreach ( $terms as $term_name ) {
			$term_id = $this->ensure_term( $term_name, $taxonomy );

			if ( $term_id ) {
				$term_ids[] = $term_id;
			}
		}

		return array_values( array_unique( $term_ids ) );
	}

	/**
	 * Ensure a single term exists.
	 *
	 * @param string   $name     Term name.
	 * @param string   $taxonomy Taxonomy slug.
	 * @param int|null $parent   Parent term ID.
	 * @return int|null
	 */
	private function ensure_term( string $name, string $taxonomy, ?int $parent = null ): ?int {
		$name = trim( $name );

		if ( '' === $name ) {
			return null;
		}

		$args   = array();
		$parent = null !== $parent ? (int) $parent : 0;

		if ( $parent > 0 ) {
			$args['parent'] = $parent;
		}

		$existing = term_exists( $name, $taxonomy, $parent );

		if ( $existing ) {
			return (int) ( is_array( $existing ) ? $existing['term_id'] : $existing );
		}

		$insert_args = array();

		if ( $parent > 0 ) {
			$insert_args['parent'] = $parent;
		}

		$term = wp_insert_term( $name, $taxonomy, $insert_args );

		if ( is_wp_error( $term ) ) {
			return null;
		}

		return (int) $term['term_id'];
	}
}
