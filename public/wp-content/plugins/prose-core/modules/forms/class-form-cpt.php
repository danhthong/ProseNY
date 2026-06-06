<?php
/**
 * Custom post type: prose_form.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Forms;

use ProSe\Core\Loader;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Form_CPT
 */
class Form_CPT {

	/**
	 * Post type slug.
	 */
	public const POST_TYPE = 'prose_form';

	/**
	 * Register hooks.
	 *
	 * @param Loader $loader Hook loader.
	 * @return void
	 */
	public function register( Loader $loader ): void {
		$loader->add_action( 'init', $this, 'register_post_type' );
		$loader->add_filter( 'manage_' . self::POST_TYPE . '_posts_columns', $this, 'register_columns' );
		$loader->add_action( 'manage_' . self::POST_TYPE . '_posts_custom_column', $this, 'render_column', 10, 2 );
	}

	/**
	 * Register the prose_form post type.
	 *
	 * @return void
	 */
	public function register_post_type(): void {
		$labels = array(
			'name'               => _x( 'Forms', 'post type general name', 'prose-core' ),
			'singular_name'      => _x( 'Form', 'post type singular name', 'prose-core' ),
			'menu_name'          => _x( 'Forms', 'admin menu', 'prose-core' ),
			'add_new'            => _x( 'Add New', 'form', 'prose-core' ),
			'add_new_item'       => __( 'Add New Form', 'prose-core' ),
			'edit_item'          => __( 'Edit Form', 'prose-core' ),
			'new_item'           => __( 'New Form', 'prose-core' ),
			'view_item'          => __( 'View Form', 'prose-core' ),
			'search_items'       => __( 'Search Forms', 'prose-core' ),
			'not_found'          => __( 'No forms found.', 'prose-core' ),
			'not_found_in_trash' => __( 'No forms found in Trash.', 'prose-core' ),
		);

		register_post_type(
			self::POST_TYPE,
			array(
				'labels'              => $labels,
				'public'              => false,
				'show_ui'             => true,
				'show_in_menu'        => 'prose',
				'show_in_rest'        => true,
				'rest_base'           => 'prose-forms',
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

	/**
	 * Add custom columns to the forms list table.
	 *
	 * @param array<string, string> $columns Existing columns.
	 * @return array<string, string>
	 */
	public function register_columns( array $columns ): array {
		$new_columns = array();

		foreach ( $columns as $key => $label ) {
			$new_columns[ $key ] = $label;

			if ( 'title' === $key ) {
				$new_columns['prose_form_number'] = __( 'Form Number', 'prose-core' );
				$new_columns['prose_pdf']         = __( 'PDF', 'prose-core' );
				$new_columns['prose_source']      = __( 'Source URL', 'prose-core' );
			}
		}

		return $new_columns;
	}

	/**
	 * Render custom column content.
	 *
	 * @param string $column  Column key.
	 * @param int    $post_id Post ID.
	 * @return void
	 */
	public function render_column( string $column, int $post_id ): void {
		switch ( $column ) {
			case 'prose_form_number':
				echo esc_html( (string) get_post_meta( $post_id, Form_Meta::META_FORM_ID, true ) );
				break;

			case 'prose_pdf':
				$file_url  = (string) get_post_meta( $post_id, Form_Meta::META_FILE_URL, true );
				$file_name = (string) get_post_meta( $post_id, Form_Meta::META_FILE_NAME, true );

				if ( '' !== $file_url ) {
					printf(
						'<a href="%1$s" target="_blank" rel="noopener noreferrer">%2$s</a>',
						esc_url( $file_url ),
						esc_html( '' !== $file_name ? $file_name : __( 'View PDF', 'prose-core' ) )
					);
				} else {
					echo '<span aria-hidden="true">&#8212;</span>';
				}
				break;

			case 'prose_source':
				$source_url = (string) get_post_meta( $post_id, Form_Meta::META_SOURCE_PDF_URL, true );

				if ( '' !== $source_url ) {
					printf(
						'<a href="%1$s" target="_blank" rel="noopener noreferrer">%2$s</a>',
						esc_url( $source_url ),
						esc_html__( 'Source', 'prose-core' )
					);
				} else {
					echo '<span aria-hidden="true">&#8212;</span>';
				}
				break;
		}
	}
}
