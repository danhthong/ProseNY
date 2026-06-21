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
	 * Hide the admin bar for non-admin users.
	 *
	 * @param bool $show Whether to show the admin bar.
	 * @return bool
	 */
	public function hide_admin_bar( bool $show ): bool {
		if ( current_user_can( 'manage_options' ) ) {
			return $show;
		}

		if ( is_user_logged_in() ) {
			return false;
		}

		return $show;
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
