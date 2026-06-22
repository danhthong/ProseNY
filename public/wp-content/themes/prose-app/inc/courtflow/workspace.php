<?php
/**
 * CourtFlow workspace page helpers.
 *
 * @package ProseApp
 */

namespace ProseApp\Courtflow;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Whether the current request is the CourtFlow workspace.
 */
function is_workspace_page(): bool {
	$post_id = get_queried_object_id();
	if ( $post_id ) {
		$slug = (string) get_page_template_slug( $post_id );
		if ( 'page-templates/page-workspace.php' === $slug ) {
			return true;
		}
	}

	global $post;
	if ( $post instanceof \WP_Post ) {
		if ( 'page-templates/page-workspace.php' === get_page_template_slug( $post->ID ) ) {
			return true;
		}
		if ( has_shortcode( $post->post_content, 'courtflow_workspace' ) ) {
			return true;
		}
		if ( has_block( 'courtflow/workspace', $post ) ) {
			return true;
		}
	}

	return false;
}

/**
 * @param array<string, string|bool> $classes
 * @return array<string, string|bool>
 */
function body_class( array $classes ): array {
	if ( is_workspace_page() ) {
		$classes[] = 'courtflow-workspace-page';
		$classes[] = 'cf-workspace-active';
	}

	return $classes;
}

add_filter( 'body_class', __NAMESPACE__ . '\\body_class' );

/**
 * Enqueue workspace assets in head when on workspace page.
 */
function enqueue_assets(): void {
	if ( ! is_workspace_page() ) {
		return;
	}

	wp_enqueue_style( 'courtflow-workspace' );
	wp_enqueue_style( 'courtflow-roadmap' );
	wp_enqueue_script( 'courtflow-workspace' );
	if ( function_exists( 'ProseApp\\Enqueue\\localize' ) ) {
		\ProseApp\Enqueue\localize();
	}
	if ( function_exists( 'ProseApp\\Enqueue\\enqueue_inter_font' ) ) {
		\ProseApp\Enqueue\enqueue_inter_font();
	}
}

add_action( 'wp_enqueue_scripts', __NAMESPACE__ . '\\enqueue_assets' );

/**
 * Default workflow title for header.
 */
function default_workflow_title(): string {
	return __( 'NY Family Court Filing', 'prose-app' );
}
