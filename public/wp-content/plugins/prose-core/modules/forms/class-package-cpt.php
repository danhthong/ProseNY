<?php
/**
 * Custom post type: prose_package.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Forms;

use ProSe\Core\Loader;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Package_CPT
 */
class Package_CPT {

	/**
	 * Post type slug.
	 */
	public const POST_TYPE = 'prose_package';

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
	 * Register the prose_package post type.
	 *
	 * @return void
	 */
	public function register_post_type(): void {
		$labels = array(
			'name'               => _x( 'Packages', 'post type general name', 'prose-core' ),
			'singular_name'      => _x( 'Package', 'post type singular name', 'prose-core' ),
			'menu_name'          => _x( 'Packages', 'admin menu', 'prose-core' ),
			'add_new'            => _x( 'Add New', 'package', 'prose-core' ),
			'add_new_item'       => __( 'Add New Package', 'prose-core' ),
			'edit_item'          => __( 'Edit Package', 'prose-core' ),
			'new_item'           => __( 'New Package', 'prose-core' ),
			'view_item'          => __( 'View Package', 'prose-core' ),
			'search_items'       => __( 'Search Packages', 'prose-core' ),
			'not_found'          => __( 'No packages found.', 'prose-core' ),
			'not_found_in_trash' => __( 'No packages found in Trash.', 'prose-core' ),
		);

		register_post_type(
			self::POST_TYPE,
			array(
				'labels'              => $labels,
				'public'              => false,
				'show_ui'             => false,
				'show_in_menu'        => false,
				'show_in_rest'        => true,
				'rest_base'           => 'prose-packages',
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
