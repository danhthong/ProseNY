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

const META_AI_SUMMARY_STRUCTURED = 'prose_ai_summary_structured';
const META_USER_SUMMARY          = 'prose_user_summary';
const META_PLAIN_LANGUAGE        = 'prose_plain_language_description';
const META_COMMON_MISTAKES       = 'prose_common_mistakes';
const META_OFFICIAL_URL          = 'prose_official_url';
const META_QUESTIONNAIRE_KEYS    = 'prose_questionnaire_keys';
const META_FILLABLE_FIELDS       = 'prose_fillable_fields';

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

/**
 * Decode structured AI summary post meta for a form.
 *
 * @param int $post_id Post ID.
 * @return array<string, string>
 */
function get_ai_summary_structured( int $post_id ): array {
	$raw = get_post_meta( $post_id, META_AI_SUMMARY_STRUCTURED, true );

	if ( is_string( $raw ) && '' !== $raw ) {
		$decoded = json_decode( $raw, true );

		if ( is_array( $decoded ) ) {
			return array_map( 'strval', $decoded );
		}
	}

	if ( is_array( $raw ) ) {
		return array_map( 'strval', $raw );
	}

	return array();
}

/**
 * Get official NY Courts URL for a form.
 *
 * @param int $post_id Post ID.
 * @return string
 */
function get_official_url( int $post_id ): string {
	return (string) get_post_meta( $post_id, META_OFFICIAL_URL, true );
}

/**
 * Normalize a field key to the canonical catalog key when possible.
 *
 * @param string $key Raw field key.
 * @return string
 */
function normalize_field_key( string $key ): string {
	$key = strtolower( trim( $key ) );

	if ( '' === $key ) {
		return '';
	}

	if ( class_exists( '\ProSe\Core\Forms\Documents\Field_Catalog' ) ) {
		$aliases = \ProSe\Core\Forms\Documents\Field_Catalog::aliases();

		if ( isset( $aliases[ $key ] ) ) {
			return (string) $aliases[ $key ];
		}
	}

	return $key;
}

/**
 * Human label for a field or questionnaire key.
 *
 * @param string $key Field key.
 * @return string
 */
function label_for_field_key( string $key ): string {
	$canonical = normalize_field_key( $key );

	if ( class_exists( '\ProSe\Core\Forms\Documents\Field_Catalog' ) && \ProSe\Core\Forms\Documents\Field_Catalog::has_field( $canonical ) ) {
		return \ProSe\Core\Forms\Documents\Field_Catalog::label( $canonical );
	}

	return ucwords( str_replace( '_', ' ', $canonical ) );
}

/**
 * Decode a JSON post-meta array.
 *
 * @param mixed $raw Meta value.
 * @return array<int, string>
 */
function decode_string_list_meta( $raw ): array {
	if ( is_array( $raw ) ) {
		return array_values( array_filter( array_map( 'strval', $raw ) ) );
	}

	if ( ! is_string( $raw ) || '' === $raw ) {
		return array();
	}

	$decoded = json_decode( $raw, true );

	if ( is_array( $decoded ) ) {
		return array_values( array_filter( array_map( 'strval', $decoded ) ) );
	}

	return array();
}

/**
 * Build what the user should gather before completing this form.
 *
 * @param int $post_id Post ID.
 * @return array{
 *     required: array<int, array{label: string}>,
 *     optional: array<int, array{label: string}>,
 *     conditional: array<int, array{label: string}>
 * }
 */
