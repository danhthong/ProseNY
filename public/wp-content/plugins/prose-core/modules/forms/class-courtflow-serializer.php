<?php
/**
 * Assemble Section 11 CourtFlow JSON envelope.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Forms;

use ProSe\Core\Forms\Database\Database_Installer;
use ProSe\Core\Forms\Database\Repositories\Deadline_Rule_Repository;
use ProSe\Core\Forms\Database\Repositories\Workflow_Repository;
use ProSe\Core\Forms\Engine\Routing_Engine;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Courtflow_Serializer
 */
final class Courtflow_Serializer {

	/**
	 * Form object builder.
	 *
	 * @var Form_Object_Builder
	 */
	private Form_Object_Builder $form_builder;

	/**
	 * Package object builder.
	 *
	 * @var Package_Object_Builder
	 */
	private Package_Object_Builder $package_builder;

	/**
	 * County rule repository.
	 *
	 * @var County_Rule_Repository
	 */
	private County_Rule_Repository $county_rule_repository;

	/**
	 * Constructor.
	 *
	 * @param Form_Object_Builder    $form_builder             Form builder.
	 * @param Package_Object_Builder $package_builder          Package builder.
	 * @param County_Rule_Repository $county_rule_repository   County rules.
	 */
	public function __construct(
		Form_Object_Builder $form_builder,
		Package_Object_Builder $package_builder,
		County_Rule_Repository $county_rule_repository
	) {
		$this->form_builder             = $form_builder;
		$this->package_builder          = $package_builder;
		$this->county_rule_repository   = $county_rule_repository;
	}

	/**
	 * Serialize full CourtFlow envelope for a form.
	 *
	 * @param int $form_post_id Form post ID.
	 * @return array<string, mixed>
	 */
	public function serialize_form( int $form_post_id ): array {
		$form = $this->form_builder->build( $form_post_id );

		if ( empty( $form ) ) {
			return array();
		}

		$package_ids = is_array( $form['package_ids'] ?? null ) ? $form['package_ids'] : array();
		$package     = array();

		if ( ! empty( $package_ids ) ) {
			$package = $this->package_builder->build_by_package_id( (string) $package_ids[0] );
		}

		$form_code    = (string) ( $form['form_number'] ?? '' );
		$workflow_ids = is_array( $form['workflow_ids'] ?? null ) ? $form['workflow_ids'] : array();
		$county_enum  = '';

		if ( ! empty( $form['counties'] ) && is_array( $form['counties'] ) ) {
			$county_enum = (string) $form['counties'][0];
		}

		$county_rules = array();
		$rule_posts   = $this->county_rule_repository->get_rules_for_form(
			$form_code,
			$package_ids,
			$workflow_ids,
			$county_enum
		);

		foreach ( $rule_posts as $rule_post ) {
			if ( $rule_post instanceof \WP_Post ) {
				$county_rules[] = $this->county_rule_repository->to_rule_object( $rule_post );
			}
		}

		$workflow_nodes = is_array( $form['workflow_nodes'] ?? null ) ? $form['workflow_nodes'] : array();
		$deadline_rules = $this->resolve_deadline_rules( $form, $workflow_ids );
		$workflows      = $this->resolve_workflows( $workflow_ids );

		$workflow_dependencies = array(
			'required_before'        => $form['required_before'] ?? array(),
			'required_after'         => $form['required_after'] ?? array(),
			'prerequisite_forms'     => $form['prerequisite_forms'] ?? array(),
			'dependent_forms'        => $form['dependent_forms'] ?? array(),
			'package_dependencies'   => $this->json_meta( $form_post_id, Form_Meta::META_PACKAGE_DEPS ),
			'workflow_dependencies'  => $this->json_meta( $form_post_id, Form_Meta::META_WORKFLOW_DEPS ),
			'routing_rules'          => is_array( $form['routing_rules'] ?? null ) ? $form['routing_rules'] : array(),
		);

		$ai_summary = $this->json_meta( $form_post_id, Form_Meta::META_AI_SUMMARY_STRUCTURED );

		if ( empty( $ai_summary ) ) {
			$ai_summary = array(
				'what'         => (string) ( $form['user_summary'] ?? '' ),
				'why'          => '',
				'when'         => '',
				'next'         => implode( ', ', (array) ( $form['next_steps'] ?? array() ) ),
				'stage'        => implode( ', ', (array) ( $form['workflow_stages'] ?? array() ) ),
				'court'        => implode( ', ', (array) ( $form['court_routing'] ?? array() ) ),
				'user_summary' => (string) ( $form['user_summary'] ?? '' ),
			);
		}

		return array(
			'form'                  => $form,
			'package'               => $package,
			'county_rules'          => $county_rules,
			'workflows'             => $workflows,
			'workflow_nodes'        => $workflow_nodes,
			'deadline_rules'        => $deadline_rules,
			'workflow_dependencies' => $workflow_dependencies,
			'ai_summary'            => $ai_summary,
		);
	}

