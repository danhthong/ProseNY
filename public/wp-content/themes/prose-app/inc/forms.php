<?php
/**
 * Public-facing Forms library + Form details support.
 *
 * The `prose_form` CPT is registered private by prose-core (admin/REST only).
 * The theme opts it into public front-end viewing so the Forms Library
 * (archive) and Form Details (single) templates can render, matching the
 * Figma "Forms" module.
 *
 * @package ProseApp
 */

namespace ProseApp\Forms;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const POST_TYPE      = 'prose_form';
const TAXONOMY       = 'prose_case_type';
const FILTER_VAR     = 'prose_case_type_filter';
const REWRITE_SLUG   = 'forms';
const REWRITE_OPTION = 'prose_app_forms_rewrite';
const REWRITE_FLAG   = '2';

const META_FORM_ID   = 'prose_form_id';
const META_FILE_NAME = 'prose_file_name';
const META_FILE_URL  = 'prose_file_url';

/**
 * Make the forms CPT publicly viewable on the front end.
 *
 * @param array<string, mixed> $args      Post type args.
 * @param string               $post_type Post type key.
 * @return array<string, mixed>
 */
function public_form_args( array $args, string $post_type ): array {
	if ( POST_TYPE !== $post_type ) {
		return $args;
	}

	$args['public']              = true;
	$args['publicly_queryable']  = true;
	$args['exclude_from_search'] = false;
	$args['has_archive']         = true;
	$args['show_in_nav_menus']   = true;
	$args['rewrite']             = array(
		'slug'       => REWRITE_SLUG,
		'with_front' => false,
	);

	return $args;
}
add_filter( 'register_post_type_args', __NAMESPACE__ . '\\public_form_args', 20, 2 );

/**
 * Register the case-type filter query var.
 *
 * @param string[] $vars Query vars.
 * @return string[]
 */
function register_query_vars( array $vars ): array {
	$vars[] = FILTER_VAR;

	return $vars;
}
add_filter( 'query_vars', __NAMESPACE__ . '\\register_query_vars' );

/**
 * Apply search + case-type filtering on the public Forms archive.
 *
 * @param \WP_Query $query Query instance.
 * @return void
 */
function filter_archive( \WP_Query $query ): void {
	if ( is_admin() || ! $query->is_main_query() ) {
		return;
	}

	if ( ! $query->is_post_type_archive( POST_TYPE ) ) {
		return;
	}

	$query->set( 'posts_per_page', 12 );
	$query->set( 'orderby', 'title' );
	$query->set( 'order', 'ASC' );

	$case_type = sanitize_title( (string) get_query_var( FILTER_VAR ) );

	if ( '' !== $case_type ) {
		$query->set(
			'tax_query',
			array(
				array(
					'taxonomy' => TAXONOMY,
					'field'    => 'slug',
					'terms'    => $case_type,
				),
			)
		);
	}
}
add_action( 'pre_get_posts', __NAMESPACE__ . '\\filter_archive' );

/**
 * Flush rewrite rules once after the public args are registered.
 *
 * @return void
 */
function maybe_flush_rewrite(): void {
	if ( get_option( REWRITE_OPTION ) === REWRITE_FLAG ) {
		return;
	}

	flush_rewrite_rules( false );
	update_option( REWRITE_OPTION, REWRITE_FLAG );
}
add_action( 'init', __NAMESPACE__ . '\\maybe_flush_rewrite', 99 );

/**
 * Flush rewrite rules when the theme is activated.
 *
 * @return void
 */
function flush_on_activation(): void {
	delete_option( REWRITE_OPTION );
}
add_action( 'after_switch_theme', __NAMESPACE__ . '\\flush_on_activation' );

/**
 * Get the external form number (e.g. UD-1).
 *
 * @param int $post_id Post ID.
 * @return string
 */
function get_form_id( int $post_id ): string {
	return (string) get_post_meta( $post_id, META_FORM_ID, true );
}

/**
 * Get the local PDF file name.
 *
 * @param int $post_id Post ID.
 * @return string
 */
function get_file_name( int $post_id ): string {
	return (string) get_post_meta( $post_id, META_FILE_NAME, true );
}

/**
 * Get the local PDF file URL.
 *
 * @param int $post_id Post ID.
 * @return string
 */
function get_file_url( int $post_id ): string {
	return (string) get_post_meta( $post_id, META_FILE_URL, true );
}

/**
 * Get the primary case-type term name for a form.
 *
 * @param int $post_id Post ID.
 * @return string
 */
function get_case_type_label( int $post_id ): string {
	$terms = get_the_terms( $post_id, TAXONOMY );

	if ( is_wp_error( $terms ) || empty( $terms ) ) {
		return '';
	}

	return $terms[0]->name;
}

/**
 * Build a human-readable description fallback for a form.
 *
 * The CPT only supports a title, so compose a short summary line.
 *
 * @param int $post_id Post ID.
 * @return string
 */
function get_description( int $post_id ): string {
	$form_id    = get_form_id( $post_id );
	$case_type  = get_case_type_label( $post_id );
	$title      = get_the_title( $post_id );

	$pieces = array();

	if ( '' !== $case_type ) {
		/* translators: %s: case type name. */
		$pieces[] = sprintf( __( 'Official %s court form.', 'prose-app' ), $case_type );
	}

	$pieces[] = __( 'Review the PDF preview, then download the form or start a guided interview.', 'prose-app' );

	unset( $form_id, $title );

	return implode( ' ', $pieces );
}
