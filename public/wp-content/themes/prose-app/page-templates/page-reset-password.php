<?php
/**
 * Template Name: ProSe Reset Password
 *
 * @package ProseApp
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use ProseApp\Users;

$key   = isset( $_GET['key'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['key'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$login = isset( $_GET['login'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['login'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$error = isset( $_GET['prose_error'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['prose_error'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

$reset_user = null;
$key_error  = '';

if ( '' !== $key && '' !== $login ) {
	$checked = check_password_reset_key( $key, $login );

	if ( is_wp_error( $checked ) ) {
		$key_error = $checked->get_error_message();
	} else {
		$reset_user = $checked;
	}
} else {
	$key_error = __( 'This password reset link is invalid or has expired.', 'prose-app' );
}

get_template_part( 'template-parts/prose-site-shell-start' );
?>
<main id="primary" class="site-main prose-auth-page">
	<div class="prose-auth-card">
		<h1 class="prose-auth-card__title"><?php esc_html_e( 'Reset your password', 'prose-app' ); ?></h1>

		<?php if ( $reset_user instanceof WP_User ) : ?>
			<p class="prose-auth-lead">
				<?php
				printf(
					/* translators: %s: user login */
					esc_html__( 'Choose a new password for %s.', 'prose-app' ),
					esc_html( $reset_user->user_login )
				);
				?>
			</p>

			<?php if ( '' !== $error ) : ?>
				<p class="prose-auth-error" role="alert"><?php echo esc_html( $error ); ?></p>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="prose-auth-form">
				<input type="hidden" name="action" value="prose_reset_password">
				<?php wp_nonce_field( 'prose_reset_password', 'prose_reset_password_nonce' ); ?>
				<input type="hidden" name="reset_key" value="<?php echo esc_attr( $key ); ?>">
				<input type="hidden" name="reset_login" value="<?php echo esc_attr( $login ); ?>">

				<div class="prose-field">
					<label for="user_pass"><?php esc_html_e( 'New password', 'prose-app' ); ?></label>
					<input type="password" name="user_pass" id="user_pass" class="prose-input" required autocomplete="new-password" minlength="8">
				</div>

				<div class="prose-field">
					<label for="user_pass_confirm"><?php esc_html_e( 'Confirm new password', 'prose-app' ); ?></label>
					<input type="password" name="user_pass_confirm" id="user_pass_confirm" class="prose-input" required autocomplete="new-password" minlength="8">
				</div>

				<button type="submit" class="prose-btn prose-btn--primary prose-btn--block"><?php esc_html_e( 'Update password', 'prose-app' ); ?></button>
			</form>
		<?php else : ?>
			<p class="prose-auth-error" role="alert"><?php echo esc_html( $key_error ); ?></p>
			<p class="prose-auth-lead"><?php esc_html_e( 'Request a new reset link to try again.', 'prose-app' ); ?></p>
			<p class="mt-6">
				<a href="<?php echo esc_url( Users\forgot_password_url() ); ?>" class="prose-btn prose-btn--primary prose-btn--block"><?php esc_html_e( 'Request new link', 'prose-app' ); ?></a>
			</p>
		<?php endif; ?>

		<p class="prose-auth-footer">
			<a href="<?php echo esc_url( Users\login_url() ); ?>"><?php esc_html_e( 'Back to log in', 'prose-app' ); ?></a>
		</p>
	</div>
</main>
<?php
get_template_part( 'template-parts/prose-site-shell-end' );
