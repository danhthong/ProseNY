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
				'show_ui'           => false,
				'show_admin_column' => false,
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
				'show_ui'           => false,
				'show_admin_column' => false,
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

		$case_types = array(
			'Divorce' => array(
				__( 'Uncontested Divorce', 'prose-core' ),
				__( 'Contested Divorce', 'prose-core' ),
				__( 'Divorce With Children', 'prose-core' ),
				__( 'Divorce Without Children', 'prose-core' ),
				__( 'Post Divorce', 'prose-core' ),
				__( 'Orders of Protection', 'prose-core' ),
			),
			'Family Court' => array(
				__( 'Child Support', 'prose-core' ),
				__( 'Child Support Modification', 'prose-core' ),
				__( 'Child Support Enforcement', 'prose-core' ),
				__( 'Child Custody', 'prose-core' ),
				__( 'Visitation', 'prose-core' ),
				__( 'Paternity', 'prose-core' ),
				__( 'Family Offense', 'prose-core' ),
				__( 'Orders of Protection', 'prose-core' ),
			),
		);

		foreach ( $case_types as $parent => $children ) {
			$parent_id = $this->ensure_term( $parent, self::TAXONOMY_CASE_TYPE );

			if ( ! $parent_id ) {
				continue;
			}

			foreach ( $children as $child ) {
				$this->ensure_term( $child, self::TAXONOMY_CASE_TYPE, $parent_id );
			}
		}

		$workflow_stages = array(
			'Supreme Court' => array(
				__( 'Commencement', 'prose-core' ),
				__( 'Service', 'prose-core' ),
				__( 'Response', 'prose-core' ),
				__( 'Settlement', 'prose-core' ),
				__( 'Judgment', 'prose-core' ),
				__( 'Post-Judgment', 'prose-core' ),
			),
			'Family Court' => array(
				__( 'Petition', 'prose-core' ),
				__( 'Service', 'prose-core' ),
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
	 * Ensure a child term under a named parent (court-aware resolution).
	 *
	 * @param string $child_name  Child term name.
	 * @param string $parent_name Parent term name.
	 * @param string $taxonomy    Taxonomy slug.
	 * @return int|null
	 */
	public function ensure_child_term( string $child_name, string $parent_name, string $taxonomy ): ?int {
		$parent_id = $this->ensure_term( $parent_name, $taxonomy );

		if ( ! $parent_id ) {
			return null;
		}

		return $this->ensure_term( $child_name, $taxonomy, $parent_id );
	}

	/**
	 * Resolve workflow stage parent from detected court.
	 *
	 * @param string $court Detected court name.
	 * @return string
	 */
	public function workflow_parent_for_court( string $court ): string {
		if ( str_contains( strtoupper( $court ), 'FAMILY' ) ) {
			return __( 'Family Court', 'prose-core' );
		}

		return __( 'Supreme Court', 'prose-core' );
	}

	/**
	 * Resolve case type parent from detected court and case type.
	 *
	 * @param string $court     Detected court.
	 * @param string $case_type Detected case type.
	 * @return string
	 */
	public function case_type_parent_for_court( string $court, string $case_type ): string {
		$family_types = array(
			'Child Support',
			'Child Support Modification',
			'Child Support Enforcement',
			'Child Custody',
			'Visitation',
			'Paternity',
			'Family Offense',
		);

		if ( in_array( $case_type, $family_types, true ) || str_contains( strtoupper( $court ), 'FAMILY' ) ) {
			return __( 'Family Court', 'prose-core' );
		}

		return __( 'Divorce', 'prose-core' );
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
	public function ensure_term( string $name, string $taxonomy, ?int $parent = null ): ?int {
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
