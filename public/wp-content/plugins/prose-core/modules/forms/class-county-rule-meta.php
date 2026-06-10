<?php
/**
 * County rule post meta registration.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Forms;

use ProSe\Core\Loader;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class County_Rule_Meta
 */
class County_Rule_Meta {

	public const META_COUNTY      = 'prose_county_rule_county';
	public const META_RULE_TYPE   = 'prose_county_rule_type';
	public const META_APPLIES_TO  = 'prose_county_rule_applies_to';
	public const META_APPLIES_REF = 'prose_county_rule_applies_ref';
	public const META_DESCRIPTION = 'prose_county_rule_description';

	/**
	 * Rule type values.
	 */
	public const TYPE_FILING_INSTRUCTIONS = 'filing_instructions';
	public const TYPE_PROCEDURE           = 'procedure';
	public const TYPE_MATRIMONIAL         = 'matrimonial';
	public const TYPE_CONFERENCE          = 'conference';
	public const TYPE_EFILING             = 'efiling';

	/**
	 * Applies-to scope values.
	 */
	public const SCOPE_FORM     = 'form';
	public const SCOPE_PACKAGE  = 'package';
	public const SCOPE_WORKFLOW = 'workflow';
	public const SCOPE_GLOBAL   = 'global';

	/**
	 * All registered meta keys.
	 *
	 * @return string[]
	 */
	public static function keys(): array {
		return array_merge( self::string_keys(), self::textarea_keys() );
	}

	/**
	 * String meta keys.
	 *
	 * @return string[]
	 */
	public static function string_keys(): array {
		return array(
			self::META_COUNTY,
			self::META_RULE_TYPE,
			self::META_APPLIES_TO,
			self::META_APPLIES_REF,
		);
	}

	/**
	 * Textarea meta keys.
	 *
	 * @return string[]
	 */
	public static function textarea_keys(): array {
		return array(
			self::META_DESCRIPTION,
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
				County_Rule_CPT::POST_TYPE,
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
				County_Rule_CPT::POST_TYPE,
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
	}
}
