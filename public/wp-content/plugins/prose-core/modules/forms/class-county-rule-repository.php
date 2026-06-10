<?php
/**
 * County rule data access layer.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Forms;

use ProSe\Core\Forms\Classification\Vocabulary;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class County_Rule_Repository
 */
class County_Rule_Repository {

	/**
	 * Create or update a county rule.
	 *
	 * @param array<string, mixed> $data Rule data.
	 * @return array{post_id: int, created: bool}|\WP_Error
	 */
	public function create_or_update( array $data ) {
		$title = isset( $data['title'] ) ? sanitize_text_field( (string) $data['title'] ) : '';

		if ( '' === $title ) {
			return new \WP_Error( 'prose_missing_title', __( 'County rule title is required.', 'prose-core' ) );
		}

		$existing = null;

		if ( ! empty( $data['post_id'] ) ) {
			$existing = get_post( (int) $data['post_id'] );
		}

		$created = false;

		$post_data = array(
			'post_type'   => County_Rule_CPT::POST_TYPE,
			'post_status' => 'publish',
			'post_title'  => $title,
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

		$string_map = array(
			'county'      => County_Rule_Meta::META_COUNTY,
			'rule_type'   => County_Rule_Meta::META_RULE_TYPE,
			'applies_to'  => County_Rule_Meta::META_APPLIES_TO,
			'applies_ref' => County_Rule_Meta::META_APPLIES_REF,
			'description' => County_Rule_Meta::META_DESCRIPTION,
		);

		foreach ( $string_map as $key => $meta_key ) {
			if ( ! array_key_exists( $key, $data ) ) {
				continue;
			}

			$value = (string) $data[ $key ];

			if ( 'description' === $key ) {
				update_post_meta( $post_id, $meta_key, sanitize_textarea_field( $value ) );
			} else {
				update_post_meta( $post_id, $meta_key, sanitize_text_field( $value ) );
			}
		}

		return array(
			'post_id' => (int) $post_id,
			'created' => $created,
		);
	}

	/**
	 * Find county rules applicable to a form.
	 *
	 * @param string   $form_code   Form code.
	 * @param string[] $package_ids Package enum IDs.
	 * @param string[] $workflow_ids Workflow enum IDs.
	 * @param string   $county_enum County enum (optional filter).
	 * @return \WP_Post[]
	 */
	public function get_rules_for_form( string $form_code, array $package_ids = array(), array $workflow_ids = array(), string $county_enum = '' ): array {
		$query_args = array(
			'post_type'      => County_Rule_CPT::POST_TYPE,
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'orderby'        => 'title',
			'order'          => 'ASC',
		);

		if ( '' !== $county_enum ) {
			$query_args['meta_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				array(
					'key'   => County_Rule_Meta::META_COUNTY,
					'value' => sanitize_text_field( $county_enum ),
				),
			);
		}

		$query = new \WP_Query( $query_args );
		$posts = $query->posts;
		$matched = array();

		foreach ( $posts as $post ) {
			if ( ! $post instanceof \WP_Post ) {
				continue;
			}

			$applies_to  = (string) get_post_meta( $post->ID, County_Rule_Meta::META_APPLIES_TO, true );
			$applies_ref = (string) get_post_meta( $post->ID, County_Rule_Meta::META_APPLIES_REF, true );

			if ( County_Rule_Meta::SCOPE_GLOBAL === $applies_to ) {
				$matched[] = $post;
				continue;
			}

			if ( County_Rule_Meta::SCOPE_FORM === $applies_to && $applies_ref === $form_code ) {
				$matched[] = $post;
				continue;
			}

			if ( County_Rule_Meta::SCOPE_PACKAGE === $applies_to && in_array( $applies_ref, $package_ids, true ) ) {
				$matched[] = $post;
				continue;
			}

			if ( County_Rule_Meta::SCOPE_WORKFLOW === $applies_to && in_array( $applies_ref, $workflow_ids, true ) ) {
				$matched[] = $post;
			}
		}

		return $matched;
	}

	/**
	 * Build county rule object array from a post.
	 *
	 * @param \WP_Post $post County rule post.
	 * @return array<string, mixed>
	 */
	public function to_rule_object( \WP_Post $post ): array {
		return array(
			'county'      => (string) get_post_meta( $post->ID, County_Rule_Meta::META_COUNTY, true ),
			'rule_type'   => (string) get_post_meta( $post->ID, County_Rule_Meta::META_RULE_TYPE, true ),
			'applies_to'  => (string) get_post_meta( $post->ID, County_Rule_Meta::META_APPLIES_TO, true ),
			'applies_ref' => (string) get_post_meta( $post->ID, County_Rule_Meta::META_APPLIES_REF, true ),
			'description' => (string) get_post_meta( $post->ID, County_Rule_Meta::META_DESCRIPTION, true ),
			'title'       => $post->post_title,
		);
	}

	/**
	 * Seed placeholder county rules (optional starter data).
	 *
	 * @return int Number of rules seeded.
	 */
	public function seed_county_rules(): int {
		$counties = array(
			Vocabulary::COUNTY_NEW_YORK => __( 'New York County', 'prose-core' ),
			Vocabulary::COUNTY_KINGS    => __( 'Kings County', 'prose-core' ),
			Vocabulary::COUNTY_QUEENS   => __( 'Queens County', 'prose-core' ),
			Vocabulary::COUNTY_BRONX    => __( 'Bronx County', 'prose-core' ),
			Vocabulary::COUNTY_RICHMOND => __( 'Richmond County', 'prose-core' ),
		);

		$count = 0;

		foreach ( $counties as $county_enum => $label ) {
			$title = sprintf(
				/* translators: %s: county name */
				__( '%s — Matrimonial e-Filing', 'prose-core' ),
				$label
			);

			$existing = get_page_by_title( $title, OBJECT, County_Rule_CPT::POST_TYPE );

			if ( $existing instanceof \WP_Post ) {
				continue;
			}

			$result = $this->create_or_update(
				array(
					'title'       => $title,
					'county'      => $county_enum,
					'rule_type'   => County_Rule_Meta::TYPE_EFILING,
					'applies_to'  => County_Rule_Meta::SCOPE_WORKFLOW,
					'applies_ref' => Vocabulary::WF_UNCONTESTED_DIVORCE,
					'description' => sprintf(
						/* translators: %s: county name */
						__( 'County-specific e-filing requirements may apply for matrimonial filings in %s. Verify current local rules before filing.', 'prose-core' ),
						$label
					),
				)
			);

			if ( ! is_wp_error( $result ) ) {
				++$count;
			}
		}

		return $count;
	}
}
