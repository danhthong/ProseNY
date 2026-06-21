<?php
/**
 * Template Name: ProSe Login
 *
 * @package ProseApp
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use ProseApp\Users;

$redirect = isset( $_GET['redirect_to'] ) ? esc_url_raw( wp_unslash( (string) $_GET['redirect_to'] ) ) : Users\dashboard_url(); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$error    = isset( $_GET['prose_error'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['prose_error'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$session  = isset( $_GET['session_id'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['session_id'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$reset    = isset( $_GET['prose_reset'] ) && '1' === (string) $_GET['prose_reset']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

get_template_part( 'template-parts/prose-site-shell-start' );
?>
<main id="primary" class="site-main prose-auth-page">
	<div class="prose-auth-card">
		<h1 class="prose-auth-card__title"><?php esc_html_e( 'Log in', 'prose-app' ); ?></h1>
		<p class="prose-auth-lead"><?php esc_html_e( 'Access your case dashboard and saved conversations.', 'prose-app' ); ?></p>

		<?php if ( $reset ) : ?>
			<p class="prose-auth-success" role="status"><?php esc_html_e( 'Your password has been updated. You can log in with your new password.', 'prose-app' ); ?></p>
		<?php endif; ?>

		<?php if ( '' !== $error ) : ?>
			<p class="prose-auth-error" role="alert"><?php echo esc_html( $error ); ?></p>
		<?php endif; ?>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="prose-auth-form">
			<input type="hidden" name="action" value="prose_login">
			<?php wp_nonce_field( 'prose_login', 'prose_login_nonce' ); ?>
			<input type="hidden" name="redirect_to" value="<?php echo esc_attr( $redirect ); ?>">
			<input type="hidden" name="session_id" id="prose-session-id" value="<?php echo esc_attr( $session ); ?>">

			<div class="prose-field">
				<label for="user_login"><?php esc_html_e( 'Email or username', 'prose-app' ); ?></label>
				<input type="text" name="log" id="user_login" class="prose-input" required autocomplete="username">
			</div>

			<div class="prose-field">
				<label for="user_pass"><?php esc_html_e( 'Password', 'prose-app' ); ?></label>
				<input type="password" name="pwd" id="user_pass" class="prose-input" required autocomplete="current-password">
			</div>

			<div class="prose-auth-remember">
				<label class="prose-checkbox">
					<input type="checkbox" name="rememberme" value="1">
					<span><?php esc_html_e( 'Remember me', 'prose-app' ); ?></span>
				</label>
			</div>

			<button type="submit" class="prose-btn prose-btn--primary prose-btn--block"><?php esc_html_e( 'Log in', 'prose-app' ); ?></button>
		</form>

		<p class="prose-auth-footer">
			<a href="<?php echo esc_url( Users\forgot_password_url() ); ?>"><?php esc_html_e( 'Forgot password?', 'prose-app' ); ?></a>
			<span aria-hidden="true"> · </span>
			<a href="<?php echo esc_url( Users\register_url( $redirect ) ); ?>"><?php esc_html_e( 'Create an account', 'prose-app' ); ?></a>
		</p>
	</div>
</main>
<script>
(function () {
	try {
		var stored = window.localStorage.getItem('courtflow_session_id');
		var field = document.getElementById('prose-session-id');
		if (stored && field && !field.value) {
			field.value = stored;
		}
	} catch (e) {}
})();
</script>
<?php
get_template_part( 'template-parts/prose-site-shell-end' );
