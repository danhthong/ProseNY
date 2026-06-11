<?php
/**
 * Package post meta registration.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Forms;

use ProSe\Core\Loader;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Package_Meta
 */
class Package_Meta {

	public const META_PACKAGE_ID            = 'prose_package_id';
	public const META_PACKAGE_NAME          = 'prose_package_name';
	public const META_COURT                 = 'prose_package_court';
	public const META_WORKFLOW_ID           = 'prose_package_workflow_id';
	public const META_WORKFLOW_STAGE        = 'prose_package_workflow_stage';
	public const META_COUNTY_SPECIFIC       = 'prose_package_county_specific';
	public const META_COUNTIES              = 'prose_package_counties';
	public const META_REQUIRED_FORMS        = 'prose_package_required_forms';
	public const META_OPTIONAL_FORMS        = 'prose_package_optional_forms';
	public const META_SUPPORTING_DOCUMENTS  = 'prose_package_supporting_documents';
	public const META_PREREQUISITE_PACKAGES = 'prose_package_prerequisite_packages';
	public const META_DEPENDENT_PACKAGES    = 'prose_package_dependent_packages';
	public const META_TRIGGER_CONDITIONS    = 'prose_package_trigger_conditions';
	public const META_COMPLETION_CONDITIONS = 'prose_package_completion_conditions';
	public const META_NEXT_PACKAGE_IDS      = 'prose_package_next_package_ids';
	public const META_NEXT_STAGE            = 'prose_package_next_stage';
	public const META_SERVICE_REQUIRED      = 'prose_package_service_required';
	public const META_FILING_REQUIRED       = 'prose_package_filing_required';
	public const META_ESTIMATED_TASKS       = 'prose_package_estimated_tasks';
	public const META_DEADLINE_RULES        = 'prose_package_deadline_rules';
	public const META_WORKFLOW_NODES        = 'prose_package_workflow_nodes';
	public const META_SUMMARY               = 'prose_package_summary';

	// Versioning.
	public const META_PACKAGE_VERSION           = 'prose_package_version';
	public const META_PACKAGE_EFFECTIVE_FROM    = 'prose_package_effective_from';
	public const META_PACKAGE_EFFECTIVE_TO      = 'prose_package_effective_to';
	public const META_PACKAGE_IS_ACTIVE         = 'prose_package_is_active';
	public const META_PACKAGE_SUPERSEDES_ID     = 'prose_package_supersedes_id';
	public const META_PACKAGE_REPLACEMENT_ID      = 'prose_package_replacement_id';
	public const META_PACKAGE_ORDER             = 'prose_package_order';

	/**
	 * All registered meta keys.
	 *
	 * @return string[]
	 */
	public static function keys(): array {
		return array_merge(
			self::string_keys(),
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
			self::META_PACKAGE_ID,
			self::META_PACKAGE_NAME,
			self::META_COURT,
			self::META_WORKFLOW_ID,
			self::META_WORKFLOW_STAGE,
			self::META_NEXT_STAGE,
			self::META_SUMMARY,
			self::META_PACKAGE_EFFECTIVE_FROM,
			self::META_PACKAGE_EFFECTIVE_TO,
		);
	}

	/**
	 * JSON meta keys.
	 *
	 * @return string[]
	 */
	public static function json_keys(): array {
		return array(
			self::META_COUNTIES,
			self::META_REQUIRED_FORMS,
			self::META_OPTIONAL_FORMS,
			self::META_SUPPORTING_DOCUMENTS,
			self::META_PREREQUISITE_PACKAGES,
			self::META_DEPENDENT_PACKAGES,
			self::META_TRIGGER_CONDITIONS,
			self::META_COMPLETION_CONDITIONS,
			self::META_NEXT_PACKAGE_IDS,
			self::META_ESTIMATED_TASKS,
			self::META_DEADLINE_RULES,
			self::META_WORKFLOW_NODES,
		);
	}

	/**
	 * Boolean meta keys.
	 *
	 * @return string[]
	 */
	public static function bool_keys(): array {
		return array(
			self::META_COUNTY_SPECIFIC,
			self::META_SERVICE_REQUIRED,
			self::META_FILING_REQUIRED,
			self::META_PACKAGE_IS_ACTIVE,
		);
	}

	/**
	 * Integer meta keys.
	 *
	 * @return string[]
	 */
	public static function int_keys(): array {
		return array(
			self::META_PACKAGE_VERSION,
			self::META_PACKAGE_SUPERSEDES_ID,
			self::META_PACKAGE_REPLACEMENT_ID,
			self::META_PACKAGE_ORDER,
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
	 * Register post meta fields.
	 *
	 * @return void
	 */
	public function register_meta(): void {
		$auth_callback = static function (): bool {
			return current_user_can( 'edit_posts' );
		};

		foreach ( self::string_keys() as $meta_key ) {
			register_post_meta(
				Package_CPT::POST_TYPE,
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

		foreach ( self::json_keys() as $meta_key ) {
			register_post_meta(
				Package_CPT::POST_TYPE,
				$meta_key,
				array(
					'type'              => 'string',
					'single'            => true,
					'show_in_rest'      => true,
					'sanitize_callback' => array( Form_Meta::class, 'sanitize_json' ),
					'auth_callback'     => $auth_callback,
				)
			);
		}

		foreach ( self::bool_keys() as $meta_key ) {
			register_post_meta(
				Package_CPT::POST_TYPE,
				$meta_key,
				array(
					'type'              => 'boolean',
					'single'            => true,
					'show_in_rest'      => true,
					'sanitize_callback' => array( Form_Meta::class, 'sanitize_bool' ),
					'auth_callback'     => $auth_callback,
				)
			);
		}

		foreach ( self::int_keys() as $meta_key ) {
			register_post_meta(
				Package_CPT::POST_TYPE,
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
}
