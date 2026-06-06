<?php
/**
 * Form data access layer.
 *
 * Extension point: future modules should use repositories instead of direct WP_Query.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Forms;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Form_Repository
 */
class Form_Repository {

	/**
	 * Find a form post by its external form number.
	 *
	 * @param string $form_id Form number (e.g. UD-1).
	 * @return \WP_Post|null
	 */
	public function get_by_form_id( string $form_id ): ?\WP_Post {
		$form_id = sanitize_text_field( $form_id );

		if ( '' === $form_id ) {
			return null;
		}

		$query = new \WP_Query(
			array(
				'post_type'      => Form_CPT::POST_TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'   => Form_Meta::META_FORM_ID,
						'value' => $form_id,
					),
				),
				'fields'         => 'all',
			)
		);

		if ( ! $query->have_posts() ) {
			return null;
		}

		return $query->posts[0];
	}

	/**
	 * Create or update a form post.
	 *
	 * @param array{
	 *     form_id?: string,
	 *     title?: string,
	 *     file_name?: string,
	 *     file_url?: string,
	 *     source_pdf_url?: string,
	 *     case_types?: string[],
	 *     post_id?: int
	 * } $data Form data.
	 * @return array{post_id: int, created: bool}|\WP_Error
	 */
	public function create_or_update( array $data ) {
		$form_id = isset( $data['form_id'] ) ? sanitize_text_field( $data['form_id'] ) : '';

		if ( '' === $form_id ) {
			return new \WP_Error( 'prose_missing_form_id', __( 'Form number is required.', 'prose-core' ) );
		}

		$existing = isset( $data['post_id'] ) ? get_post( (int) $data['post_id'] ) : $this->get_by_form_id( $form_id );
		$created  = false;

		$post_data = array(
			'post_type'   => Form_CPT::POST_TYPE,
			'post_status' => 'publish',
			'post_title'  => isset( $data['title'] ) ? sanitize_text_field( $data['title'] ) : $form_id,
		);

		if ( $existing instanceof \WP_Post ) {
			$post_data['ID'] = $existing->ID;
			$post_id         = wp_update_post( $post_data, true );
		} else {
			$post_id = wp_insert_post( $post_data, true );
			$created = true;
		}

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		update_post_meta( $post_id, Form_Meta::META_FORM_ID, $form_id );

		if ( isset( $data['file_name'] ) ) {
			update_post_meta( $post_id, Form_Meta::META_FILE_NAME, sanitize_file_name( $data['file_name'] ) );
		}

		if ( isset( $data['file_url'] ) ) {
			update_post_meta( $post_id, Form_Meta::META_FILE_URL, esc_url_raw( $data['file_url'] ) );
		}

		if ( isset( $data['source_pdf_url'] ) ) {
			update_post_meta( $post_id, Form_Meta::META_SOURCE_PDF_URL, esc_url_raw( $data['source_pdf_url'] ) );
		}

		if ( ! empty( $data['case_types'] ) && is_array( $data['case_types'] ) ) {
			$taxonomy = new Form_Taxonomy();
			$term_ids = $taxonomy->ensure_terms( $data['case_types'] );

			if ( ! empty( $term_ids ) ) {
				wp_set_object_terms( $post_id, $term_ids, Form_Taxonomy::TAXONOMY );
			}
		}

		return array(
			'post_id' => (int) $post_id,
			'created' => $created,
		);
	}

	/**
	 * Search forms with optional filters.
	 *
	 * @param array{
	 *     search?: string,
	 *     case_type?: string,
	 *     posts_per_page?: int,
	 *     paged?: int
	 * } $args Search arguments.
	 * @return \WP_Post[]
	 */
	public function search( array $args = array() ): array {
		$query_args = array(
			'post_type'      => Form_CPT::POST_TYPE,
			'post_status'    => 'publish',
			'posts_per_page' => isset( $args['posts_per_page'] ) ? (int) $args['posts_per_page'] : 20,
			'paged'          => isset( $args['paged'] ) ? (int) $args['paged'] : 1,
			'orderby'        => 'title',
			'order'          => 'ASC',
		);

		if ( ! empty( $args['search'] ) ) {
			$query_args['s'] = sanitize_text_field( $args['search'] );
		}

		if ( ! empty( $args['case_type'] ) ) {
			$query_args['tax_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
				array(
					'taxonomy' => Form_Taxonomy::TAXONOMY,
					'field'    => 'slug',
					'terms'    => sanitize_title( $args['case_type'] ),
				),
			);
		}

		$query = new \WP_Query( $query_args );

		return $query->posts;
	}

	/**
	 * Get forms assigned to a case type term.
	 *
	 * @param string $case_type Case type name or slug.
	 * @return \WP_Post[]
	 */
	public function get_by_case_type( string $case_type ): array {
		$case_type = sanitize_text_field( $case_type );

		if ( '' === $case_type ) {
			return array();
		}

		$term = get_term_by( 'name', $case_type, Form_Taxonomy::TAXONOMY );

		if ( ! $term ) {
			$term = get_term_by( 'slug', sanitize_title( $case_type ), Form_Taxonomy::TAXONOMY );
		}

		if ( ! $term || is_wp_error( $term ) ) {
			return array();
		}

		$query = new \WP_Query(
			array(
				'post_type'      => Form_CPT::POST_TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'tax_query'      => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
					array(
						'taxonomy' => Form_Taxonomy::TAXONOMY,
						'field'    => 'term_id',
						'terms'    => (int) $term->term_id,
					),
				),
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);

		return $query->posts;
	}
}
