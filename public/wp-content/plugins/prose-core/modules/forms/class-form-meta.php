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

	/**
	 * Meta key: external form number (e.g. UD-1).
	 */
	public const META_FORM_ID = 'prose_form_id';

	/**
	 * Meta key: local PDF filename.
	 */
	public const META_FILE_NAME = 'prose_file_name';

	/**
	 * Meta key: local PDF URL.
	 */
	public const META_FILE_URL = 'prose_file_url';

	/**
	 * Meta key: source PDF URL from court website.
	 */
	public const META_SOURCE_PDF_URL = 'prose_source_pdf_url';

	/**
	 * All registered meta keys.
	 *
	 * @return string[]
	 */
	public static function keys(): array {
		return array(
			self::META_FORM_ID,
			self::META_FILE_NAME,
			self::META_FILE_URL,
			self::META_SOURCE_PDF_URL,
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
		foreach ( self::keys() as $meta_key ) {
			register_post_meta(
				Form_CPT::POST_TYPE,
				$meta_key,
				array(
					'type'              => 'string',
					'single'            => true,
					'show_in_rest'      => true,
					'sanitize_callback' => 'sanitize_text_field',
					'auth_callback'     => static function (): bool {
						return current_user_can( 'edit_posts' );
					},
				)
			);
		}
	}
}
