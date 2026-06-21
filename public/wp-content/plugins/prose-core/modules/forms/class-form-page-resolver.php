<?php
/**
 * Form Page Resolver — public single-form permalink for a form code.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Forms;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Form_Page_Resolver
 */
final class Form_Page_Resolver {

	/**
	 * Form repository.
	 *
	 * @var Form_Repository
	 */
	private Form_Repository $forms;

	/**
	 * Constructor.
	 *
	 * @param Form_Repository|null $forms Form repository.
	 */
	public function __construct( ?Form_Repository $forms = null ) {
		$this->forms = $forms ?? new Form_Repository();
	}

	/**
	 * Resolve the public Form Details page URL for a form code.
	 *
	 * @param string $form_code Form code (e.g. UD-1).
	 * @return string Permalink or empty string when unavailable.
	 */
	public function resolve( string $form_code ): string {
		$form_code = trim( $form_code );

		if ( '' === $form_code || ! function_exists( 'get_permalink' ) ) {
			return '';
		}

		$post = $this->forms->get_by_form_code( $form_code );

		if ( ! $post instanceof \WP_Post ) {
			return '';
		}

		$url = get_permalink( $post );

		return is_string( $url ) ? $url : '';
	}
}
