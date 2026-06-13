<?php
/**
 * Build Section 1 Form Object from a prose_form post.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Forms;

use ProSe\Core\Forms\Classification\Vocabulary;
use ProSe\Core\Forms\Classification\Workflow_Node_Mapper;
use ProSe\Core\Forms\Database\Database_Installer;
use ProSe\Core\Forms\Database\Repositories\Edge_Repository;
use ProSe\Core\Forms\Database\Repositories\Node_Repository;
use ProSe\Core\Forms\Database\Repositories\Package_Form_Repository;
use ProSe\Core\Forms\Engine\Routing_Engine;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Form_Object_Builder
 */
final class Form_Object_Builder {

	/**
	 * Build form object from post ID.
	 *
	 * @param int $post_id Form post ID.
	 * @return array<string, mixed>
	 */
	public function build( int $post_id ): array {
		$post = get_post( $post_id );

		if ( ! $post || Form_CPT::POST_TYPE !== $post->post_type ) {
			return array();
		}

		$form_code = (string) get_post_meta( $post_id, Form_Meta::META_FORM_CODE, true );

		if ( '' === $form_code ) {
			$form_code = (string) get_post_meta( $post_id, Form_Meta::META_FORM_ID, true );
		}

		$county_label = (string) get_post_meta( $post_id, Form_Meta::META_COUNTY, true );
		$county_enum  = Vocabulary::county_to_enum( $county_label );

		$counties = array();

		if ( '' !== $county_enum ) {
			$counties[] = $county_enum;
		}

		$court_terms = wp_get_post_terms( $post_id, Form_Taxonomy::TAXONOMY_COURT, array( 'fields' => 'names' ) );
		$court       = ( ! is_wp_error( $court_terms ) && ! empty( $court_terms ) ) ? (string) $court_terms[0] : (string) get_post_meta( $post_id, Form_Meta::META_DETECTED_COURT, true );

		$confidence = (int) get_post_meta( $post_id, Form_Meta::META_CONFIDENCE_SCORE, true );

		if ( 0 === $confidence ) {
			$confidence = (int) get_post_meta( $post_id, Form_Meta::META_CLASSIFICATION_CONFIDENCE, true );
		}

		$form = array(
			'form_id'            => 'form_' . sanitize_title( $form_code ?: (string) $post_id ),
			'form_number'        => $form_code,
			'title'              => $post->post_title,
			'aliases'            => $this->json_array( $post_id, Form_Meta::META_ALIASES ),
			'court'              => $court,
			'court_division'     => $court,
			'workflow_ids'       => $this->json_array( $post_id, Form_Meta::META_WORKFLOW_IDS ),
			'package_ids'        => $this->json_array( $post_id, Form_Meta::META_PACKAGE_IDS ),
			'workflow_stages'    => $this->json_array( $post_id, Form_Meta::META_WORKFLOW_STAGES ),
			'issue_types'        => $this->json_array( $post_id, Form_Meta::META_ISSUE_TYPES ),
			'document_type'      => (string) get_post_meta( $post_id, Form_Meta::META_DOCUMENT_TYPE, true ),
			'filing_party'       => $this->json_array( $post_id, Form_Meta::META_FILING_PARTY ),
			'served_party'       => $this->json_array( $post_id, Form_Meta::META_SERVED_PARTY ),
			'county_specific'    => '' !== $county_enum,
			'counties'           => $counties,
			'required_before'    => $this->json_array( $post_id, Form_Meta::META_REQUIRED_BEFORE ),
			'required_after'     => $this->json_array( $post_id, Form_Meta::META_REQUIRED_AFTER ),
			'related_forms'      => $this->json_array( $post_id, Form_Meta::META_RELATED_FORMS ),
			'prerequisite_forms' => $this->json_array( $post_id, Form_Meta::META_PREREQUISITE_FORMS ),
			'dependent_forms'    => $this->json_array( $post_id, Form_Meta::META_DEPENDENT_FORMS ),
			'trigger_events'     => $this->json_array( $post_id, Form_Meta::META_TRIGGER_EVENTS ),
			'completion_events'  => $this->json_array( $post_id, Form_Meta::META_COMPLETION_EVENTS ),
			'next_steps'         => $this->json_array( $post_id, Form_Meta::META_NEXT_STEPS ),
			'workflow_nodes'     => $this->json_array( $post_id, Form_Meta::META_WORKFLOW_NODES ),
			'workflow_node_ids'  => $this->json_array( $post_id, Form_Meta::META_WORKFLOW_NODE_IDS ),
			'court_routing'      => $this->json_array( $post_id, Form_Meta::META_COURT_ROUTING ),
			'official_url'       => (string) get_post_meta( $post_id, Form_Meta::META_OFFICIAL_URL, true ),
			'official_pdf_url'   => (string) ( get_post_meta( $post_id, Form_Meta::META_SOURCE_PDF_URL, true ) ?: get_post_meta( $post_id, Form_Meta::META_FILE_URL, true ) ),
			'source_files'       => $this->source_files_array( $post_id ),
			'description'        => (string) get_post_meta( $post_id, Form_Meta::META_DESCRIPTION, true ),
			'user_summary'       => (string) get_post_meta( $post_id, Form_Meta::META_USER_SUMMARY, true ),
			'confidence_score'   => $confidence,
		);

		return $this->enrich_from_graph( $form, $post_id, $form_code, $county_enum );
	}

