<?php
/**
 * Build Section 2 Package Object from a prose_package post.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Forms;

use ProSe\Core\Forms\Database\Database_Installer;
use ProSe\Core\Forms\Database\Repositories\Package_Form_Repository;
use ProSe\Core\Forms\Database\Repositories\Package_Relation_Repository;

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

		$package = array(
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
			'package_version'        => (int) get_post_meta( $post_id, Package_Meta::META_PACKAGE_VERSION, true ) ?: 1,
			'effective_from'         => (string) get_post_meta( $post_id, Package_Meta::META_PACKAGE_EFFECTIVE_FROM, true ),
			'effective_to'           => (string) get_post_meta( $post_id, Package_Meta::META_PACKAGE_EFFECTIVE_TO, true ),
			'is_active'              => (bool) get_post_meta( $post_id, Package_Meta::META_PACKAGE_IS_ACTIVE, true ),
			'supersedes_package_id'  => (int) get_post_meta( $post_id, Package_Meta::META_PACKAGE_SUPERSEDES_ID, true ),
			'replacement_package_id' => (int) get_post_meta( $post_id, Package_Meta::META_PACKAGE_REPLACEMENT_ID, true ),
			'package_post_id'        => $post_id,
		);

		return $this->enrich_from_graph( $package, $post_id );
	}

	/**
	 * Build package object from package enum ID.
	 *
	 * @param string $package_id Package enum.
	 * @return array<string, mixed>
	 */
	public function build_by_package_id( string $package_id ): array {
		$post = $this->repository->get_active_by_key( $package_id );

		if ( ! $post instanceof \WP_Post ) {
			$post = $this->repository->get_by_package_id( $package_id );
		}

		if ( ! $post instanceof \WP_Post ) {
			return array();
		}

		return $this->build( $post->ID );
	}

	/**
	 * Merge junction-table data when graph layer is available.
	 *
	 * @param array<string, mixed> $package   Base package object.
	 * @param int                  $post_id   Package post ID.
	 * @return array<string, mixed>
	 */
	private function enrich_from_graph( array $package, int $post_id ): array {
		if ( ! Database_Installer::is_ready() ) {
			return $package;
		}

		$forms_repo = new Package_Form_Repository();
		$rels_repo  = new Package_Relation_Repository();
		$pkg_key    = (string) ( $package['package_id'] ?? '' );

		$required = array();
		$optional = array();
		$support  = array();

		foreach ( $forms_repo->get_by_package( $post_id ) as $link ) {
			$code = (string) $link->form_code;

			if ( '' === $code ) {
				continue;
			}

			switch ( (string) $link->requirement ) {
				case 'optional':
					$optional[] = $code;
					break;
				case 'supporting':
					$support[] = $code;
					break;
				default:
					$required[] = $code;
					break;
			}
		}

		if ( ! empty( $required ) ) {
			$package['required_forms'] = array_values( array_unique( array_merge( (array) ( $package['required_forms'] ?? array() ), $required ) ) );
		}

		if ( ! empty( $optional ) ) {
			$package['optional_forms'] = array_values( array_unique( array_merge( (array) ( $package['optional_forms'] ?? array() ), $optional ) ) );
		}

		if ( ! empty( $support ) ) {
			$package['supporting_documents'] = array_values( array_unique( array_merge( (array) ( $package['supporting_documents'] ?? array() ), $support ) ) );
		}

		if ( '' !== $pkg_key ) {
			$next_ids = array();
			$prereqs  = array();

			foreach ( $rels_repo->get_outgoing( $pkg_key ) as $rel ) {
				if ( 'next' === (string) $rel->relation_type ) {
					$next_ids[] = (string) $rel->to_package_key;
				}
			}

			foreach ( $rels_repo->get_prerequisites( $pkg_key ) as $rel ) {
				$prereqs[] = (string) $rel->from_package_key;
			}

			if ( ! empty( $next_ids ) ) {
				$package['next_package_ids'] = array_values( array_unique( array_merge( (array) ( $package['next_package_ids'] ?? array() ), $next_ids ) ) );
			}

			if ( ! empty( $prereqs ) ) {
				$package['prerequisite_packages'] = array_values( array_unique( array_merge( (array) ( $package['prerequisite_packages'] ?? array() ), $prereqs ) ) );
			}
		}

		return $package;
	}
}