	/**
	 * Serialize package only.
	 *
	 * @param int $package_post_id Package post ID.
	 * @return array<string, mixed>
	 */
	public function serialize_package( int $package_post_id ): array {
		return array(
			'package' => $this->package_builder->build( $package_post_id ),
		);
	}

	/**
	 * Resolve deadline rule templates for a form context.
	 *
	 * @param array<string, mixed> $form         Form object.
	 * @param array<int, mixed>    $workflow_ids Workflow keys.
	 * @return array<int, array<string, mixed>>
	 */
	private function resolve_deadline_rules( array $form, array $workflow_ids ): array {
		if ( ! Database_Installer::is_ready() ) {
			return array();
		}

		$repo    = new Deadline_Rule_Repository();
		$rules   = array();
		$seen    = array();
		$events  = is_array( $form['trigger_events'] ?? null ) ? $form['trigger_events'] : array();
		$node_ids = is_array( $form['workflow_node_ids'] ?? null ) ? $form['workflow_node_ids'] : array();

		foreach ( $events as $event ) {
			foreach ( $workflow_ids as $workflow_key ) {
				foreach ( $repo->get_by_trigger( (string) $event, (string) $workflow_key ) as $row ) {
					$key = (int) $row->deadline_id;

					if ( isset( $seen[ $key ] ) ) {
						continue;
					}

					$seen[ $key ] = true;
					$rules[]      = $repo->to_array( $row );
				}
			}

			foreach ( $repo->get_by_trigger( (string) $event ) as $row ) {
				$key = (int) $row->deadline_id;

				if ( isset( $seen[ $key ] ) ) {
					continue;
				}

				$seen[ $key ] = true;
				$rules[]      = $repo->to_array( $row );
			}
		}

		foreach ( $node_ids as $node_id ) {
			foreach ( $repo->get_by_node( (int) $node_id ) as $row ) {
				$key = (int) $row->deadline_id;

				if ( isset( $seen[ $key ] ) ) {
					continue;
				}

				$seen[ $key ] = true;
				$rules[]      = $repo->to_array( $row );
			}
		}

		return $rules;
	}

	/**
	 * Resolve workflow catalog entries.
	 *
	 * @param array<int, mixed> $workflow_ids Workflow keys.
	 * @return array<int, array<string, mixed>>
	 */
	private function resolve_workflows( array $workflow_ids ): array {
		if ( ! Database_Installer::is_ready() || empty( $workflow_ids ) ) {
			return array();
		}

		$repo     = new Workflow_Repository();
		$routing  = new Routing_Engine();
		$result   = array();

		foreach ( $workflow_ids as $workflow_key ) {
			$row = $repo->get_by_key( (string) $workflow_key );

			if ( ! $row ) {
				continue;
			}

			$result[] = array(
				'workflow_key'  => (string) $row->workflow_key,
				'workflow_name' => (string) $row->workflow_name,
				'court_routing' => (string) $row->court_routing,
				'description'   => (string) $row->description,
				'routes'        => $routing->route_for_workflow( (string) $row->workflow_key ),
			);
		}

		return $result;
	}

	/**
	 * Decode JSON meta from form post.
	 *
	 * @param int    $post_id  Post ID.
	 * @param string $meta_key Meta key.
	 * @return array<int|string, mixed>
	 */
	private function json_meta( int $post_id, string $meta_key ): array {
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
