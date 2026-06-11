<?php
/**
 * Form post meta registration.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Forms;

use ProSe\Core\Loader;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Form_Meta
 */
class Form_Meta {

	// Core.
	public const META_FORM_CODE        = 'prose_form_code';
	public const META_FORM_ID          = 'prose_form_id';
	public const META_COUNTY           = 'prose_county';
	public const META_WORKFLOW_KEY     = 'prose_workflow_key';
	public const META_WORKFLOW_ORDER   = 'prose_workflow_order';
	public const META_PACKET_GROUP     = 'prose_packet_group';
	public const META_REQUIRED         = 'prose_required';
	public const META_DEPENDENCIES     = 'prose_dependencies';
	public const META_CONDITIONS       = 'prose_conditions';

	// PDF storage.
	public const META_FILE_NAME        = 'prose_file_name';
	public const META_FILE_URL         = 'prose_file_url';
	public const META_SOURCE_PDF_URL   = 'prose_source_pdf_url';

	// PDF analysis.
	public const META_PDF_FILLABLE     = 'prose_pdf_fillable';
	public const META_PDF_FIELD_COUNT  = 'prose_pdf_field_count';
	public const META_PDF_FIELDS_JSON  = 'prose_pdf_fields_json';
	public const META_PDF_ANALYZED_AT  = 'prose_pdf_analyzed_at';

	// Classification (Form Intelligence Engine).
	public const META_SUPPORTED_COURT            = 'prose_supported_court';
	public const META_DETECTED_COURT             = 'prose_detected_court';
	public const META_DETECTED_COUNTY            = 'prose_detected_county';
	public const META_DETECTED_CASE_TYPE         = 'prose_detected_case_type';
	public const META_DETECTED_WORKFLOW_STAGE    = 'prose_detected_workflow_stage';
	public const META_CLASSIFICATION_CONFIDENCE  = 'prose_classification_confidence';
	public const META_CLASSIFICATION_SOURCE      = 'prose_classification_source';
	public const META_CLASSIFICATION_SIGNALS     = 'prose_classification_signals';
	public const META_CLASSIFICATION_WARNING     = 'prose_classification_warning';
	public const META_NEEDS_REVIEW               = 'prose_needs_review';
	public const META_MANUAL_OVERRIDE            = 'prose_manual_override';
	public const META_QUESTIONNAIRE_KEYS         = 'prose_questionnaire_keys';
	public const META_WORKFLOW_PACKAGE           = 'prose_workflow_package';
	public const META_CLASSIFICATION_LOG         = 'prose_classification_log';

	// Automation.
	public const META_FILLABLE_FIELDS      = 'prose_fillable_fields';
	public const META_FIELD_MAPPING_JSON   = 'prose_field_mapping_json';

	// AI.
	public const META_AI_SUMMARY                   = 'prose_ai_summary';
	public const META_PLAIN_LANGUAGE_DESCRIPTION   = 'prose_plain_language_description';
	public const META_COMMON_MISTAKES              = 'prose_common_mistakes';
	public const META_AI_SUMMARY_STRUCTURED        = 'prose_ai_summary_structured';
	public const META_USER_SUMMARY                 = 'prose_user_summary';
	public const META_CONFIDENCE_SCORE             = 'prose_confidence_score';

