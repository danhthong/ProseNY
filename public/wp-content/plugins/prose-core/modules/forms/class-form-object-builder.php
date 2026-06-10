<?php
/**
 * Build Section 1 Form Object from a prose_form post.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Forms;

use ProSe\Core\Forms\Classification\Vocabulary;

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

		return array(
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
			'court_routing'      => $this->json_array( $post_id, Form_Meta::META_COURT_ROUTING ),
			'official_url'       => (string) get_post_meta( $post_id, Form_Meta::META_OFFICIAL_URL, true ),
			'official_pdf_url'   => (string) ( get_post_meta( $post_id, Form_Meta::META_SOURCE_PDF_URL, true ) ?: get_post_meta( $post_id, Form_Meta::META_FILE_URL, true ) ),
			'description'        => (string) get_post_meta( $post_id, Form_Meta::META_DESCRIPTION, true ),
			'user_summary'         => (string) get_post_meta( $post_id, Form_Meta::META_USER_SUMMARY, true ),
			'confidence_score'   => $confidence,
		);
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
}
