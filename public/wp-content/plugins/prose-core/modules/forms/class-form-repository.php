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
	 * Find a form post by its external form number (legacy alias).
	 *
	 * @param string $form_id Form number (e.g. UD-1).
	 * @return \WP_Post|null
	 */
	public function get_by_form_id( string $form_id ): ?\WP_Post {
		return $this->get_by_form_code( $form_id );
	}

	/**
	 * Find a form post by its form code.
	 *
	 * @param string $form_code Form code (e.g. UD-1).
	 * @return \WP_Post|null
	 */
	public function get_by_form_code( string $form_code ): ?\WP_Post {
		$form_code = sanitize_text_field( $form_code );

		if ( '' === $form_code || '--' === $form_code ) {
			return null;
		}

		$post = $this->query_by_meta( Form_Meta::META_FORM_CODE, $form_code );

		if ( $post instanceof \WP_Post ) {
			return $post;
		}

		return $this->query_by_meta( Form_Meta::META_FORM_ID, $form_code );
	}

	/**
	 * Find a form post by exact title (for numberless forms).
	 *
	 * @param string $title Post title.
	 * @return \WP_Post|null
	 */
	public function get_by_title( string $title ): ?\WP_Post {
		$title = sanitize_text_field( $title );

		if ( '' === $title ) {
			return null;
		}

		$post = get_page_by_title( $title, OBJECT, Form_CPT::POST_TYPE );

		return ( $post instanceof \WP_Post ) ? $post : null;
	}

	/**
	 * Create or update a form post.
	 *
	 * @param array{
	 *     form_id?: string,
	 *     form_code?: string,
	 *     title?: string,
	 *     county?: string,
	 *     workflow_key?: string,
	 *     workflow_order?: int,
	 *     packet_group?: string,
	 *     required?: bool,
	 *     dependencies?: string|array,
	 *     conditions?: string|array,
	 *     file_name?: string,
	 *     file_url?: string,
	 *     source_pdf_url?: string,
	 *     source_files?: string|array,
	 *     case_types?: string[],
	 *     court?: string[],
	 *     workflow_stage?: string[],
	 *     post_id?: int
	 * } $data Form data.
	 * @return array{post_id: int, created: bool}|\WP_Error
	 */
	public function create_or_update( array $data ) {
		$form_code = '';

		if ( isset( $data['form_code'] ) ) {
			$form_code = sanitize_text_field( (string) $data['form_code'] );
		} elseif ( isset( $data['form_id'] ) ) {
			$form_code = sanitize_text_field( (string) $data['form_id'] );
		}

		$form_code = ( '--' === $form_code ) ? '' : $form_code;
		$title     = isset( $data['title'] ) ? sanitize_text_field( $data['title'] ) : '';

		if ( '' === $title && '' === $form_code ) {
			return new \WP_Error( 'prose_missing_identity', __( 'Form title is required when no form number is provided.', 'prose-core' ) );
		}

		if ( isset( $data['post_id'] ) ) {
			$existing = get_post( (int) $data['post_id'] );
		} elseif ( '' !== $form_code ) {
			$existing = $this->get_by_form_code( $form_code );
		} elseif ( '' !== $title ) {
			$existing = $this->get_by_title( $title );
		} else {
			$existing = null;
		}

		$created = false;

		$post_data = array(
			'post_type'   => Form_CPT::POST_TYPE,
			'post_status' => 'publish',
			'post_title'  => '' !== $title ? $title : $form_code,
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

		update_post_meta( $post_id, Form_Meta::META_FORM_CODE, $form_code );
		update_post_meta( $post_id, Form_Meta::META_FORM_ID, $form_code );

		$string_fields = array(
			'county'         => Form_Meta::META_COUNTY,
			'workflow_key'   => Form_Meta::META_WORKFLOW_KEY,
			'packet_group'   => Form_Meta::META_PACKET_GROUP,
			'file_name'      => Form_Meta::META_FILE_NAME,
			'file_url'       => Form_Meta::META_FILE_URL,
			'source_pdf_url' => Form_Meta::META_SOURCE_PDF_URL,
		);

		foreach ( $string_fields as $data_key => $meta_key ) {
			if ( ! array_key_exists( $data_key, $data ) ) {
				continue;
			}

			$value = (string) $data[ $data_key ];

			if ( in_array( $data_key, array( 'file_url', 'source_pdf_url' ), true ) ) {
				update_post_meta( $post_id, $meta_key, esc_url_raw( $value ) );
			} elseif ( 'file_name' === $data_key ) {
				update_post_meta( $post_id, $meta_key, sanitize_file_name( $value ) );
			} else {
				update_post_meta( $post_id, $meta_key, sanitize_text_field( $value ) );
			}
		}

		if ( isset( $data['workflow_order'] ) ) {
			update_post_meta( $post_id, Form_Meta::META_WORKFLOW_ORDER, absint( $data['workflow_order'] ) );
		}

		if ( isset( $data['required'] ) ) {
			update_post_meta( $post_id, Form_Meta::META_REQUIRED, (bool) $data['required'] );
		}

		$json_fields = array(
			'dependencies' => Form_Meta::META_DEPENDENCIES,
			'conditions'   => Form_Meta::META_CONDITIONS,
			'source_files' => Form_Meta::META_SOURCE_FILES,
		);

		foreach ( $json_fields as $data_key => $meta_key ) {
			if ( ! array_key_exists( $data_key, $data ) ) {
				continue;
			}

			update_post_meta( $post_id, $meta_key, Form_Meta::sanitize_json( $data[ $data_key ] ) );
		}

		$taxonomy = new Form_Taxonomy();

		if ( ! empty( $data['case_types'] ) && is_array( $data['case_types'] ) ) {
			$term_ids = $taxonomy->ensure_terms( $data['case_types'], Form_Taxonomy::TAXONOMY_CASE_TYPE );

			if ( ! empty( $term_ids ) ) {
				wp_set_object_terms( $post_id, $term_ids, Form_Taxonomy::TAXONOMY_CASE_TYPE );
			}
		}

		if ( ! empty( $data['court'] ) && is_array( $data['court'] ) ) {
			$term_ids = $taxonomy->ensure_terms( $data['court'], Form_Taxonomy::TAXONOMY_COURT );

			if ( ! empty( $term_ids ) ) {
				wp_set_object_terms( $post_id, $term_ids, Form_Taxonomy::TAXONOMY_COURT );
			}
		}

		if ( ! empty( $data['workflow_stage'] ) && is_array( $data['workflow_stage'] ) ) {
			$term_ids = $taxonomy->ensure_terms( $data['workflow_stage'], Form_Taxonomy::TAXONOMY_WORKFLOW_STAGE );

			if ( ! empty( $term_ids ) ) {
				wp_set_object_terms( $post_id, $term_ids, Form_Taxonomy::TAXONOMY_WORKFLOW_STAGE );
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
	 *     court?: string,
	 *     workflow_stage?: string,
	 *     workflow_key?: string,
	 *     packet_group?: string,
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

		$tax_query = array();

		if ( ! empty( $args['case_type'] ) ) {
			$tax_query[] = array(
				'taxonomy' => Form_Taxonomy::TAXONOMY_CASE_TYPE,
				'field'    => 'slug',
				'terms'    => sanitize_title( $args['case_type'] ),
			);
		}

		if ( ! empty( $args['court'] ) ) {
			$tax_query[] = array(
				'taxonomy' => Form_Taxonomy::TAXONOMY_COURT,
				'field'    => 'slug',
				'terms'    => sanitize_title( $args['court'] ),
			);
		}

		if ( ! empty( $args['workflow_stage'] ) ) {
			$tax_query[] = array(
				'taxonomy' => Form_Taxonomy::TAXONOMY_WORKFLOW_STAGE,
				'field'    => 'slug',
				'terms'    => sanitize_title( $args['workflow_stage'] ),
			);
		}

		if ( ! empty( $tax_query ) ) {
			$query_args['tax_query'] = $tax_query; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
		}

		$meta_query = array();

		if ( ! empty( $args['workflow_key'] ) ) {
			$meta_query[] = array(
				'key'   => Form_Meta::META_WORKFLOW_KEY,
				'value' => sanitize_text_field( $args['workflow_key'] ),
			);
		}

		if ( ! empty( $args['packet_group'] ) ) {
			$meta_query[] = array(
				'key'   => Form_Meta::META_PACKET_GROUP,
				'value' => sanitize_text_field( $args['packet_group'] ),
			);
		}

		if ( ! empty( $meta_query ) ) {
			$query_args['meta_query'] = $meta_query; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
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
		return $this->get_by_taxonomy_term( $case_type, Form_Taxonomy::TAXONOMY_CASE_TYPE );
	}

	/**
	 * Get forms by workflow key.
	 *
	 * @param string $workflow_key Workflow key.
	 * @return \WP_Post[]
	 */
	public function get_forms_by_workflow( string $workflow_key ): array {
		$workflow_key = sanitize_text_field( $workflow_key );

		if ( '' === $workflow_key ) {
			return array();
		}

		$query = new \WP_Query(
			array(
				'post_type'      => Form_CPT::POST_TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'   => Form_Meta::META_WORKFLOW_KEY,
						'value' => $workflow_key,
					),
				),
				'meta_key'       => Form_Meta::META_WORKFLOW_ORDER,
				'orderby'        => 'meta_value_num',
				'order'          => 'ASC',
			)
		);

		return $query->posts;
	}

	/**
	 * Get forms assigned to a workflow stage term.
	 *
	 * @param string $stage Workflow stage name or slug.
	 * @return \WP_Post[]
	 */
	public function get_forms_by_stage( string $stage ): array {
		return $this->get_by_taxonomy_term( $stage, Form_Taxonomy::TAXONOMY_WORKFLOW_STAGE );
	}

	/**
	 * Get forms for a workflow packet, ordered by workflow order.
	 *
	 * @param string      $workflow_key Workflow key.
	 * @param string|null $packet_group Optional packet group filter.
	 * @return \WP_Post[]
	 */
	public function get_packet_forms( string $workflow_key, ?string $packet_group = null ): array {
		$workflow_key = sanitize_text_field( $workflow_key );

		if ( '' === $workflow_key ) {
			return array();
		}

		$meta_query = array(
			array(
				'key'   => Form_Meta::META_WORKFLOW_KEY,
				'value' => $workflow_key,
			),
		);

		if ( null !== $packet_group && '' !== $packet_group ) {
			$meta_query[] = array(
				'key'   => Form_Meta::META_PACKET_GROUP,
				'value' => sanitize_text_field( $packet_group ),
			);
		}

		$query = new \WP_Query(
			array(
				'post_type'      => Form_CPT::POST_TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'meta_query'     => $meta_query, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				'meta_key'       => Form_Meta::META_WORKFLOW_ORDER,
				'orderby'        => 'meta_value_num',
				'order'          => 'ASC',
			)
		);

		return $query->posts;
	}

	/**
	 * Get forms that have not been analyzed yet.
	 *
	 * @return \WP_Post[]
	 */
	public function get_forms_missing_analysis(): array {
		$query = new \WP_Query(
			array(
				'post_type'      => Form_CPT::POST_TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					'relation' => 'OR',
					array(
						'key'     => Form_Meta::META_PDF_ANALYZED_AT,
						'compare' => 'NOT EXISTS',
					),
					array(
						'key'     => Form_Meta::META_PDF_ANALYZED_AT,
						'value'   => '',
						'compare' => '=',
					),
				),
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);

		return $query->posts;
	}

	/**
	 * Update PDF analysis metadata for a form.
	 *
	 * @param int   $post_id Form post ID.
	 * @param array{
	 *     fillable?: bool,
	 *     field_count?: int,
	 *     fields_json?: string|array,
	 *     analyzed_at?: string
	 * } $data PDF metadata.
	 * @return bool
	 */
	public function update_pdf_metadata( int $post_id, array $data ): bool {
		if ( Form_CPT::POST_TYPE !== get_post_type( $post_id ) ) {
			return false;
		}

		if ( isset( $data['fillable'] ) ) {
			update_post_meta( $post_id, Form_Meta::META_PDF_FILLABLE, (bool) $data['fillable'] );
		}

		if ( isset( $data['field_count'] ) ) {
			update_post_meta( $post_id, Form_Meta::META_PDF_FIELD_COUNT, absint( $data['field_count'] ) );
		}

		if ( isset( $data['fields_json'] ) ) {
			update_post_meta( $post_id, Form_Meta::META_PDF_FIELDS_JSON, Form_Meta::sanitize_json( $data['fields_json'] ) );
		}

		if ( isset( $data['fillable_fields'] ) ) {
			update_post_meta( $post_id, Form_Meta::META_FILLABLE_FIELDS, Form_Meta::sanitize_json( $data['fillable_fields'] ) );
		}

		if ( isset( $data['analyzed_at'] ) ) {
			update_post_meta( $post_id, Form_Meta::META_PDF_ANALYZED_AT, sanitize_text_field( (string) $data['analyzed_at'] ) );
		}

		return true;
	}

	/**
	 * Save classification results to a form post.
	 *
	 * @param int                  $post_id Form post ID.
	 * @param array<string, mixed> $result  Classification result.
	 * @param bool                 $force   Force overwrite manual override.
	 * @return bool
	 */
	public function save_classification( int $post_id, array $result, bool $force = false ): bool {
		if ( Form_CPT::POST_TYPE !== get_post_type( $post_id ) ) {
			return false;
		}

		$manual = (bool) get_post_meta( $post_id, Form_Meta::META_MANUAL_OVERRIDE, true );

		if ( $manual && ! $force ) {
			$this->append_classification_log( $post_id, $result, 'skipped_manual_override' );
			return false;
		}

		$string_map = array(
			'detected_court'            => Form_Meta::META_DETECTED_COURT,
			'detected_county'           => Form_Meta::META_DETECTED_COUNTY,
			'detected_case_type'        => Form_Meta::META_DETECTED_CASE_TYPE,
			'detected_workflow_stage'   => Form_Meta::META_DETECTED_WORKFLOW_STAGE,
			'classification_source'     => Form_Meta::META_CLASSIFICATION_SOURCE,
			'pdf_analyzed_at'           => Form_Meta::META_PDF_ANALYZED_AT,
			'document_type'             => Form_Meta::META_DOCUMENT_TYPE,
			'user_summary'              => Form_Meta::META_USER_SUMMARY,
		);

		foreach ( $string_map as $key => $meta_key ) {
			if ( array_key_exists( $key, $result ) ) {
				update_post_meta( $post_id, $meta_key, sanitize_text_field( (string) $result[ $key ] ) );
			}
		}

		if ( array_key_exists( 'classification_confidence', $result ) ) {
			update_post_meta( $post_id, Form_Meta::META_CLASSIFICATION_CONFIDENCE, absint( $result['classification_confidence'] ) );
		}

		if ( array_key_exists( 'confidence_score', $result ) ) {
			update_post_meta( $post_id, Form_Meta::META_CONFIDENCE_SCORE, absint( $result['confidence_score'] ) );
		} elseif ( array_key_exists( 'classification_confidence', $result ) ) {
			update_post_meta( $post_id, Form_Meta::META_CONFIDENCE_SCORE, absint( $result['classification_confidence'] ) );
		}

		if ( array_key_exists( 'classification_warning', $result ) ) {
			update_post_meta( $post_id, Form_Meta::META_CLASSIFICATION_WARNING, sanitize_textarea_field( (string) $result['classification_warning'] ) );
		}

		if ( array_key_exists( 'supported_court', $result ) ) {
			update_post_meta( $post_id, Form_Meta::META_SUPPORTED_COURT, (bool) $result['supported_court'] );
		}

		if ( array_key_exists( 'needs_review', $result ) ) {
			update_post_meta( $post_id, Form_Meta::META_NEEDS_REVIEW, (bool) $result['needs_review'] );
		}

		if ( array_key_exists( 'ai_summary', $result ) && '' !== (string) $result['ai_summary'] ) {
			update_post_meta( $post_id, Form_Meta::META_AI_SUMMARY, sanitize_textarea_field( (string) $result['ai_summary'] ) );
		}

		$json_map = array(
			'questionnaire_keys'      => Form_Meta::META_QUESTIONNAIRE_KEYS,
			'workflow_package'        => Form_Meta::META_WORKFLOW_PACKAGE,
			'dependencies'            => Form_Meta::META_DEPENDENCIES,
			'pdf_fields_json'         => Form_Meta::META_PDF_FIELDS_JSON,
			'fillable_fields'         => Form_Meta::META_FILLABLE_FIELDS,
			'classification_signals'  => Form_Meta::META_CLASSIFICATION_SIGNALS,
			'ai_summary_structured'   => Form_Meta::META_AI_SUMMARY_STRUCTURED,
			'workflow_ids'            => Form_Meta::META_WORKFLOW_IDS,
			'package_ids'             => Form_Meta::META_PACKAGE_IDS,
			'workflow_stages'         => Form_Meta::META_WORKFLOW_STAGES,
			'issue_types'             => Form_Meta::META_ISSUE_TYPES,
			'court_routing'           => Form_Meta::META_COURT_ROUTING,
			'workflow_nodes'          => Form_Meta::META_WORKFLOW_NODES,
			'trigger_events'          => Form_Meta::META_TRIGGER_EVENTS,
			'completion_events'       => Form_Meta::META_COMPLETION_EVENTS,
			'next_steps'              => Form_Meta::META_NEXT_STEPS,
			'required_before'         => Form_Meta::META_REQUIRED_BEFORE,
			'required_after'          => Form_Meta::META_REQUIRED_AFTER,
			'prerequisite_forms'      => Form_Meta::META_PREREQUISITE_FORMS,
			'dependent_forms'         => Form_Meta::META_DEPENDENT_FORMS,
			'related_forms'           => Form_Meta::META_RELATED_FORMS,
			'filing_party'            => Form_Meta::META_FILING_PARTY,
			'served_party'            => Form_Meta::META_SERVED_PARTY,
			'aliases'                 => Form_Meta::META_ALIASES,
			'package_dependencies'    => Form_Meta::META_PACKAGE_DEPS,
			'workflow_dependencies'   => Form_Meta::META_WORKFLOW_DEPS,
		);

		foreach ( $json_map as $key => $meta_key ) {
			if ( array_key_exists( $key, $result ) ) {
				update_post_meta( $post_id, $meta_key, Form_Meta::sanitize_json( $result[ $key ] ) );
			}
		}

		if ( array_key_exists( 'pdf_fillable', $result ) ) {
			update_post_meta( $post_id, Form_Meta::META_PDF_FILLABLE, (bool) $result['pdf_fillable'] );
		}

		if ( array_key_exists( 'pdf_field_count', $result ) ) {
			update_post_meta( $post_id, Form_Meta::META_PDF_FIELD_COUNT, absint( $result['pdf_field_count'] ) );
		}

		if ( array_key_exists( 'detected_county', $result ) && '' !== (string) $result['detected_county'] ) {
			update_post_meta( $post_id, Form_Meta::META_COUNTY, sanitize_text_field( (string) $result['detected_county'] ) );
		}

		$confidence = (int) ( $result['classification_confidence'] ?? 0 );
		$can_assign = $confidence >= 70 && ( $result['supported_court'] ?? true );

		if ( $can_assign ) {
			$taxonomy = new Form_Taxonomy();
			$court    = (string) ( $result['detected_court'] ?? '' );

			if ( '' !== $court && ( $result['supported_court'] ?? true ) ) {
				$court_ids = $taxonomy->ensure_terms( array( $court ), Form_Taxonomy::TAXONOMY_COURT );

				if ( ! empty( $court_ids ) ) {
					wp_set_object_terms( $post_id, $court_ids, Form_Taxonomy::TAXONOMY_COURT );
				}
			}

			$case_type = (string) ( $result['detected_case_type'] ?? '' );

			if ( '' !== $case_type ) {
				$parent = $taxonomy->case_type_parent_for_court( $court, $case_type );
				$term_id = $taxonomy->ensure_child_term( $case_type, $parent, Form_Taxonomy::TAXONOMY_CASE_TYPE );

				if ( $term_id ) {
					wp_set_object_terms( $post_id, array( $term_id ), Form_Taxonomy::TAXONOMY_CASE_TYPE );
				}
			}

			$stage = (string) ( $result['detected_workflow_stage'] ?? '' );

			if ( '' !== $stage ) {
				$parent  = $taxonomy->workflow_parent_for_court( $court );
				$term_id = $taxonomy->ensure_child_term( $stage, $parent, Form_Taxonomy::TAXONOMY_WORKFLOW_STAGE );

				if ( $term_id ) {
					wp_set_object_terms( $post_id, array( $term_id ), Form_Taxonomy::TAXONOMY_WORKFLOW_STAGE );
				}
			}
		}

		$this->append_classification_log( $post_id, $result, 'classified' );

		return true;
	}

	/**
	 * Append an entry to the classification audit log.
	 *
	 * @param int                  $post_id Form post ID.
	 * @param array<string, mixed> $result  Classification result.
	 * @param string               $action  Log action.
	 * @return void
	 */
	private function append_classification_log( int $post_id, array $result, string $action ): void {
		$existing = get_post_meta( $post_id, Form_Meta::META_CLASSIFICATION_LOG, true );
		$log      = array();

		if ( is_string( $existing ) && '' !== $existing ) {
			$decoded = json_decode( $existing, true );
			$log     = is_array( $decoded ) ? $decoded : array();
		}

		$entry = array(
			'timestamp'  => gmdate( 'c' ),
			'action'     => $action,
			'user_id'    => get_current_user_id(),
			'confidence' => (int) ( $result['classification_confidence'] ?? 0 ),
			'court'      => (string) ( $result['detected_court'] ?? '' ),
			'case_type'  => (string) ( $result['detected_case_type'] ?? '' ),
			'stage'      => (string) ( $result['detected_workflow_stage'] ?? '' ),
			'source'     => (string) ( $result['classification_source'] ?? '' ),
		);

		$log[] = $entry;

		// Keep last 50 entries.
		if ( count( $log ) > 50 ) {
			$log = array_slice( $log, -50 );
		}

		update_post_meta( $post_id, Form_Meta::META_CLASSIFICATION_LOG, Form_Meta::sanitize_json( $log ) );
	}

	/**
	 * Get forms flagged for manual review.
	 *
	 * @return \WP_Post[]
	 */
	public function get_forms_needing_review(): array {
		$query = new \WP_Query(
			array(
				'post_type'      => Form_CPT::POST_TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'   => Form_Meta::META_NEEDS_REVIEW,
						'value' => '1',
					),
				),
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);

		return $query->posts;
	}

	/**
	 * Query a single form by meta key/value.
	 *
	 * @param string $meta_key   Meta key.
	 * @param string $meta_value Meta value.
	 * @return \WP_Post|null
	 */
	private function query_by_meta( string $meta_key, string $meta_value ): ?\WP_Post {
		$query = new \WP_Query(
			array(
				'post_type'      => Form_CPT::POST_TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'   => $meta_key,
						'value' => $meta_value,
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
	 * Get forms assigned to a taxonomy term.
	 *
	 * @param string $term_value Term name or slug.
	 * @param string $taxonomy   Taxonomy slug.
	 * @return \WP_Post[]
	 */
	private function get_by_taxonomy_term( string $term_value, string $taxonomy ): array {
		$term_value = sanitize_text_field( $term_value );

		if ( '' === $term_value ) {
			return array();
		}

		$term = get_term_by( 'name', $term_value, $taxonomy );

		if ( ! $term ) {
			$term = get_term_by( 'slug', sanitize_title( $term_value ), $taxonomy );
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
						'taxonomy' => $taxonomy,
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