	/**
	 * Enrich form object from graph tables (authoritative) with mapper fallback.
	 *
	 * @param array<string, mixed> $form       Base form object.
	 * @param int                  $post_id    Form post ID.
	 * @param string               $form_code  Form code.
	 * @param string               $county_enum County enum.
	 * @return array<string, mixed>
	 */
	private function enrich_from_graph( array $form, int $post_id, string $form_code, string $county_enum ): array {
		if ( ! Database_Installer::is_ready() ) {
			return $this->fallback_mapper_enrichment( $form, $form_code, $post_id );
		}

		$nodes_repo = new Node_Repository();
		$edges_repo = new Edge_Repository();
		$pkg_forms  = new Package_Form_Repository();
		$routing    = new Routing_Engine();

		$node_keys = is_array( $form['workflow_nodes'] ?? null ) ? $form['workflow_nodes'] : array();

		if ( empty( $node_keys ) && '' !== $form_code ) {
			$mapper = new Workflow_Node_Mapper();
			$mapped = $mapper->map(
				array(
					'form_code'      => $form_code,
					'workflow_stage' => implode( ', ', (array) ( $form['workflow_stages'] ?? array() ) ),
					'title'          => (string) ( $form['title'] ?? '' ),
				)
			);
			$node_keys = is_array( $mapped['workflow_nodes'] ?? null ) ? $mapped['workflow_nodes'] : array();
		}

		$resolved_nodes = array();
		$node_ids       = array();
		$next_keys      = array();

		foreach ( $node_keys as $node_key ) {
			$row = $nodes_repo->get_by_key( (string) $node_key );

			if ( ! $row ) {
				$resolved_nodes[] = array( 'node_key' => (string) $node_key );
				continue;
			}

			$node_ids[]       = (int) $row->node_id;
			$resolved_nodes[] = $nodes_repo->to_array( $row );

			foreach ( $edges_repo->get_outgoing( (int) $row->node_id ) as $edge ) {
				$to = $nodes_repo->get_by_id( (int) $edge->to_node_id );

				if ( $to ) {
					$next_keys[] = (string) $to->node_key;
				}
			}
		}

		if ( ! empty( $resolved_nodes ) ) {
			$form['workflow_nodes']    = $resolved_nodes;
			$form['workflow_node_ids'] = array_values( array_unique( $node_ids ) );
		}

		if ( ! empty( $next_keys ) ) {
			$form['next_steps'] = array_values( array_unique( array_merge( (array) ( $form['next_steps'] ?? array() ), $next_keys ) ) );
		}

		if ( '' !== $form_code ) {
			$package_keys = array();

			foreach ( $pkg_forms->get_by_form_code( $form_code ) as $link ) {
				$package_post = get_post( (int) $link->package_id );

				if ( ! $package_post ) {
					continue;
				}

				$key = (string) get_post_meta( $package_post->ID, Package_Meta::META_PACKAGE_ID, true );

				if ( '' !== $key ) {
					$package_keys[] = $key;
				}
			}

			if ( ! empty( $package_keys ) ) {
				$form['package_ids'] = array_values( array_unique( array_merge( (array) ( $form['package_ids'] ?? array() ), $package_keys ) ) );
			}
		}

		$workflow_ids = is_array( $form['workflow_ids'] ?? null ) ? $form['workflow_ids'] : array();
		$court_routes = is_array( $form['court_routing'] ?? null ) ? $form['court_routing'] : array();

		foreach ( $workflow_ids as $workflow_key ) {
			$court_routes = array_merge( $court_routes, $routing->route_for_workflow( (string) $workflow_key, $county_enum ) );
		}

		if ( ! empty( $court_routes ) ) {
			$form['court_routing'] = array_values( array_unique( $court_routes ) );
		}

		$form['routing_rules'] = '' !== $form_code ? $routing->rules_for_form( $form_code, $county_enum ) : array();

		return $form;
	}