function get_prepare_items( int $post_id ): array {
	$form_code   = get_form_id( $post_id );
	$required    = array();
	$optional    = array();
	$conditional = array();
	$seen        = array();

	$add = static function ( string $key, string $bucket ) use ( &$required, &$optional, &$conditional, &$seen ): void {
		$canonical = normalize_field_key( $key );

		if ( '' === $canonical || isset( $seen[ $canonical ] ) ) {
			return;
		}

		$seen[ $canonical ] = true;
		$item               = array(
			'label' => label_for_field_key( $canonical ),
		);

		if ( 'required' === $bucket ) {
			$required[] = $item;
		} elseif ( 'conditional' === $bucket ) {
			$conditional[] = $item;
		} else {
			$optional[] = $item;
		}
	};

	if ( '' !== $form_code && class_exists( '\ProSe\Core\Forms\Documents\Field_Catalog' ) ) {
		foreach ( \ProSe\Core\Forms\Documents\Field_Catalog::required_fields( $form_code ) as $field_key ) {
			$add( (string) $field_key, 'required' );
		}

		foreach ( \ProSe\Core\Forms\Documents\Field_Catalog::optional_fields( $form_code ) as $field_key ) {
			$add( (string) $field_key, 'optional' );
		}

		foreach ( \ProSe\Core\Forms\Documents\Field_Catalog::conditional_fields( $form_code ) as $rule ) {
			$add( (string) ( $rule['field'] ?? '' ), 'conditional' );
		}
	}

	foreach ( decode_string_list_meta( get_post_meta( $post_id, META_QUESTIONNAIRE_KEYS, true ) ) as $field_key ) {
		$add( $field_key, 'optional' );
	}

	$fillable_raw = get_post_meta( $post_id, META_FILLABLE_FIELDS, true );
	$fillable     = array();

	if ( is_string( $fillable_raw ) && '' !== $fillable_raw ) {
		$decoded = json_decode( $fillable_raw, true );
		$fillable = is_array( $decoded ) ? $decoded : array();
	} elseif ( is_array( $fillable_raw ) ) {
		$fillable = $fillable_raw;
	}

	foreach ( $fillable as $field ) {
		if ( ! is_array( $field ) ) {
			continue;
		}

		$key = (string) ( $field['normalized_key'] ?? $field['pdf_field'] ?? '' );
		$add( $key, 'optional' );
	}

	return array(
		'required'    => $required,
		'optional'    => $optional,
		'conditional' => $conditional,
	);
}

/**
 * Remove NY Courts sidebar boilerplate from explanation text.
 *
 * @param string $body Raw explanation body or summary.
 * @return string
 */
function sanitize_explanation_body( string $body ): string {
	$body = trim( $body );

	if ( '' === $body ) {
		return '';
	}

	$clean = array();

	foreach ( preg_split( '/\r?\n/', $body ) ?: array() as $line ) {
		$trim = trim( $line );

		if ( '' === $trim ) {
			$clean[] = '';
			continue;
		}

		if ( preg_match( '/^form details\b/i', $trim ) ) {
			continue;
		}

		$clean[] = $line;
	}

	$body = trim( implode( "\n", $clean ) );

	// Single-line blobs that still contain sidebar metadata only.
	if ( preg_match( '/^form details\b/i', preg_replace( '/\s+/', ' ', $body ) ) ) {
		return '';
	}

	$non_empty = array_values(
		array_filter(
			array_map( 'trim', $clean ),
			static function ( string $line ): bool {
				return '' !== $line;
			}
		)
	);

	// Drop a lone markdown heading — the page title already covers it.
	if ( 1 === count( $non_empty ) && preg_match( '/^#{1,3}\s+/', $non_empty[0] ) ) {
		return '';
	}

	return $body;
}

/**
 * Render markdown-ish explanation body as safe HTML paragraphs.
 *
 * @param string $body Markdown body text.
 * @return string
 */
function format_explanation_body( string $body ): string {
	$body = sanitize_explanation_body( $body );

	if ( '' === $body ) {
		return '';
	}

	$html    = '';
	$in_list = false;

	foreach ( preg_split( '/\r?\n/', $body ) ?: array() as $line ) {
		$line = rtrim( $line );

		if ( '' === trim( $line ) ) {
			if ( $in_list ) {
				$html   .= '</ul>';
				$in_list = false;
			}
			continue;
		}

		if ( preg_match( '/^#{1,3}\s+(.+)$/', $line, $matches ) ) {
			if ( $in_list ) {
				$html   .= '</ul>';
				$in_list = false;
			}
			$html .= '<p class="mt-2 text-[12px] font-semibold uppercase tracking-wide text-slate-500">' . esc_html( $matches[1] ) . '</p>';
			continue;
		}

		if ( preg_match( '/^[-*]\s+(.+)$/', $line, $matches ) ) {
			if ( ! $in_list ) {
				$html   .= '<ul class="mt-1 list-disc space-y-1 pl-4 text-[13px] leading-[20px] text-slate-600">';
				$in_list = true;
			}
			$html .= '<li>' . esc_html( $matches[1] ) . '</li>';
			continue;
		}

		if ( $in_list ) {
			$html   .= '</ul>';
			$in_list = false;
		}

		$html .= '<p class="text-[13px] leading-[20px] text-slate-600">' . esc_html( $line ) . '</p>';
	}

	if ( $in_list ) {
		$html .= '</ul>';
	}

	return $html;
}

