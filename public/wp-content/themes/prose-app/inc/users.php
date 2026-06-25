<?php
/**
 * ProSe user-facing page helpers.
 *
 * @package ProseApp
 */

namespace ProseApp\Users;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Forgot password page URL.
 *
 * @return string
 */
function forgot_password_url(): string {
	if ( class_exists( '\ProSe\Core\Users\Page_Installer' ) ) {
		return \ProSe\Core\Users\Page_Installer::url( 'forgot-password' );
	}

	return wp_lostpassword_url( login_url() );
}

/**
 * Reset password page URL.
 *
 * @param string $key   Reset key.
 * @param string $login User login.
 * @return string
 */
function reset_password_url( string $key = '', string $login = '' ): string {
	if ( class_exists( '\ProSe\Core\Users\Page_Installer' ) ) {
		$url = \ProSe\Core\Users\Page_Installer::url( 'reset-password' );
	} else {
		$url = wp_login_url();
	}

	if ( '' !== $key && '' !== $login ) {
		$url = add_query_arg(
			array(
				'key'   => $key,
				'login' => rawurlencode( $login ),
			),
			$url
		);
	}

	return $url;
}

/**
 * Whether the current page uses the forgot-password template.
 *
 * @return bool
 */
function is_forgot_password_page(): bool {
	return is_page_template( 'page-templates/page-forgot-password.php' );
}

/**
 * Whether the current page uses the reset-password template.
 *
 * @return bool
 */
function is_reset_password_page(): bool {
	return is_page_template( 'page-templates/page-reset-password.php' );
}

/**
 * Login page URL.
 *
 * @param string $redirect Optional redirect after login.
 * @return string
 */
function login_url( string $redirect = '' ): string {
	if ( class_exists( '\ProSe\Core\Users\Page_Installer' ) ) {
		$url = \ProSe\Core\Users\Page_Installer::url( 'login' );
	} else {
		$url = wp_login_url();
	}

	if ( '' !== $redirect ) {
		$url = add_query_arg( 'redirect_to', rawurlencode( $redirect ), $url );
	}

	return $url;
}

/**
 * Register page URL.
 *
 * @param string $redirect Optional redirect after registration.
 * @return string
 */
function register_url( string $redirect = '' ): string {
	if ( class_exists( '\ProSe\Core\Users\Page_Installer' ) ) {
		$url = \ProSe\Core\Users\Page_Installer::url( 'register' );
	} else {
		$url = function_exists( 'wp_registration_url' ) ? wp_registration_url() : wp_login_url();
	}

	if ( '' !== $redirect ) {
		$url = add_query_arg( 'redirect_to', rawurlencode( $redirect ), $url );
	}

	return $url;
}

/**
 * Dashboard page URL.
 *
 * @return string
 */
function dashboard_url(): string {
	if ( class_exists( '\ProSe\Core\Users\Page_Installer' ) ) {
		return \ProSe\Core\Users\Page_Installer::url( 'dashboard' );
	}

	return home_url( '/dashboard/' );
}

/**
 * Whether the current page uses the login template.
 *
 * @return bool
 */
function is_login_page(): bool {
	return is_page_template( 'page-templates/page-login.php' );
}

/**
 * Whether the current page uses the register template.
 *
 * @return bool
 */
function is_register_page(): bool {
	return is_page_template( 'page-templates/page-register.php' );
}

/**
 * Whether the current page is login or register.
 *
 * @return bool
 */
function is_auth_page(): bool {
	return is_login_page() || is_register_page() || is_forgot_password_page() || is_reset_password_page();
}

/**
 * Whether the current page uses the dashboard template.
 *
 * @return bool
 */
function is_dashboard_page(): bool {
	if ( ! is_page() ) {
		return false;
	}

	$dashboard_id = class_exists( '\ProSe\Core\Users\Page_Installer' )
		? (int) get_option( \ProSe\Core\Users\Page_Installer::OPT_DASHBOARD, 0 )
		: 0;

	if ( $dashboard_id > 0 && get_queried_object_id() === $dashboard_id ) {
		return true;
	}

	return is_page_template( 'page-templates/page-dashboard.php' );
}

/**
 * Enqueue dashboard assets.
 *
 * @return void
 */
function enqueue_dashboard_assets(): void {
	if ( ! is_dashboard_page() ) {
		return;
	}

	$script = get_template_directory() . '/build/dashboard.js';

	if ( ! file_exists( $script ) ) {
		return;
	}

	wp_enqueue_style(
		'courtflow-workspace',
		get_template_directory_uri() . '/assets/css/courtflow.css',
		array(),
		prose_app_asset_version( 'assets/css/courtflow.css' )
	);

	wp_enqueue_style(
		'courtflow-roadmap',
		get_template_directory_uri() . '/assets/css/courtflow-roadmap.css',
		array( 'courtflow-workspace' ),
		prose_app_asset_version( 'assets/css/courtflow-roadmap.css' )
	);

	wp_enqueue_script(
		'prose-dashboard',
		get_template_directory_uri() . '/build/dashboard.js',
		array(),
		(string) ( file_exists( $script ) ? prose_app_asset_version( 'build/dashboard.js' ) : PROSE_APP_VERSION ),
		true
	);

	wp_localize_script(
		'prose-dashboard',
		'proseDashboardConfig',
		array(
			'restUrl' => esc_url_raw( rest_url( 'prose/v1/' ) ),
			'nonce'   => wp_create_nonce( 'wp_rest' ),
			'homeUrl' => esc_url_raw( home_url( '/' ) ),
			'i18n'    => array(
				'loading'       => __( 'Loading your dashboard…', 'prose-app' ),
				'error'         => __( 'Could not load dashboard data.', 'prose-app' ),
				'noCase'        => __( 'You have not started a case yet.', 'prose-app' ),
				'startCase'     => __( 'Start a new case', 'prose-app' ),
				'noConversations' => __( 'No conversations yet. Start chatting on the homepage while logged in to save your intake here.', 'prose-app' ),
				'startChat'       => __( 'Start chatting', 'prose-app' ),
				'resumeChat'      => __( 'Resume', 'prose-app' ),
				'removeConversation' => __( 'Remove', 'prose-app' ),
				'confirmRemoveConversation' => __( 'Remove this conversation from your dashboard? This cannot be undone.', 'prose-app' ),
				'removingConversation' => __( 'Removing conversation…', 'prose-app' ),
				'removeError'     => __( 'Could not remove conversation.', 'prose-app' ),
				'continueCase'    => __( 'Continue Case', 'prose-app' ),
				'noLifecycle'     => __( 'Lifecycle tracking appears after you start a divorce case.', 'prose-app' ),
				'updateMilestones' => __( 'Update milestones', 'prose-app' ),
				'noMatterMap'     => __( 'No parallel court tracks identified yet.', 'prose-app' ),
				'noDocuments'   => __( 'No documents generated yet.', 'prose-app' ),
			),
		)
	);
}

add_action( 'wp_enqueue_scripts', __NAMESPACE__ . '\\enqueue_dashboard_assets' );
