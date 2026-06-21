<?php
/**
 * Blocks wp-admin access for prose_client users.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Users;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Admin_Access_Guard
 */
final class Admin_Access_Guard {

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_init', array( $this, 'block_admin' ) );
		add_filter( 'show_admin_bar', array( $this, 'hide_admin_bar' ) );
		add_filter( 'login_redirect', array( $this, 'login_redirect' ), 10, 3 );
		add_action( 'get_header', array( $this, 'disable_admin_bar_bump' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'dequeue_admin_bar_assets' ), 100 );
		add_filter( 'body_class', array( $this, 'strip_admin_bar_body_class' ) );
	}

	/**
	 * Redirect non-admins away from wp-admin.
	 *
	 * @return void
	 */
	public function block_admin(): void {
		if ( wp_doing_ajax() ) {
			return;
		}

		if ( current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( ! is_user_logged_in() ) {
			return;
		}

		wp_safe_redirect( Page_Installer::url( 'dashboard' ) );
		exit;
	}

	/**
	 * Hide the admin bar on the public frontend (all roles).
	 *
	 * @param bool $show Whether to show the admin bar.
	 * @return bool
	 */
	public function hide_admin_bar( bool $show ): bool {
		if ( is_admin() ) {
			return $show;
		}

		/**
		 * Filter whether the WordPress admin bar is hidden on the public site.
		 *
		 * @param bool $hide Default true.
		 */
		if ( apply_filters( 'prose_hide_admin_bar_on_frontend', true ) ) {
			return false;
		}

		return $show;
	}

	/**
	 * Remove the html margin-top bump WordPress adds for the admin bar.
	 *
	 * @return void
	 */
	public function disable_admin_bar_bump(): void {
		if ( is_admin() ) {
			return;
		}

		remove_action( 'wp_head', '_admin_bar_bump_cb' );
	}

	/**
	 * Dequeue admin bar assets on the public frontend.
	 *
	 * @return void
	 */
	public function dequeue_admin_bar_assets(): void {
		if ( is_admin() ) {
			return;
		}

		wp_dequeue_style( 'admin-bar' );
		wp_deregister_style( 'admin-bar' );
		wp_dequeue_script( 'admin-bar' );
		wp_deregister_script( 'admin-bar' );
	}

	/**
	 * Strip admin-bar body class so theme layout (sticky headers) stays flush.
	 *
	 * @param string[] $classes Body classes.
	 * @return string[]
	 */
	public function strip_admin_bar_body_class( array $classes ): array {
		if ( is_admin() ) {
			return $classes;
		}

		return array_values( array_diff( $classes, array( 'admin-bar' ) ) );
	}

	/**
	 * Send prose_client users to the dashboard after login.
	 *
	 * @param string           $redirect_to           Redirect URL.
	 * @param string           $requested_redirect_to Requested redirect.
	 * @param \WP_User|WP_Error $user                  User object.
	 * @return string
	 */
	public function login_redirect( string $redirect_to, string $requested_redirect_to, $user ): string {
		if ( $user instanceof \WP_Error ) {
			return $redirect_to;
		}

		if ( ! $user instanceof \WP_User ) {
			return $redirect_to;
		}

		if ( user_can( $user, 'manage_options' ) ) {
			return $redirect_to;
		}

		if ( '' !== $requested_redirect_to && wp_validate_redirect( $requested_redirect_to, false ) ) {
			return $requested_redirect_to;
		}

		return Page_Installer::url( 'dashboard' );
	}
}