/**
 * Build normalized AI explanation payload for the Form Details sidebar.
 *
 * @param int $post_id Post ID.
 * @return array{
 *     has_content: bool,
 *     summary: string,
 *     body: string,
 *     sections: array<string, string>,
 *     common_mistakes: string[],
 *     prepare: array{required: array<int, array{label: string}>, optional: array<int, array{label: string}>, conditional: array<int, array{label: string}>},
 *     official_url: string,
 *     source: string
 * }
 */
function get_ai_explanation( int $post_id ): array {
	$structured     = get_ai_summary_structured( $post_id );
	$official_url   = get_official_url( $post_id );
	$user_summary   = (string) get_post_meta( $post_id, META_USER_SUMMARY, true );
	$plain_language = (string) get_post_meta( $post_id, META_PLAIN_LANGUAGE, true );
	$mistakes_raw   = get_post_meta( $post_id, META_COMMON_MISTAKES, true );
	$mistakes       = array();

	if ( is_string( $mistakes_raw ) && '' !== $mistakes_raw ) {
		$decoded = json_decode( $mistakes_raw, true );

		if ( is_array( $decoded ) ) {
			$mistakes = array_values( array_filter( array_map( 'strval', $decoded ) ) );
		}
	} elseif ( is_array( $mistakes_raw ) ) {
		$mistakes = array_values( array_filter( array_map( 'strval', $mistakes_raw ) ) );
	}

	$sections = array(
		'what'  => trim( (string) ( $structured['what'] ?? '' ) ),
		'why'   => trim( (string) ( $structured['why'] ?? '' ) ),
		'when'  => trim( (string) ( $structured['when'] ?? '' ) ),
		'next'  => trim( (string) ( $structured['next'] ?? '' ) ),
		'stage' => trim( (string) ( $structured['stage'] ?? '' ) ),
		'court' => trim( (string) ( $structured['court'] ?? '' ) ),
	);

	$summary = trim( $user_summary );

	if ( '' === $summary ) {
		$summary = trim( (string) ( $structured['user_summary'] ?? '' ) );
	}

	if ( '' === $summary ) {
		$summary = trim( implode( ' ', array_filter( array( $sections['what'], $sections['why'], $sections['when'] ) ) ) );
	}

	$source = 'none';
	$body   = '';

	if ( '' !== $summary || array_filter( $sections ) ) {
		$source = 'classification';
	}

	$article = null;

	if ( class_exists( '\ProSe\Core\Search\Knowledge_Article_Loader' ) ) {
		$form_code = get_form_id( $post_id );
		$article   = ( new \ProSe\Core\Search\Knowledge_Article_Loader() )->find_by_form_code( $form_code );
	}

	if ( null !== $article ) {
		$body = sanitize_explanation_body( trim( (string) ( $article['body'] ?? $article['content'] ?? '' ) ) );

		if ( '' === $summary ) {
			$summary = sanitize_explanation_body( trim( (string) ( $article['summary'] ?? '' ) ) );

			if ( '' === $summary && '' !== $body ) {
				$summary = $body;
			}

			if ( '' !== $summary || '' !== $body ) {
				$source = 'knowledge';
			}
		}

		if ( '' === $official_url ) {
			$official_url = (string) ( $article['source_url'] ?? '' );
		}
	}

	if ( '' === $summary && '' !== $plain_language ) {
		$summary = $plain_language;
		$source  = 'classification';
	}

	$prepare = get_prepare_items( $post_id );

	return array(
		'has_content'     => '' !== $summary
			|| '' !== $body
			|| array_filter( $sections )
			|| ! empty( $prepare['required'] )
			|| ! empty( $prepare['optional'] )
			|| ! empty( $prepare['conditional'] ),
		'summary'         => $summary,
		'body'            => $body,
		'sections'        => $sections,
		'common_mistakes' => $mistakes,
		'prepare'         => $prepare,
		'official_url'    => $official_url,
		'source'          => $source,
	);
}
