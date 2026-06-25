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
			prose_app_asset_version( 'build/courtflow.js' ),
			true
		);
	}

	$css = get_template_directory() . '/assets/css/courtflow.css';
	wp_register_style(
		'courtflow-workspace',
		get_template_directory_uri() . '/assets/css/courtflow.css',
		array(),
		file_exists( $css ) ? prose_app_asset_version( 'assets/css/courtflow.css' ) : PROSE_APP_VERSION
	);

	$roadmap_css = get_template_directory() . '/assets/css/courtflow-roadmap.css';
	wp_register_style(
		'courtflow-roadmap',
		get_template_directory_uri() . '/assets/css/courtflow-roadmap.css',
		array( 'courtflow-workspace' ),
		file_exists( $roadmap_css ) ? prose_app_asset_version( 'assets/css/courtflow-roadmap.css' ) : PROSE_APP_VERSION
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
			'restUrl'      => esc_url_raw( rest_url( 'courtflow/v1/' ) ),
			'nonce'        => wp_create_nonce( 'wp_rest' ),
			'loginUrl'     => class_exists( '\ProSe\Core\Users\Page_Installer' )
				? esc_url_raw( \ProSe\Core\Users\Page_Installer::url( 'login' ) )
				: esc_url_raw( wp_login_url() ),
			'registerUrl'  => class_exists( '\ProSe\Core\Users\Page_Installer' )
				? esc_url_raw( \ProSe\Core\Users\Page_Installer::url( 'register' ) )
				: esc_url_raw( wp_registration_url() ),
			'dashboardUrl' => class_exists( '\ProSe\Core\Users\Page_Installer' )
				? esc_url_raw( \ProSe\Core\Users\Page_Installer::url( 'dashboard' ) )
				: esc_url_raw( home_url( '/dashboard/' ) ),
			'isLoggedIn'   => is_user_logged_in(),
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
				'viewForm'    => __( 'View form details', 'prose-app' ),
				'downloadForm' => __( 'Download', 'prose-app' ),
				'lifecycleTitle' => __( 'Case milestones', 'prose-app' ),
				'serviceDatePrompt' => __( 'Enter the service date (YYYY-MM-DD):', 'prose-app' ),
				'lifecycleError' => __( 'Could not record milestone.', 'prose-app' ),
			),
		)
	);
}

add_action( 'init', __NAMESPACE__ . '\\register_assets' );
add_action( 'wp_enqueue_scripts', __NAMESPACE__ . '\\enqueue_inter_font' );
