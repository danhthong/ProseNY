<?php
/**
 * Package Engine — resolve package state and transitions.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Forms\Engine;

use ProSe\Core\Forms\Database\Repositories\Node_Package_Repository;
use ProSe\Core\Forms\Database\Repositories\Package_Form_Repository;
use ProSe\Core\Forms\Database\Repositories\Package_Relation_Repository;
use ProSe\Core\Forms\Package_CPT;
use ProSe\Core\Forms\Package_Meta;
use ProSe\Core\Forms\Package_Repository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Package_Engine
 */
final class Package_Engine {

	public const STATE_LOCKED      = 'LOCKED';
	public const STATE_AVAILABLE     = 'AVAILABLE';
	public const STATE_IN_PROGRESS   = 'IN_PROGRESS';
	public const STATE_COMPLETE      = 'COMPLETE';
	public const STATE_SKIPPED       = 'SKIPPED';

	/**
	 * Condition evaluator.
	 *
	 * @var Condition_Evaluator
	 */
	private Condition_Evaluator $evaluator;

	/**
	 * Package repository.
	 *
	 * @var Package_Repository
	 */
	private Package_Repository $packages;

	/**
	 * Package relation repository.
	 *
	 * @var Package_Relation_Repository
	 */
	private Package_Relation_Repository $relations;

	/**
	 * Package form repository.
	 *
	 * @var Package_Form_Repository
	 */
	private Package_Form_Repository $package_forms;

