<?php
/**
 * Enqueue CourtFlow workspace assets.
 *
 * @package ProseApp
 */

namespace ProseApp\Enqueue;

use ProseApp\Courtflow;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function register_assets(): void {
	$build = get_template_directory() . '/build/courtflow.js';
	if ( file_exists( $build ) ) {
		wp_register_script(
			'courtflow-workspace',
			get_template_directory_uri() . '/build/courtflow.js',
			array(),
			(string) filemtime( $build ),
			true
		);
	}

	$css = get_template_directory() . '/assets/css/courtflow.css';
	wp_register_style(
		'courtflow-workspace',
		get_template_directory_uri() . '/assets/css/courtflow.css',
		array(),
		file_exists( $css ) ? (string) filemtime( $css ) : PROSE_APP_VERSION
	);
}

function enqueue_inter_font(): void {
	if ( ! wp_style_is( 'courtflow-workspace', 'enqueued' ) && ! Courtflow\is_workspace_page() ) {
		return;
	}

	wp_enqueue_style(
		'courtflow-inter',
		'https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&display=swap',
		array(),
		null
	);
}

function localize(): void {
	$steps = array();
	if ( function_exists( 'ProseApp\\Courtflow\\step_catalog' ) ) {
		$steps = \ProseApp\Courtflow\step_catalog();
	}

	wp_localize_script(
		'courtflow-workspace',
		'courtflowConfig',
		array(
			'restUrl'    => esc_url_raw( rest_url( 'courtflow/v1/' ) ),
			'nonce'      => wp_create_nonce( 'wp_rest' ),
			'disclaimer' => class_exists( '\Prose\Core\Security\Disclaimer' )
				? \Prose\Core\Security\Disclaimer::text()
				: '',
			'steps'      => $steps,
			'i18n'       => array(
				'saved'       => __( 'Saved', 'prose-app' ),
				'saving'      => __( 'Saving…', 'prose-app' ),
				'inProgress'  => __( 'In progress', 'prose-app' ),
				'ready'       => __( 'Ready to file', 'prose-app' ),
				'attention'   => __( 'Needs attention', 'prose-app' ),
				'stepOf'      => __( 'Step %1$d of %2$d', 'prose-app' ),
				'informational' => __( 'Informational guidance only — not legal advice.', 'prose-app' ),
			),
		)
	);
}

add_action( 'init', __NAMESPACE__ . '\\register_assets' );
add_action( 'wp_enqueue_scripts', __NAMESPACE__ . '\\enqueue_inter_font' );
