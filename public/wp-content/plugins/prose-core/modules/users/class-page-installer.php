<?php
/**
 * Creates frontend auth and dashboard pages on activation.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Users;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Page_Installer
 */
final class Page_Installer {

	public const OPT_REGISTER        = 'prose_page_register';
	public const OPT_LOGIN           = 'prose_page_login';
	public const OPT_DASHBOARD       = 'prose_page_dashboard';
	public const OPT_FORGOT_PASSWORD = 'prose_page_forgot_password';
	public const OPT_RESET_PASSWORD  = 'prose_page_reset_password';

	/**
	 * Page definitions keyed by slug.
	 *
	 * @return array<string, array{title: string, template: string, option: string}>
	 */
	public static function pages(): array {
		return array(
			'register'  => array(
				'title'    => __( 'Register', 'prose-core' ),
				'template' => 'page-templates/page-register.php',
				'option'   => self::OPT_REGISTER,
			),
			'login'     => array(
				'title'    => __( 'Log In', 'prose-core' ),
				'template' => 'page-templates/page-login.php',
				'option'   => self::OPT_LOGIN,
			),
			'dashboard'       => array(
				'title'    => __( 'Dashboard', 'prose-core' ),
				'template' => 'page-templates/page-dashboard.php',
				'option'   => self::OPT_DASHBOARD,
			),
			'forgot-password' => array(
				'title'    => __( 'Forgot Password', 'prose-core' ),
				'template' => 'page-templates/page-forgot-password.php',
				'option'   => self::OPT_FORGOT_PASSWORD,
			),
			'reset-password'  => array(
				'title'    => __( 'Reset Password', 'prose-core' ),
				'template' => 'page-templates/page-reset-password.php',
				'option'   => self::OPT_RESET_PASSWORD,
			),
		);
	}

	/**
	 * Create pages if they do not exist.
	 *
	 * @return void
	 */
	public static function install(): void {
		foreach ( self::pages() as $slug => $def ) {
			self::ensure_page( $slug, $def );
		}
	}

	/**
	 * Permalink for a ProSe page slug.
	 *
	 * @param string $slug register|login|dashboard|forgot-password|reset-password.
	 * @return string
	 */
	public static function url( string $slug ): string {
		$pages = self::pages();

		if ( ! isset( $pages[ $slug ] ) ) {
			return home_url( '/' );
		}

		$page_id = (int) get_option( $pages[ $slug ]['option'], 0 );

		if ( $page_id > 0 ) {
			$link = get_permalink( $page_id );

			if ( is_string( $link ) && '' !== $link ) {
				return $link;
			}
		}

		return home_url( '/' . $slug . '/' );
	}

	/**
	 * Ensure a single page exists.
	 *
	 * @param string               $slug Page slug.
	 * @param array<string, mixed> $def  Page definition.
	 * @return void
	 */
	private static function ensure_page( string $slug, array $def ): void {
		$option  = (string) ( $def['option'] ?? '' );
		$page_id = (int) get_option( $option, 0 );

		if ( $page_id > 0 && get_post( $page_id ) instanceof \WP_Post ) {
			return;
		}

		$existing = get_page_by_path( $slug );

		if ( $existing instanceof \WP_Post ) {
			update_post_meta( $existing->ID, '_wp_page_template', (string) ( $def['template'] ?? '' ) );
			update_option( $option, $existing->ID );

			return;
		}

		$page_id = wp_insert_post(
			array(
				'post_title'   => (string) ( $def['title'] ?? ucfirst( $slug ) ),
				'post_name'    => $slug,
				'post_status'  => 'publish',
				'post_type'    => 'page',
				'post_content' => '',
			),
			true
		);

		if ( is_wp_error( $page_id ) ) {
			return;
		}

		update_post_meta( (int) $page_id, '_wp_page_template', (string) ( $def['template'] ?? '' ) );
		update_option( $option, (int) $page_id );
	}
}
