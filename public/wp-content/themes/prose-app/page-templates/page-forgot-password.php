<?php
/**
 * Template Name: ProSe Forgot Password
 *
 * @package ProseApp
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use ProseApp\Users;

$sent  = isset( $_GET['prose_sent'] ) && '1' === (string) $_GET['prose_sent']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$error = isset( $_GET['prose_error'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['prose_error'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

get_template_part( 'template-parts/prose-site-shell-start' );
?>
<main id="primary" class="site-main prose-auth-page">
	<div class="prose-auth-card">
		<h1 class="prose-auth-card__title"><?php esc_html_e( 'Forgot your password?', 'prose-app' ); ?></h1>
		<p class="prose-auth-lead"><?php esc_html_e( 'Enter the email address for your account and we will send you a link to reset your password.', 'prose-app' ); ?></p>

		<?php if ( $sent ) : ?>
			<p class="prose-auth-success" role="status">
				<?php esc_html_e( 'If an account exists for that email, you will receive a password reset link shortly. Check your inbox and spam folder.', 'prose-app' ); ?>
			</p>
		<?php else : ?>
			<?php if ( '' !== $error ) : ?>
				<p class="prose-auth-error" role="alert"><?php echo esc_html( $error ); ?></p>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="prose-auth-form">
				<input type="hidden" name="action" value="prose_forgot_password">
				<?php wp_nonce_field( 'prose_forgot_password', 'prose_forgot_password_nonce' ); ?>

				<div class="prose-field">
					<label for="user_login"><?php esc_html_e( 'Email or username', 'prose-app' ); ?></label>
					<input type="text" name="user_login" id="user_login" class="prose-input" required autocomplete="username">
				</div>

				<button type="submit" class="prose-btn prose-btn--primary prose-btn--block"><?php esc_html_e( 'Send reset link', 'prose-app' ); ?></button>
			</form>
		<?php endif; ?>

		<p class="prose-auth-footer">
			<a href="<?php echo esc_url( Users\login_url() ); ?>"><?php esc_html_e( 'Back to log in', 'prose-app' ); ?></a>
			<span aria-hidden="true"> · </span>
			<a href="<?php echo esc_url( Users\register_url() ); ?>"><?php esc_html_e( 'Create an account', 'prose-app' ); ?></a>
		</p>
	</div>
</main>
<?php
get_template_part( 'template-parts/prose-site-shell-end' );