	/**
	 * Mapper-only enrichment when graph tables are unavailable.
	 *
	 * @param array<string, mixed> $form      Form object.
	 * @param string               $form_code Form code.
	 * @param int                  $post_id   Post ID.
	 * @return array<string, mixed>
	 */
	private function fallback_mapper_enrichment( array $form, string $form_code, int $post_id ): array {
		if ( empty( $form['workflow_nodes'] ) && '' !== $form_code ) {
			$mapper = new Workflow_Node_Mapper();
			$mapped = $mapper->map(
				array(
					'form_code'      => $form_code,
					'workflow_stage' => implode( ', ', (array) ( $form['workflow_stages'] ?? array() ) ),
					'title'          => (string) ( $form['title'] ?? '' ),
				)
			);

			if ( ! empty( $mapped['workflow_nodes'] ) ) {
				$form['workflow_nodes'] = $mapped['workflow_nodes'];
			}

			if ( ! empty( $mapped['next_steps'] ) ) {
				$form['next_steps'] = array_values( array_unique( array_merge( (array) ( $form['next_steps'] ?? array() ), $mapped['next_steps'] ) ) );
			}
		}

		unset( $post_id );

		return $form;
	}

	/**
	 * Decode JSON array meta.
	 *
	 * @param int    $post_id  Post ID.
	 * @param string $meta_key Meta key.
	 * @return array<int, mixed>
	 */
	private function json_array( int $post_id, string $meta_key ): array {
		$raw = get_post_meta( $post_id, $meta_key, true );

		if ( is_array( $raw ) ) {
			return array_values( $raw );
		}

		if ( ! is_string( $raw ) || '' === $raw ) {
			return array();
		}

		$decoded = json_decode( $raw, true );

		return is_array( $decoded ) ? array_values( $decoded ) : array();
	}

	/**
	 * Load court source file entries from post meta.
	 *
	 * @param int $post_id Post ID.
	 * @return array<int, array<string, mixed>>
	 */
	private function source_files_array( int $post_id ): array {
		$raw = get_post_meta( $post_id, Form_Meta::META_SOURCE_FILES, true );

		if ( is_string( $raw ) && '' !== trim( $raw ) ) {
			$decoded = json_decode( $raw, true );
		} elseif ( is_array( $raw ) ) {
			$decoded = $raw;
		} else {
			return array();
		}

		if ( ! is_array( $decoded ) || empty( $decoded['files'] ) || ! is_array( $decoded['files'] ) ) {
			return array();
		}

		return array_values( $decoded['files'] );
	}
}
