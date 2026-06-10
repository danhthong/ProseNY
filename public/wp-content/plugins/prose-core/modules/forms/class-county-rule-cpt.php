<?php
/**
 * Custom post type: prose_county_rule.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Forms;

use ProSe\Core\Loader;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class County_Rule_CPT
 */
class County_Rule_CPT {

	/**
	 * Post type slug.
	 */
	public const POST_TYPE = 'prose_county_rule';

	/**
	 * Register hooks.
	 *
	 * @param Loader $loader Hook loader.
	 * @return void
	 */
	public function register( Loader $loader ): void {
		$loader->add_action( 'init', $this, 'register_post_type' );
	}

	/**
	 * Register the prose_county_rule post type.
	 *
	 * @return void
	 */
	public function register_post_type(): void {
		$labels = array(
			'name'               => _x( 'County Rules', 'post type general name', 'prose-core' ),
			'singular_name'      => _x( 'County Rule', 'post type singular name', 'prose-core' ),
			'menu_name'          => _x( 'County Rules', 'admin menu', 'prose-core' ),
			'add_new'            => _x( 'Add New', 'county rule', 'prose-core' ),
			'add_new_item'       => __( 'Add New County Rule', 'prose-core' ),
			'edit_item'          => __( 'Edit County Rule', 'prose-core' ),
			'new_item'           => __( 'New County Rule', 'prose-core' ),
			'view_item'          => __( 'View County Rule', 'prose-core' ),
			'search_items'       => __( 'Search County Rules', 'prose-core' ),
			'not_found'          => __( 'No county rules found.', 'prose-core' ),
			'not_found_in_trash' => __( 'No county rules found in Trash.', 'prose-core' ),
		);

		register_post_type(
			self::POST_TYPE,
			array(
				'labels'              => $labels,
				'public'              => false,
				'show_ui'             => true,
				'show_in_menu'        => 'prose',
				'show_in_rest'        => true,
				'rest_base'           => 'prose-county-rules',
				'capability_type'     => 'post',
				'map_meta_cap'        => true,
				'hierarchical'        => false,
				'supports'            => array( 'title' ),
				'has_archive'         => false,
				'rewrite'             => false,
				'exclude_from_search' => true,
			)
		);
	}
}
