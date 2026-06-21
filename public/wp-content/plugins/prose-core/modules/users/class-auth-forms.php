<?php
/**
 * Branded login and registration forms using WordPress native auth APIs.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Users;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Auth_Forms
 */
final class Auth_Forms {

	public const ACTION_LOGIN           = 'prose_login';
	public const ACTION_REGISTER        = 'prose_register';
	public const ACTION_FORGOT_PASSWORD = 'prose_forgot_password';
	public const ACTION_RESET_PASSWORD  = 'prose_reset_password';

	/**
	 * @var Session_Claim_Service
	 */
	private Session_Claim_Service $claim;

	/**
	 * Constructor.
	 *
	 * @param Session_Claim_Service|null $claim Session claim service.
	 */
	public function __construct( ?Session_Claim_Service $claim = null ) {
		$this->claim = $claim ?? new Session_Claim_Service();
	}

	/**
	 * Register admin-post handlers.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_post_nopriv_' . self::ACTION_LOGIN, array( $this, 'handle_login' ) );
		add_action( 'admin_post_' . self::ACTION_LOGIN, array( $this, 'handle_login' ) );
		add_action( 'admin_post_nopriv_' . self::ACTION_REGISTER, array( $this, 'handle_register' ) );
		add_action( 'admin_post_' . self::ACTION_REGISTER, array( $this, 'handle_register' ) );
		add_action( 'admin_post_nopriv_' . self::ACTION_FORGOT_PASSWORD, array( $this, 'handle_forgot_password' ) );
		add_action( 'admin_post_' . self::ACTION_FORGOT_PASSWORD, array( $this, 'handle_forgot_password' ) );
		add_action( 'admin_post_nopriv_' . self::ACTION_RESET_PASSWORD, array( $this, 'handle_reset_password' ) );
		add_action( 'admin_post_' . self::ACTION_RESET_PASSWORD, array( $this, 'handle_reset_password' ) );

		add_filter( 'lostpassword_url', array( $this, 'filter_lostpassword_url' ), 10, 2 );
		add_filter( 'retrieve_password_notification_email', array( $this, 'filter_reset_notification_email' ), 10, 4 );
		add_action( 'login_init', array( $this, 'redirect_wp_reset_links' ) );
	}

	/**
	 * Handle login form submission.
	 *
	 * @return void
	 */
	public function handle_login(): void {
		if ( ! isset( $_POST['prose_login_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['prose_login_nonce'] ) ), self::ACTION_LOGIN ) ) {
			wp_die( esc_html__( 'Invalid login request.', 'prose-core' ), 403 );
		}

		$username = sanitize_text_field( wp_unslash( (string) ( $_POST['log'] ?? '' ) ) );
		$password = (string) ( $_POST['pwd'] ?? '' );
		$redirect = esc_url_raw( wp_unslash( (string) ( $_POST['redirect_to'] ?? Page_Installer::url( 'dashboard' ) ) ) );
		$session  = sanitize_text_field( wp_unslash( (string) ( $_POST['session_id'] ?? '' ) ) );

		$user = wp_signon(
			array(
				'user_login'    => $username,
				'user_password' => $password,
				'remember'      => ! empty( $_POST['rememberme'] ),
			),
			is_ssl()
		);

		if ( is_wp_error( $user ) ) {
			$this->redirect_with_error( Page_Installer::url( 'login' ), $user->get_error_message(), $redirect );
		}

		if ( '' !== $session ) {
			$this->claim->claim_for_user( (int) $user->ID, $session );
		}

		wp_safe_redirect( wp_validate_redirect( $redirect, Page_Installer::url( 'dashboard' ) ) );
		exit;
	}

	/**
	 * Handle registration form submission.
	 *
	 * @return void
	 */
	public function handle_register(): void {
		if ( ! get_option( 'users_can_register' ) ) {
			wp_die( esc_html__( 'Registration is disabled.', 'prose-core' ), 403 );
		}

		if ( ! isset( $_POST['prose_register_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['prose_register_nonce'] ) ), self::ACTION_REGISTER ) ) {
			wp_die( esc_html__( 'Invalid registration request.', 'prose-core' ), 403 );
		}

		$email      = sanitize_email( wp_unslash( (string) ( $_POST['user_email'] ?? '' ) ) );
		$password   = (string) ( $_POST['user_pass'] ?? '' );
		$display    = sanitize_text_field( wp_unslash( (string) ( $_POST['display_name'] ?? '' ) ) );
		$redirect   = esc_url_raw( wp_unslash( (string) ( $_POST['redirect_to'] ?? Page_Installer::url( 'dashboard' ) ) ) );
		$session    = sanitize_text_field( wp_unslash( (string) ( $_POST['session_id'] ?? '' ) ) );

		if ( ! is_email( $email ) ) {
			$this->redirect_with_error( Page_Installer::url( 'register' ), __( 'Please enter a valid email address.', 'prose-core' ), $redirect );
		}

		if ( strlen( $password ) < 8 ) {
			$this->redirect_with_error( Page_Installer::url( 'register' ), __( 'Password must be at least 8 characters.', 'prose-core' ), $redirect );
		}

		if ( email_exists( $email ) ) {
			$this->redirect_with_error( Page_Installer::url( 'register' ), __( 'An account with this email already exists.', 'prose-core' ), $redirect );
		}

		$user_id = wp_create_user( $email, $password, $email );

		if ( is_wp_error( $user_id ) ) {
			$this->redirect_with_error( Page_Installer::url( 'register' ), $user_id->get_error_message(), $redirect );
		}

		if ( '' !== $display ) {
			wp_update_user(
				array(
					'ID'           => (int) $user_id,
					'display_name' => $display,
				)
			);
		}

		Role_Registrar::assign_client_role( (int) $user_id );

		wp_set_current_user( (int) $user_id );
		wp_set_auth_cookie( (int) $user_id, true );

		if ( '' !== $session ) {
			$this->claim->claim_for_user( (int) $user_id, $session );
		}

		do_action( 'prose_user_registered', (int) $user_id, $session );

		wp_safe_redirect( wp_validate_redirect( $redirect, Page_Installer::url( 'dashboard' ) ) );
		exit;
	}

	/**
	 * Handle forgot-password form submission.
	 *
	 * @return void
	 */
	public function handle_forgot_password(): void {
		if ( ! isset( $_POST['prose_forgot_password_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['prose_forgot_password_nonce'] ) ), self::ACTION_FORGOT_PASSWORD ) ) {
			wp_die( esc_html__( 'Invalid password reset request.', 'prose-core' ), 403 );
		}

		$user_login = sanitize_text_field( wp_unslash( (string) ( $_POST['user_login'] ?? '' ) ) );

		if ( '' !== $user_login ) {
			retrieve_password( $user_login );
		}

		wp_safe_redirect(
			add_query_arg( 'prose_sent', '1', Page_Installer::url( 'forgot-password' ) )
		);
		exit;
	}

	/**
	 * Handle reset-password form submission.
	 *
	 * @return void
	 */
	public function handle_reset_password(): void {
		if ( ! isset( $_POST['prose_reset_password_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['prose_reset_password_nonce'] ) ), self::ACTION_RESET_PASSWORD ) ) {
			wp_die( esc_html__( 'Invalid password reset request.', 'prose-core' ), 403 );
		}

		$key   = sanitize_text_field( wp_unslash( (string) ( $_POST['reset_key'] ?? '' ) ) );
		$login = sanitize_text_field( wp_unslash( (string) ( $_POST['reset_login'] ?? '' ) ) );
		$pass1 = (string) ( $_POST['user_pass'] ?? '' );
		$pass2 = (string) ( $_POST['user_pass_confirm'] ?? '' );

		$user = check_password_reset_key( $key, $login );

		if ( is_wp_error( $user ) ) {
			$this->redirect_with_error( Page_Installer::url( 'reset-password' ), $user->get_error_message() );
		}

		if ( strlen( $pass1 ) < 8 ) {
			$this->redirect_with_reset_error( $key, $login, __( 'Password must be at least 8 characters.', 'prose-core' ) );
		}

		if ( $pass1 !== $pass2 ) {
			$this->redirect_with_reset_error( $key, $login, __( 'Passwords do not match.', 'prose-core' ) );
		}

		reset_password( $user, $pass1 );

		wp_safe_redirect(
			add_query_arg( 'prose_reset', '1', Page_Installer::url( 'login' ) )
		);
		exit;
	}

	/**
	 * Point WordPress lost-password links to the branded page.
	 *
	 * @param string $url      Default URL.
	 * @param string $redirect Redirect target.
	 * @return string
	 */
	public function filter_lostpassword_url( string $url, string $redirect ): string {
		unset( $url );

		$target = Page_Installer::url( 'forgot-password' );

		if ( '' !== $redirect ) {
			$target = add_query_arg( 'redirect_to', rawurlencode( $redirect ), $target );
		}

		return $target;
	}

	/**
	 * Customize the password-reset email with a branded reset URL.
	 *
	 * @param array<string, string> $defaults   Email parts.
	 * @param string                $key        Reset key.
	 * @param string                $user_login User login.
	 * @param \WP_User              $user_data  User object.
	 * @return array<string, string>
	 */
	public function filter_reset_notification_email( array $defaults, string $key, string $user_login, $user_data ): array {
		unset( $user_data );

		$reset_url = add_query_arg(
			array(
				'key'   => $key,
				'login' => rawurlencode( $user_login ),
			),
			Page_Installer::url( 'reset-password' )
		);

		$defaults['subject'] = sprintf(
			/* translators: %s: site name */
			__( '[%s] Reset your password', 'prose-core' ),
			wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES )
		);

		$defaults['message'] = sprintf(
			/* translators: 1: user login, 2: site name, 3: reset URL */
			__(
				"Someone requested a password reset for the account %1\$s on %2\$s.\r\n\r\nIf this was you, reset your password here:\r\n%3\$s\r\n\r\nIf you did not request this, you can ignore this email.",
				'prose-core'
			),
			$user_login,
			wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
			$reset_url
		);

		return $defaults;
	}

	/**
	 * Redirect default wp-login.php reset links to the branded page.
	 *
	 * @return void
	 */
	public function redirect_wp_reset_links(): void {
		if ( ! isset( $_GET['action'] ) || 'rp' !== $_GET['action'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		$key   = isset( $_GET['key'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['key'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$login = isset( $_GET['login'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['login'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( '' === $key || '' === $login ) {
			return;
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'key'   => $key,
					'login' => rawurlencode( $login ),
				),
				Page_Installer::url( 'reset-password' )
			)
		);
		exit;
	}

	/**
	 * @param string $url      Form URL.
	 * @param string $message  Error message.
	 * @param string $redirect Redirect target.
	 * @return void
	 */
	private function redirect_with_error( string $url, string $message, string $redirect = '' ): void {
		$args = array(
			'prose_error' => rawurlencode( $message ),
		);

		if ( '' !== $redirect ) {
			$args['redirect_to'] = rawurlencode( $redirect );
		}

		wp_safe_redirect( add_query_arg( $args, $url ) );
		exit;
	}

	/**
	 * Redirect back to reset form preserving key/login query args.
	 *
	 * @param string $key     Reset key.
	 * @param string $login   User login.
	 * @param string $message Error message.
	 * @return void
	 */
	private function redirect_with_reset_error( string $key, string $login, string $message ): void {
		wp_safe_redirect(
			add_query_arg(
				array(
					'key'         => $key,
					'login'       => rawurlencode( $login ),
					'prose_error' => rawurlencode( $message ),
				),
				Page_Installer::url( 'reset-password' )
			)
		);
		exit;
	}
}
