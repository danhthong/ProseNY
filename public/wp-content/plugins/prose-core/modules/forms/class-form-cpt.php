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
			if ( 'title' === $key ) {
				$new_columns['title']                = $label;
				$new_columns['prose_form_code']      = __( 'Form Code', 'prose-core' );
				$new_columns['prose_court']          = __( 'Court', 'prose-core' );
				$new_columns['prose_case_type']      = __( 'Case Type', 'prose-core' );
				$new_columns['prose_workflow_stage'] = __( 'Workflow Stage', 'prose-core' );
				$new_columns['prose_workflow_key']   = __( 'Workflow Key', 'prose-core' );
				$new_columns['prose_packet_group']   = __( 'Packet Group', 'prose-core' );
				$new_columns['prose_required']       = __( 'Required', 'prose-core' );
				$new_columns['prose_pdf']            = __( 'PDF', 'prose-core' );
				$new_columns['prose_pdf_fields']     = __( 'PDF Fields', 'prose-core' );
				continue;
			}

			if ( in_array( $key, array( 'taxonomy-prose_case_type', 'taxonomy-prose_court', 'taxonomy-prose_workflow_stage' ), true ) ) {
				continue;
			}

			$new_columns[ $key ] = $label;
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
			case 'prose_form_code':
				$code = (string) get_post_meta( $post_id, Form_Meta::META_FORM_CODE, true );

				if ( '' === $code ) {
					$code = (string) get_post_meta( $post_id, Form_Meta::META_FORM_ID, true );
				}

				echo esc_html( $code );
				break;

			case 'prose_court':
				echo wp_kses_post( $this->format_terms( $post_id, Form_Taxonomy::TAXONOMY_COURT ) );
				break;

			case 'prose_case_type':
				echo wp_kses_post( $this->format_terms( $post_id, Form_Taxonomy::TAXONOMY_CASE_TYPE ) );
				break;

			case 'prose_workflow_stage':
				echo wp_kses_post( $this->format_terms( $post_id, Form_Taxonomy::TAXONOMY_WORKFLOW_STAGE ) );
				break;

			case 'prose_workflow_key':
				echo esc_html( (string) get_post_meta( $post_id, Form_Meta::META_WORKFLOW_KEY, true ) );
				break;

			case 'prose_packet_group':
				echo esc_html( (string) get_post_meta( $post_id, Form_Meta::META_PACKET_GROUP, true ) );
				break;

			case 'prose_required':
				$required = (bool) get_post_meta( $post_id, Form_Meta::META_REQUIRED, true );
				echo $required ? esc_html__( 'Yes', 'prose-core' ) : '<span aria-hidden="true">&#8212;</span>';
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

			case 'prose_pdf_fields':
				$count = (int) get_post_meta( $post_id, Form_Meta::META_PDF_FIELD_COUNT, true );

				if ( $count > 0 ) {
					echo esc_html( (string) $count );
				} else {
					echo '<span aria-hidden="true">&#8212;</span>';
				}
				break;
		}
	}

	/**
	 * Format taxonomy terms for list table display.
	 *
	 * @param int    $post_id  Post ID.
	 * @param string $taxonomy Taxonomy slug.
	 * @return string
	 */
	private function format_terms( int $post_id, string $taxonomy ): string {
		$terms = get_the_terms( $post_id, $taxonomy );

		if ( empty( $terms ) || is_wp_error( $terms ) ) {
			return '<span aria-hidden="true">&#8212;</span>';
		}

		$names = wp_list_pluck( $terms, 'name' );

		return esc_html( implode( ', ', $names ) );
	}
}
