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
		$loader->add_action( 'admin_menu', $this, 'register_taxonomy_submenus', 30 );
		$loader->add_action( 'restrict_manage_posts', $this, 'render_taxonomy_filters' );
		$loader->add_filter( 'parent_file', $this, 'fix_taxonomy_parent_menu' );
		$loader->add_action( 'pre_get_posts', $this, 'enable_form_code_admin_search' );
	}

	/**
	 * Register taxonomy management pages as submenus under the ProSe menu.
	 *
	 * @return void
	 */
	public function register_taxonomy_submenus(): void {
		$taxonomies = array(
			Form_Taxonomy::TAXONOMY_CASE_TYPE,
		);

		foreach ( $taxonomies as $taxonomy ) {
			$tax_object = get_taxonomy( $taxonomy );

			if ( ! $tax_object ) {
				continue;
			}

			add_submenu_page(
				'prose',
				$tax_object->labels->name,
				$tax_object->labels->menu_name,
				$tax_object->cap->manage_terms,
				'edit-tags.php?taxonomy=' . $taxonomy . '&post_type=' . self::POST_TYPE
			);
		}
	}

	/**
	 * Keep the ProSe menu highlighted on taxonomy term screens.
	 *
	 * @param string $parent_file Current parent file.
	 * @return string
	 */
	public function fix_taxonomy_parent_menu( string $parent_file ): string {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( $screen && 'edit-tags' === $screen->base && self::POST_TYPE === $screen->post_type ) {
			return 'prose';
		}

		return $parent_file;
	}

	/**
	 * Render taxonomy filter dropdowns on the forms list table.
	 *
	 * @param string $post_type Current post type.
	 * @return void
	 */
	public function render_taxonomy_filters( string $post_type ): void {
		if ( self::POST_TYPE !== $post_type ) {
			return;
		}

		$taxonomies = array(
			Form_Taxonomy::TAXONOMY_CASE_TYPE,
		);

		foreach ( $taxonomies as $taxonomy ) {
			$tax_object = get_taxonomy( $taxonomy );

			if ( ! $tax_object ) {
				continue;
			}

			$selected = isset( $_GET[ $taxonomy ] ) ? sanitize_text_field( wp_unslash( $_GET[ $taxonomy ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

			wp_dropdown_categories(
				array(
					'show_option_all' => $tax_object->labels->all_items,
					'taxonomy'        => $taxonomy,
					'name'            => $taxonomy,
					'value_field'     => 'slug',
					'selected'        => $selected,
					'hierarchical'    => true,
					'hide_empty'      => false,
					'show_count'      => false,
					'orderby'         => 'name',
				)
			);
		}
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
				$new_columns['prose_case_type']      = __( 'Case Type', 'prose-core' );
				$new_columns['prose_workflow_key']   = __( 'Workflow Key', 'prose-core' );
				$new_columns['prose_packet_group']   = __( 'Packet Group', 'prose-core' );
				$new_columns['prose_required']       = __( 'Required', 'prose-core' );
				$new_columns['prose_pdf']            = __( 'PDF', 'prose-core' );
				$new_columns['prose_pdf_fields']     = __( 'PDF Fields', 'prose-core' );
				$new_columns['prose_needs_review']   = __( 'Needs Review', 'prose-core' );
				$new_columns['prose_confidence']     = __( 'Confidence', 'prose-core' );
				$new_columns['prose_scan']           = __( 'Metadata', 'prose-core' );
				continue;
			}

			if ( in_array( $key, array( 'taxonomy-prose_case_type' ), true ) ) {
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

			case 'prose_case_type':
				echo wp_kses_post( $this->format_terms( $post_id, Form_Taxonomy::TAXONOMY_CASE_TYPE ) );
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
				$resolver  = new Form_Pdf_Path_Resolver();
				$file_name = $resolver->pdf_filename_for_post( get_post( $post_id ) );
				$file_url  = (string) get_post_meta( $post_id, Form_Meta::META_FILE_URL, true );

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

			case 'prose_needs_review':
				$needs = (bool) get_post_meta( $post_id, Form_Meta::META_NEEDS_REVIEW, true );
				echo $needs
					? '<span class="prose-badge prose-badge--warning">' . esc_html__( 'Yes', 'prose-core' ) . '</span>'
					: '<span aria-hidden="true">&#8212;</span>';
				break;

			case 'prose_confidence':
				$confidence = get_post_meta( $post_id, Form_Meta::META_CLASSIFICATION_CONFIDENCE, true );

				if ( '' !== (string) $confidence ) {
					echo esc_html( (string) $confidence . '%' );
				} else {
					echo '<span aria-hidden="true">&#8212;</span>';
				}
				break;

			case 'prose_scan':
				$has_pdf = '' !== (string) get_post_meta( $post_id, Form_Meta::META_FILE_NAME, true );

				if ( ! $has_pdf || ! current_user_can( 'edit_post', $post_id ) ) {
					echo '<span aria-hidden="true">&#8212;</span>';
					break;
				}

				printf(
					'<button type="button" class="button button-small prose-scan-btn" data-post-id="%1$d">%2$s</button> <span class="prose-scan-status" aria-live="polite"></span>',
					(int) $post_id,
					esc_html__( 'Scan &amp; Fill', 'prose-core' )
				);
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

	/**
	 * Extend the Forms list table search to match form code meta.
	 *
	 * @param \WP_Query $query Main admin query.
	 * @return void
	 */
	public function enable_form_code_admin_search( \WP_Query $query ): void {
		if ( ! $this->is_admin_form_code_search( $query ) ) {
			return;
		}

		$query->set( 'prose_form_code_search', true );

		add_filter( 'posts_join', array( $this, 'filter_form_code_search_join' ), 10, 2 );
		add_filter( 'posts_search', array( $this, 'filter_form_code_search_clause' ), 10, 2 );
		add_filter( 'posts_distinct', array( $this, 'filter_form_code_search_distinct' ), 10, 2 );
	}

	/**
	 * Whether the current query is an admin search on the forms list screen.
	 *
	 * @param \WP_Query $query Query.
	 * @return bool
	 */
	private function is_admin_form_code_search( \WP_Query $query ): bool {
		if ( ! is_admin() || ! $query->is_main_query() ) {
			return false;
		}

		global $pagenow;

		if ( 'edit.php' !== $pagenow ) {
			return false;
		}

		$post_type = $query->get( 'post_type' );

		if ( is_array( $post_type ) ) {
			if ( ! in_array( self::POST_TYPE, $post_type, true ) ) {
				return false;
			}
		} elseif ( self::POST_TYPE !== $post_type ) {
			return false;
		}

		return '' !== trim( (string) $query->get( 's' ) );
	}

	/**
	 * Join form code meta for admin search.
	 *
	 * @param string    $join  SQL join clause.
	 * @param \WP_Query $query Query.
	 * @return string
	 */
	public function filter_form_code_search_join( string $join, \WP_Query $query ): string {
		if ( ! $query->get( 'prose_form_code_search' ) ) {
			return $join;
		}

		global $wpdb;

		$join .= $wpdb->prepare(
			" LEFT JOIN {$wpdb->postmeta} AS prose_form_code_search ON ({$wpdb->posts}.ID = prose_form_code_search.post_id AND prose_form_code_search.meta_key = %s)",
			Form_Meta::META_FORM_CODE
		);
		$join .= $wpdb->prepare(
			" LEFT JOIN {$wpdb->postmeta} AS prose_form_id_search ON ({$wpdb->posts}.ID = prose_form_id_search.post_id AND prose_form_id_search.meta_key = %s)",
			Form_Meta::META_FORM_ID
		);

		return $join;
	}

	/**
	 * Append form code meta matches to the default title/content search.
	 *
	 * @param string    $search Search SQL fragment.
	 * @param \WP_Query $query  Query.
	 * @return string
	 */
	public function filter_form_code_search_clause( string $search, \WP_Query $query ): string {
		if ( ! $query->get( 'prose_form_code_search' ) ) {
			return $search;
		}

		global $wpdb;

		$term = trim( (string) $query->get( 's' ) );

		if ( '' === $term ) {
			return $search;
		}

		$like = '%' . $wpdb->esc_like( $term ) . '%';

		$search .= $wpdb->prepare(
			" OR (prose_form_code_search.meta_value LIKE %s) OR (prose_form_id_search.meta_value LIKE %s)",
			$like,
			$like
		);

		return $search;
	}

	/**
	 * Prevent duplicate rows when form meta joins match.
	 *
	 * @param string    $distinct DISTINCT clause.
	 * @param \WP_Query $query    Query.
	 * @return string
	 */
	public function filter_form_code_search_distinct( string $distinct, \WP_Query $query ): string {
		if ( ! $query->get( 'prose_form_code_search' ) ) {
			return $distinct;
		}

		return 'DISTINCT';
	}
}