	/**
	 * Node package repository.
	 *
	 * @var Node_Package_Repository
	 */
	private Node_Package_Repository $node_packages;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->evaluator       = new Condition_Evaluator();
		$this->packages        = new Package_Repository();
		$this->relations       = new Package_Relation_Repository();
		$this->package_forms   = new Package_Form_Repository();
		$this->node_packages   = new Node_Package_Repository();
	}

	/**
	 * Resolve package post for a logical key and context.
	 *
	 * @param string               $package_key Package key.
	 * @param array<string, mixed> $ctx         Context (case_id, pinned, as_of, mode).
	 * @return \WP_Post|null
	 */
	public function resolve_package( string $package_key, array $ctx = array() ): ?\WP_Post {
		$pinned = $ctx['pinned_packages'][ $package_key ] ?? null;

		if ( $pinned ) {
			$post = get_post( (int) $pinned );

			return ( $post instanceof \WP_Post && Package_CPT::POST_TYPE === $post->post_type ) ? $post : null;
		}

		$posts = $this->packages->get_all_by_key( $package_key );

		if ( empty( $posts ) ) {
			$post = $this->packages->get_by_package_id( $package_key );

			return $post;
		}

		foreach ( $posts as $post ) {
			if ( (bool) get_post_meta( $post->ID, Package_Meta::META_PACKAGE_IS_ACTIVE, true ) ) {
				return $post;
			}
		}

		return $posts[0] ?? null;
	}

	/**
	 * Resolve package state.
	 *
	 * @param string               $package_key Package key.
	 * @param array<string, mixed> $ctx         Case context.
	 * @return string
	 */
	public function resolve_state( string $package_key, array $ctx ): string {
		$explicit = $ctx['package_states'][ $package_key ] ?? null;

		if ( is_string( $explicit ) && in_array( $explicit, array( self::STATE_COMPLETE, self::STATE_SKIPPED, self::STATE_IN_PROGRESS ), true ) ) {
			return $explicit;
		}

		$post = $this->resolve_package( $package_key, $ctx );

		if ( ! $post ) {
			return self::STATE_LOCKED;
		}

		$trigger_raw = get_post_meta( $post->ID, Package_Meta::META_TRIGGER_CONDITIONS, true );
		$trigger     = is_string( $trigger_raw ) ? json_decode( $trigger_raw, true ) : $trigger_raw;
		$trigger     = is_array( $trigger ) ? $trigger : array();

		$prereqs = $this->relations->get_prerequisites( $package_key );

		foreach ( $prereqs as $rel ) {
			$pre_state = $this->resolve_state( (string) $rel->from_package_key, $ctx );

			if ( self::STATE_COMPLETE !== $pre_state ) {
				return self::STATE_LOCKED;
			}
		}

		if ( ! empty( $trigger ) && ! $this->evaluator->evaluate( $trigger, $ctx ) ) {
			return self::STATE_LOCKED;
		}

		$completion_raw = get_post_meta( $post->ID, Package_Meta::META_COMPLETION_CONDITIONS, true );
		$completion     = is_string( $completion_raw ) ? json_decode( $completion_raw, true ) : $completion_raw;
		$completion     = is_array( $completion ) ? $completion : array();

		if ( ! empty( $completion ) && $this->evaluator->evaluate( $completion, $ctx ) ) {
			return self::STATE_COMPLETE;
		}

		if ( self::STATE_IN_PROGRESS === $explicit ) {
			return self::STATE_IN_PROGRESS;
		}

		return self::STATE_AVAILABLE;
	}

	/**
	 * Current available/in-progress packages for a workflow.
	 *
	 * @param string               $workflow_key Workflow key.
	 * @param array<string, mixed> $ctx          Case context.
	 * @return array<int, array<string, mixed>>
	 */
	public function current_packages( string $workflow_key, array $ctx ): array {
		$posts = get_posts(
			array(
				'post_type'      => Package_CPT::POST_TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'   => Package_Meta::META_WORKFLOW_ID,
						'value' => sanitize_text_field( $workflow_key ),
					),
					array(
						'key'   => Package_Meta::META_PACKAGE_IS_ACTIVE,
						'value' => '1',
					),
				),
			)
		);

		$result = array();

		foreach ( $posts as $post ) {
			if ( ! $post instanceof \WP_Post ) {
				continue;
			}

			$key   = (string) get_post_meta( $post->ID, Package_Meta::META_PACKAGE_ID, true );
			$state = $this->resolve_state( $key, $ctx );

			if ( in_array( $state, array( self::STATE_AVAILABLE, self::STATE_IN_PROGRESS ), true ) ) {
				$result[] = array(
					'package_key'  => $key,
					'package_id'   => $post->ID,
					'package_name' => $post->post_title,
					'state'        => $state,
				);
			}
		}

		return $result;
	}

	/**
	 * Next packages from current package key.
	 *
	 * @param string               $package_key Package key.
	 * @param array<string, mixed> $ctx         Case context.
	 * @return array<int, array<string, mixed>>
	 */
	public function next_packages( string $package_key, array $ctx ): array {
		$relations = $this->relations->get_outgoing( $package_key, 'next' );
		$result    = array();

		foreach ( $relations as $rel ) {
			$to_key = (string) $rel->to_package_key;
			$cond   = is_string( $rel->condition_data ) ? json_decode( $rel->condition_data, true ) : array();

			if ( ! empty( $cond ) && ! $this->evaluator->evaluate( (array) $cond, $ctx ) ) {
				continue;
			}

			$result[] = array(
				'package_key' => $to_key,
				'state'       => $this->resolve_state( $to_key, $ctx ),
			);
		}

		return $result;
	}

	/**
	 * Forms for a resolved package.
	 *
	 * @param int                  $package_id Package post ID.
	 * @param array<string, mixed> $ctx        Case context (answers for condition_key).
	 * @return array<int, array<string, mixed>>
	 */
	public function package_forms( int $package_id, array $ctx = array() ): array {
		$rows   = $this->package_forms->get_by_package( $package_id );
		$result = array();

		foreach ( $rows as $row ) {
			$condition_key = (string) $row->condition_key;

			if ( '' !== $condition_key ) {
				$answers = is_array( $ctx['answers'] ?? null ) ? $ctx['answers'] : array();

				if ( empty( $answers[ $condition_key ] ) ) {
					continue;
				}
			}

			$result[] = array(
				'form_code'   => (string) $row->form_code,
				'form_id'     => $row->form_id ? (int) $row->form_id : null,
				'requirement' => (string) $row->requirement,
			);
		}

		return $result;
	}

	/**
	 * Packages satisfying a node.
	 *
	 * @param int $node_id Node ID.
	 * @return object[]
	 */
	public function packages_for_node( int $node_id ): array {
		return $this->node_packages->get_by_node( $node_id );
	}
}
