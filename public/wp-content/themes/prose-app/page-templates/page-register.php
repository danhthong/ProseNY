<?php
/**
 * Template Name: ProSe Register
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

get_template_part( 'template-parts/prose-site-shell-start' );
?>
<main id="primary" class="site-main prose-auth-page">
	<div class="prose-auth-card">
		<h1 class="prose-auth-card__title"><?php esc_html_e( 'Create your account', 'prose-app' ); ?></h1>
		<p class="prose-auth-lead"><?php esc_html_e( 'Save your progress, generate documents, and return to your case anytime.', 'prose-app' ); ?></p>

		<?php if ( '' !== $error ) : ?>
			<p class="prose-auth-error" role="alert"><?php echo esc_html( $error ); ?></p>
		<?php endif; ?>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="prose-auth-form">
			<input type="hidden" name="action" value="prose_register">
			<?php wp_nonce_field( 'prose_register', 'prose_register_nonce' ); ?>
			<input type="hidden" name="redirect_to" value="<?php echo esc_attr( $redirect ); ?>">
			<input type="hidden" name="session_id" id="prose-session-id" value="<?php echo esc_attr( $session ); ?>">

			<div class="prose-field">
				<label for="display_name"><?php esc_html_e( 'Your name', 'prose-app' ); ?></label>
				<input type="text" name="display_name" id="display_name" class="prose-input" autocomplete="name">
			</div>

			<div class="prose-field">
				<label for="user_email"><?php esc_html_e( 'Email', 'prose-app' ); ?></label>
				<input type="email" name="user_email" id="user_email" class="prose-input" required autocomplete="email">
			</div>

			<div class="prose-field">
				<label for="user_pass"><?php esc_html_e( 'Password', 'prose-app' ); ?></label>
				<input type="password" name="user_pass" id="user_pass" class="prose-input" required autocomplete="new-password" minlength="8">
			</div>

			<button type="submit" class="prose-btn prose-btn--primary prose-btn--block"><?php esc_html_e( 'Register', 'prose-app' ); ?></button>
		</form>

		<p class="prose-auth-footer">
			<a href="<?php echo esc_url( Users\login_url( $redirect ) ); ?>"><?php esc_html_e( 'Already have an account? Log in', 'prose-app' ); ?></a>
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
