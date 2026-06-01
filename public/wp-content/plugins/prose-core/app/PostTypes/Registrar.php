<?php
/**
 * Custom post type registration.
 *
 * @package ProseCore
 */

namespace Prose\Core\PostTypes;

/**
 * Registers CourtFlow CMS post types.
 */
final class Registrar {

	public static function register(): void {
		self::register_workflow();
		self::register_form();
		self::register_question();
		self::register_county();
		self::register_court();
	}

	private static function register_workflow(): void {
		register_post_type(
			'cf_workflow',
			array(
				'labels'       => array(
					'name'          => __( 'Workflows', 'prose-core' ),
					'singular_name' => __( 'Workflow', 'prose-core' ),
				),
				'public'       => false,
				'show_ui'      => true,
				'show_in_menu' => 'courtflow',
				'show_in_rest' => true,
				'supports'     => array( 'title', 'editor', 'revisions', 'custom-fields' ),
				'has_archive'  => false,
				'capability_type' => 'cf_workflow',
				'map_meta_cap'    => true,
			)
		);
	}

	private static function register_form(): void {
		register_post_type(
			'cf_form',
			array(
				'labels'       => array(
					'name'          => __( 'Official Forms', 'prose-core' ),
					'singular_name' => __( 'Official Form', 'prose-core' ),
				),
				'public'       => false,
				'show_ui'      => true,
				'show_in_menu' => false,
				'show_in_rest' => true,
				'supports'     => array( 'title', 'editor', 'revisions', 'thumbnail' ),
				'capability_type' => 'cf_form',
				'map_meta_cap'    => true,
			)
		);
	}

	private static function register_question(): void {
		register_post_type(
			'cf_question',
			array(
				'labels'       => array(
					'name'          => __( 'Intake Questions', 'prose-core' ),
					'singular_name' => __( 'Intake Question', 'prose-core' ),
				),
				'public'       => false,
				'show_ui'      => true,
				'show_in_menu' => 'courtflow',
				'show_in_rest' => true,
				'supports'     => array( 'title', 'editor', 'custom-fields' ),
				'capability_type' => 'cf_question',
				'map_meta_cap'    => true,
			)
		);
	}

	private static function register_county(): void {
		register_post_type(
			'cf_county',
			array(
				'labels'       => array(
					'name'          => __( 'Counties', 'prose-core' ),
					'singular_name' => __( 'County', 'prose-core' ),
				),
				'public'       => false,
				'show_ui'      => true,
				'show_in_menu' => 'courtflow',
				'show_in_rest' => true,
				'supports'     => array( 'title', 'custom-fields' ),
				'capability_type' => 'cf_county',
				'map_meta_cap'    => true,
			)
		);
	}

	private static function register_court(): void {
		register_post_type(
			'cf_court',
			array(
				'labels'       => array(
					'name'          => __( 'Courts', 'prose-core' ),
					'singular_name' => __( 'Court', 'prose-core' ),
				),
				'public'       => false,
				'show_ui'      => true,
				'show_in_menu' => 'courtflow',
				'show_in_rest' => true,
				'supports'     => array( 'title', 'editor', 'custom-fields' ),
				'capability_type' => 'cf_court',
				'map_meta_cap'    => true,
			)
		);
	}
}
