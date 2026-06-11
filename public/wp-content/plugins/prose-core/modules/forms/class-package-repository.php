<?php
/**
 * Package data access layer.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Forms;

use ProSe\Core\Forms\Classification\Package_Detector;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Package_Repository
 */
class Package_Repository {

	/**
	 * Find a package by package ID enum.
	 *
	 * @param string $package_id Package enum (e.g. PKG_UNCONTESTED_NO_CHILDREN).
	 * @return \WP_Post|null
	 */
	public function get_by_package_id( string $package_id ): ?\WP_Post {
		$package_id = sanitize_text_field( $package_id );

		if ( '' === $package_id ) {
			return null;
		}

		$query = new \WP_Query(
			array(
				'post_type'      => Package_CPT::POST_TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'   => Package_Meta::META_PACKAGE_ID,
						'value' => $package_id,
					),
				),
			)
		);

		if ( ! $query->have_posts() ) {
			return null;
		}

		return $query->posts[0];
	}

	/**
	 * Get all package posts sharing a package key (all versions).
	 *
	 * @param string $package_key Package key.
	 * @return \WP_Post[]
	 */
	public function get_all_by_key( string $package_key ): array {
		$query = new \WP_Query(
			array(
				'post_type'      => Package_CPT::POST_TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'   => Package_Meta::META_PACKAGE_ID,
						'value' => sanitize_text_field( $package_key ),
					),
				),
				'orderby'        => 'meta_value_num',
				'meta_key'       => Package_Meta::META_PACKAGE_VERSION,
				'order'          => 'DESC',
			)
		);

		return $query->posts;
	}

	/**
	 * Get a specific package version.
	 *
	 * @param string $package_key Package key.
	 * @param int    $version     Version number.
	 * @return \WP_Post|null
	 */
	public function get_by_key_and_version( string $package_key, int $version ): ?\WP_Post {
		$query = new \WP_Query(
			array(
				'post_type'      => Package_CPT::POST_TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					'relation' => 'AND',
					array(
						'key'   => Package_Meta::META_PACKAGE_ID,
						'value' => sanitize_text_field( $package_key ),
					),
					array(
						'key'   => Package_Meta::META_PACKAGE_VERSION,
						'value' => absint( $version ),
					),
				),
			)
		);

		if ( ! $query->have_posts() ) {
			return null;
		}

		return $query->posts[0];
	}

	/**
	 * Get active package for a key.
	 *
	 * @param string $package_key Package key.
	 * @return \WP_Post|null
	 */
	public function get_active_by_key( string $package_key ): ?\WP_Post {
		$query = new \WP_Query(
			array(
				'post_type'      => Package_CPT::POST_TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					'relation' => 'AND',
					array(
						'key'   => Package_Meta::META_PACKAGE_ID,
						'value' => sanitize_text_field( $package_key ),
					),
					array(
						'key'   => Package_Meta::META_PACKAGE_IS_ACTIVE,
						'value' => '1',
					),
				),
			)
		);

		if ( ! $query->have_posts() ) {
			return $this->get_by_package_id( $package_key );
		}

		return $query->posts[0];
	}

	/**
	 * Create or update a package from catalog data.
	 *
	 * @param string               $package_id Package enum.
	 * @param array<string, mixed> $data       Package data.
	 * @return array{post_id: int, created: bool}|\WP_Error
	 */
	public function create_or_update( string $package_id, array $data ) {
		$package_id = sanitize_text_field( $package_id );

		if ( '' === $package_id ) {
			return new \WP_Error( 'prose_missing_package_id', __( 'Package ID is required.', 'prose-core' ) );
		}

		$existing = $this->get_by_package_id( $package_id );
		$version  = isset( $data['package_version'] ) ? (int) $data['package_version'] : 0;

		if ( $version > 0 ) {
			$by_version = $this->get_by_key_and_version( $package_id, $version );

			if ( $by_version ) {
				$existing = $by_version;
			}
		}

		$created  = false;

		$title = (string) ( $data['package_name'] ?? $package_id );

		$post_data = array(
			'post_type'   => Package_CPT::POST_TYPE,
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

		update_post_meta( $post_id, Package_Meta::META_PACKAGE_ID, $package_id );

		$string_map = array(
			'package_name'   => Package_Meta::META_PACKAGE_NAME,
			'court'          => Package_Meta::META_COURT,
			'workflow_id'    => Package_Meta::META_WORKFLOW_ID,
			'workflow_stage' => Package_Meta::META_WORKFLOW_STAGE,
			'next_stage'       => Package_Meta::META_NEXT_STAGE,
			'summary'          => Package_Meta::META_SUMMARY,
		);

		foreach ( $string_map as $key => $meta_key ) {
			if ( array_key_exists( $key, $data ) ) {
				update_post_meta( $post_id, $meta_key, sanitize_text_field( (string) $data[ $key ] ) );
			}
		}

		$bool_map = array(
			'county_specific'  => Package_Meta::META_COUNTY_SPECIFIC,
			'service_required' => Package_Meta::META_SERVICE_REQUIRED,
			'filing_required'  => Package_Meta::META_FILING_REQUIRED,
			'is_active'        => Package_Meta::META_PACKAGE_IS_ACTIVE,
		);

		foreach ( $bool_map as $key => $meta_key ) {
			if ( array_key_exists( $key, $data ) ) {
				update_post_meta( $post_id, $meta_key, (bool) $data[ $key ] );
			}
		}

		$int_map = array(
			'package_version'       => Package_Meta::META_PACKAGE_VERSION,
			'supersedes_package_id' => Package_Meta::META_PACKAGE_SUPERSEDES_ID,
			'replacement_package_id' => Package_Meta::META_PACKAGE_REPLACEMENT_ID,
			'package_order'         => Package_Meta::META_PACKAGE_ORDER,
		);

		foreach ( $int_map as $key => $meta_key ) {
			if ( array_key_exists( $key, $data ) ) {
				update_post_meta( $post_id, $meta_key, absint( $data[ $key ] ) );
			}
		}

		if ( array_key_exists( 'effective_from', $data ) ) {
			update_post_meta( $post_id, Package_Meta::META_PACKAGE_EFFECTIVE_FROM, sanitize_text_field( (string) $data['effective_from'] ) );
		}

		if ( array_key_exists( 'effective_to', $data ) ) {
			update_post_meta( $post_id, Package_Meta::META_PACKAGE_EFFECTIVE_TO, sanitize_text_field( (string) $data['effective_to'] ) );
		}

		$json_map = array(
			'counties'               => Package_Meta::META_COUNTIES,
			'required_forms'         => Package_Meta::META_REQUIRED_FORMS,
			'optional_forms'         => Package_Meta::META_OPTIONAL_FORMS,
			'supporting_documents'   => Package_Meta::META_SUPPORTING_DOCUMENTS,
			'prerequisite_packages'  => Package_Meta::META_PREREQUISITE_PACKAGES,
			'dependent_packages'     => Package_Meta::META_DEPENDENT_PACKAGES,
			'trigger_conditions'     => Package_Meta::META_TRIGGER_CONDITIONS,
			'completion_conditions'  => Package_Meta::META_COMPLETION_CONDITIONS,
			'next_package_ids'       => Package_Meta::META_NEXT_PACKAGE_IDS,
			'estimated_tasks'        => Package_Meta::META_ESTIMATED_TASKS,
			'deadline_rules'         => Package_Meta::META_DEADLINE_RULES,
			'workflow_nodes'         => Package_Meta::META_WORKFLOW_NODES,
		);

		foreach ( $json_map as $key => $meta_key ) {
			if ( array_key_exists( $key, $data ) ) {
				update_post_meta( $post_id, $meta_key, Form_Meta::sanitize_json( $data[ $key ] ) );
			}
		}

		return array(
			'post_id' => (int) $post_id,
			'created' => $created,
		);
	}

	/**
	 * Seed standard packages from vocabulary catalog.
	 *
	 * @return int Number of packages seeded.
	 */
	public function seed_packages(): int {
		$detector = new Package_Detector();
		$catalog  = $detector->get_catalog();
		$count    = 0;

		foreach ( $catalog as $package_id => $data ) {
			$result = $this->create_or_update( $package_id, $data );

			if ( ! is_wp_error( $result ) ) {
				++$count;
			}
		}

		return $count;
	}

	/**
	 * Get packages by package ID enums.
	 *
	 * @param string[] $package_ids Package enum values.
	 * @return \WP_Post[]
	 */
	public function get_by_package_ids( array $package_ids ): array {
		$posts = array();

		foreach ( $package_ids as $package_id ) {
			$post = $this->get_by_package_id( (string) $package_id );

			if ( $post instanceof \WP_Post ) {
				$posts[] = $post;
			}
		}

		return $posts;
	}

	/**
	 * Decode JSON meta value.
	 *
	 * @param int    $post_id  Post ID.
	 * @param string $meta_key Meta key.
	 * @return mixed
	 */
	public function get_json_meta( int $post_id, string $meta_key ) {
		$raw = get_post_meta( $post_id, $meta_key, true );

		if ( is_array( $raw ) ) {
			return $raw;
		}

		if ( ! is_string( $raw ) || '' === $raw ) {
			return array();
		}

		$decoded = json_decode( $raw, true );

		return is_array( $decoded ) ? $decoded : array();
	}
}
