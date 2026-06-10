<?php
/**
 * Build Section 2 Package Object from a prose_package post.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Forms;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Package_Object_Builder
 */
final class Package_Object_Builder {

	/**
	 * Package repository.
	 *
	 * @var Package_Repository
	 */
	private Package_Repository $repository;

	/**
	 * Constructor.
	 *
	 * @param Package_Repository $repository Repository.
	 */
	public function __construct( Package_Repository $repository ) {
		$this->repository = $repository;
	}

	/**
	 * Build package object from post ID.
	 *
	 * @param int $post_id Package post ID.
	 * @return array<string, mixed>
	 */
	public function build( int $post_id ): array {
		$post = get_post( $post_id );

		if ( ! $post || Package_CPT::POST_TYPE !== $post->post_type ) {
			return array();
		}

		return array(
			'package_id'             => (string) get_post_meta( $post_id, Package_Meta::META_PACKAGE_ID, true ),
			'package_name'           => (string) ( get_post_meta( $post_id, Package_Meta::META_PACKAGE_NAME, true ) ?: $post->post_title ),
			'court'                  => (string) get_post_meta( $post_id, Package_Meta::META_COURT, true ),
			'workflow_id'            => (string) get_post_meta( $post_id, Package_Meta::META_WORKFLOW_ID, true ),
			'workflow_stage'         => (string) get_post_meta( $post_id, Package_Meta::META_WORKFLOW_STAGE, true ),
			'county_specific'        => (bool) get_post_meta( $post_id, Package_Meta::META_COUNTY_SPECIFIC, true ),
			'counties'               => $this->repository->get_json_meta( $post_id, Package_Meta::META_COUNTIES ),
			'required_forms'         => $this->repository->get_json_meta( $post_id, Package_Meta::META_REQUIRED_FORMS ),
			'optional_forms'         => $this->repository->get_json_meta( $post_id, Package_Meta::META_OPTIONAL_FORMS ),
			'supporting_documents'   => $this->repository->get_json_meta( $post_id, Package_Meta::META_SUPPORTING_DOCUMENTS ),
			'prerequisite_packages'  => $this->repository->get_json_meta( $post_id, Package_Meta::META_PREREQUISITE_PACKAGES ),
			'dependent_packages'     => $this->repository->get_json_meta( $post_id, Package_Meta::META_DEPENDENT_PACKAGES ),
			'trigger_conditions'     => $this->repository->get_json_meta( $post_id, Package_Meta::META_TRIGGER_CONDITIONS ),
			'completion_conditions'  => $this->repository->get_json_meta( $post_id, Package_Meta::META_COMPLETION_CONDITIONS ),
			'next_package_ids'       => $this->repository->get_json_meta( $post_id, Package_Meta::META_NEXT_PACKAGE_IDS ),
			'next_stage'             => (string) get_post_meta( $post_id, Package_Meta::META_NEXT_STAGE, true ),
			'service_required'       => (bool) get_post_meta( $post_id, Package_Meta::META_SERVICE_REQUIRED, true ),
			'filing_required'        => (bool) get_post_meta( $post_id, Package_Meta::META_FILING_REQUIRED, true ),
			'estimated_tasks'        => $this->repository->get_json_meta( $post_id, Package_Meta::META_ESTIMATED_TASKS ),
			'deadline_rules'         => $this->repository->get_json_meta( $post_id, Package_Meta::META_DEADLINE_RULES ),
			'workflow_nodes'         => $this->repository->get_json_meta( $post_id, Package_Meta::META_WORKFLOW_NODES ),
			'summary'                => (string) get_post_meta( $post_id, Package_Meta::META_SUMMARY, true ),
		);
	}

	/**
	 * Build package object from package enum ID.
	 *
	 * @param string $package_id Package enum.
	 * @return array<string, mixed>
	 */
	public function build_by_package_id( string $package_id ): array {
		$post = $this->repository->get_by_package_id( $package_id );

		if ( ! $post instanceof \WP_Post ) {
			return array();
		}

		return $this->build( $post->ID );
	}
}