	// CourtFlow enrichment.
	public const META_WORKFLOW_IDS       = 'prose_workflow_ids';
	public const META_PACKAGE_IDS        = 'prose_package_ids';
	public const META_WORKFLOW_STAGES    = 'prose_workflow_stages';
	public const META_ISSUE_TYPES        = 'prose_issue_types';
	public const META_COURT_ROUTING      = 'prose_court_routing';
	public const META_WORKFLOW_NODES     = 'prose_workflow_nodes';
	public const META_WORKFLOW_NODE_IDS  = 'prose_workflow_node_ids';
	public const META_TRIGGER_EVENTS     = 'prose_trigger_events';
	public const META_COMPLETION_EVENTS  = 'prose_completion_events';
	public const META_NEXT_STEPS         = 'prose_next_steps';
	public const META_REQUIRED_BEFORE    = 'prose_required_before';
	public const META_REQUIRED_AFTER     = 'prose_required_after';
	public const META_PREREQUISITE_FORMS = 'prose_prerequisite_forms';
	public const META_DEPENDENT_FORMS    = 'prose_dependent_forms';
	public const META_RELATED_FORMS      = 'prose_related_forms';
	public const META_FILING_PARTY       = 'prose_filing_party';
	public const META_SERVED_PARTY       = 'prose_served_party';
	public const META_DOCUMENT_TYPE      = 'prose_document_type';
	public const META_ALIASES            = 'prose_aliases';
	public const META_PACKAGE_DEPS       = 'prose_package_dependencies';
	public const META_WORKFLOW_DEPS      = 'prose_workflow_dependencies';
	public const META_DESCRIPTION        = 'prose_description';
	public const META_OFFICIAL_URL       = 'prose_official_url';
	public const META_OFFICIAL_PDF_URL   = 'prose_official_pdf_url';

	/**
	 * All registered meta keys.
	 *
	 * @return string[]
	 */
	public static function keys(): array {
		return array_merge(
			self::string_keys(),
			self::textarea_keys(),
			self::json_keys(),
			self::bool_keys(),
			self::int_keys()
		);
	}

	/**
	 * String meta keys.
	 *
	 * @return string[]
	 */
	public static function string_keys(): array {
		return array(
			self::META_FORM_CODE,
			self::META_FORM_ID,
			self::META_COUNTY,
			self::META_WORKFLOW_KEY,
			self::META_PACKET_GROUP,
			self::META_FILE_NAME,
			self::META_FILE_URL,
			self::META_SOURCE_PDF_URL,
			self::META_PDF_ANALYZED_AT,
			self::META_DETECTED_COURT,
			self::META_DETECTED_COUNTY,
			self::META_DETECTED_CASE_TYPE,
			self::META_DETECTED_WORKFLOW_STAGE,
			self::META_CLASSIFICATION_SOURCE,
			self::META_DOCUMENT_TYPE,
			self::META_DESCRIPTION,
			self::META_OFFICIAL_URL,
			self::META_OFFICIAL_PDF_URL,
			self::META_USER_SUMMARY,
		);
	}

	/**
	 * Textarea meta keys.
	 *
	 * @return string[]
	 */
	public static function textarea_keys(): array {
		return array(
			self::META_AI_SUMMARY,
			self::META_PLAIN_LANGUAGE_DESCRIPTION,
			self::META_CLASSIFICATION_WARNING,
		);
	}

	/**
	 * JSON meta keys (stored as validated JSON strings).
	 *
	 * @return string[]
	 */
	public static function json_keys(): array {
		return array(
			self::META_DEPENDENCIES,
			self::META_CONDITIONS,
			self::META_PDF_FIELDS_JSON,
			self::META_FILLABLE_FIELDS,
			self::META_FIELD_MAPPING_JSON,
			self::META_COMMON_MISTAKES,
			self::META_QUESTIONNAIRE_KEYS,
			self::META_WORKFLOW_PACKAGE,
			self::META_CLASSIFICATION_LOG,
			self::META_CLASSIFICATION_SIGNALS,
			self::META_AI_SUMMARY_STRUCTURED,
			self::META_WORKFLOW_IDS,
			self::META_PACKAGE_IDS,
			self::META_WORKFLOW_STAGES,
			self::META_ISSUE_TYPES,
			self::META_COURT_ROUTING,
			self::META_WORKFLOW_NODES,
			self::META_WORKFLOW_NODE_IDS,
			self::META_TRIGGER_EVENTS,
			self::META_COMPLETION_EVENTS,
			self::META_NEXT_STEPS,
			self::META_REQUIRED_BEFORE,
			self::META_REQUIRED_AFTER,
			self::META_PREREQUISITE_FORMS,
			self::META_DEPENDENT_FORMS,
			self::META_RELATED_FORMS,
			self::META_FILING_PARTY,
			self::META_SERVED_PARTY,
			self::META_ALIASES,
			self::META_PACKAGE_DEPS,
			self::META_WORKFLOW_DEPS,
		);
	}

