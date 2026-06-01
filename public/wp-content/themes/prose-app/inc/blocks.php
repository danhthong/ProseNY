<?php
/**
 * Register CourtFlow Gutenberg blocks.
 *
 * @package ProseApp
 */

namespace ProseApp\Blocks;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Register all CourtFlow blocks.
 */
function register(): void {
	$blocks = array(
		'courtflow-workspace',
		'courtflow-progress-rail',
		'courtflow-intake-chat',
		'courtflow-context-panel',
		'courtflow-data-panel',
		'courtflow-validation-alerts',
		'courtflow-checklist',
		'courtflow-document-library',
	);

	foreach ( $blocks as $block ) {
		$dir = get_template_directory() . '/blocks/' . $block;
		if ( file_exists( $dir . '/block.json' ) ) {
			register_block_type( $dir );
		}
	}
}

add_action( 'init', __NAMESPACE__ . '\\register' );

/**
 * Force-register the workspace page template even if WordPress fails to scan it.
 *
 * @param array<string, string> $templates
 * @return array<string, string>
 */
function add_page_templates( array $templates ): array {
	$templates['page-templates/page-workspace.php'] = __( 'CourtFlow Workspace', 'prose-app' );
	return $templates;
}

add_filter( 'theme_page_templates', __NAMESPACE__ . '\\add_page_templates' );

/**
 * When the chosen template is our workspace template, load the corresponding file.
 */
function load_workspace_template( string $template ): string {
	if ( ! is_page() ) {
		return $template;
	}

	$slug = (string) get_page_template_slug( get_queried_object_id() );
	if ( 'page-templates/page-workspace.php' === $slug ) {
		$candidate = get_template_directory() . '/page-templates/page-workspace.php';
		if ( file_exists( $candidate ) ) {
			return $candidate;
		}
	}

	return $template;
}

add_filter( 'page_template', __NAMESPACE__ . '\\load_workspace_template' );