	/**
	 * Boolean meta keys.
	 *
	 * @return string[]
	 */
	public static function bool_keys(): array {
		return array(
			self::META_REQUIRED,
			self::META_PDF_FILLABLE,
			self::META_SUPPORTED_COURT,
			self::META_NEEDS_REVIEW,
			self::META_MANUAL_OVERRIDE,
		);
	}

	/**
	 * Integer meta keys.
	 *
	 * @return string[]
	 */
	public static function int_keys(): array {
		return array(
			self::META_WORKFLOW_ORDER,
			self::META_PDF_FIELD_COUNT,
			self::META_CLASSIFICATION_CONFIDENCE,
			self::META_CONFIDENCE_SCORE,
		);
	}

	/**
	 * Register hooks.
	 *
	 * @param Loader $loader Hook loader.
	 * @return void
	 */
	public function register( Loader $loader ): void {
		$loader->add_action( 'init', $this, 'register_meta' );
	}

	/**
	 * Register post meta fields for prose_form.
	 *
	 * @return void
	 */
	public function register_meta(): void {
		$auth_callback = static function (): bool {
			return current_user_can( 'edit_posts' );
		};

		foreach ( self::string_keys() as $meta_key ) {
			register_post_meta(
				Form_CPT::POST_TYPE,
				$meta_key,
				array(
					'type'              => 'string',
					'single'            => true,
					'show_in_rest'      => true,
					'sanitize_callback' => 'sanitize_text_field',
					'auth_callback'     => $auth_callback,
				)
			);
		}

		foreach ( self::textarea_keys() as $meta_key ) {
			register_post_meta(
				Form_CPT::POST_TYPE,
				$meta_key,
				array(
					'type'              => 'string',
					'single'            => true,
					'show_in_rest'      => true,
					'sanitize_callback' => 'sanitize_textarea_field',
					'auth_callback'     => $auth_callback,
				)
			);
		}

		foreach ( self::json_keys() as $meta_key ) {
			register_post_meta(
				Form_CPT::POST_TYPE,
				$meta_key,
				array(
					'type'              => 'string',
					'single'            => true,
					'show_in_rest'      => true,
					'sanitize_callback' => array( self::class, 'sanitize_json' ),
					'auth_callback'     => $auth_callback,
				)
			);
		}

		foreach ( self::bool_keys() as $meta_key ) {
			register_post_meta(
				Form_CPT::POST_TYPE,
				$meta_key,
				array(
					'type'              => 'boolean',
					'single'            => true,
					'show_in_rest'      => true,
					'sanitize_callback' => array( self::class, 'sanitize_bool' ),
					'auth_callback'     => $auth_callback,
				)
			);
		}

		foreach ( self::int_keys() as $meta_key ) {
			register_post_meta(
				Form_CPT::POST_TYPE,
				$meta_key,
				array(
					'type'              => 'integer',
					'single'            => true,
					'show_in_rest'      => true,
					'sanitize_callback' => 'absint',
					'auth_callback'     => $auth_callback,
				)
			);
		}
	}

	/**
	 * Sanitize and validate JSON meta values.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	public static function sanitize_json( $value ): string {
		if ( is_array( $value ) || is_object( $value ) ) {
			return wp_json_encode( $value ) ?: '';
		}

		$value = is_string( $value ) ? trim( $value ) : '';

		if ( '' === $value ) {
			return '';
		}

		$decoded = json_decode( $value, true );

		if ( JSON_ERROR_NONE !== json_last_error() ) {
			return $value;
		}

		return wp_json_encode( $decoded ) ?: '';
	}

	/**
	 * Sanitize boolean meta values.
	 *
	 * @param mixed $value Raw value.
	 * @return bool
	 */
	public static function sanitize_bool( $value ): bool {
		return filter_var( $value, FILTER_VALIDATE_BOOLEAN );
	}
}
