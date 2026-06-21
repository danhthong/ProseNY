<?php
/**
 * CourtFlow workspace header.
 *
 * @package ProseApp
 */

use ProseApp\Courtflow;
use ProseApp\Users;

$workflow_title = Courtflow\default_workflow_title();
?>
<header class="cf-workspace-header" role="banner">
	<div class="cf-workspace-header__brand">
		<a href="<?php echo esc_url( home_url( '/' ) ); ?>" class="cf-workspace-header__logo" aria-label="<?php esc_attr_e( 'CourtFlow AI home', 'prose-app' ); ?>">
			<span class="cf-workspace-header__logo-mark" aria-hidden="true">CF</span>
			<span class="cf-workspace-header__logo-text"><?php esc_html_e( 'CourtFlow AI', 'prose-app' ); ?></span>
		</a>
	</div>
	<div class="cf-workspace-header__center">
		<h1 class="cf-workspace-header__title" id="cf-workflow-title"><?php echo esc_html( $workflow_title ); ?></h1>
	</div>
	<div class="cf-workspace-header__actions">
		<span class="cf-badge cf-badge--status" id="cf-case-status" data-status="in-progress"><?php esc_html_e( 'In progress', 'prose-app' ); ?></span>
		<span class="cf-save-indicator" id="cf-save-indicator" aria-live="polite"><?php esc_html_e( 'Saved', 'prose-app' ); ?></span>
		<?php if ( is_user_logged_in() ) : ?>
			<div class="cf-user-menu">
				<button type="button" class="cf-user-menu__trigger" id="cf-user-menu-trigger" aria-expanded="false" aria-haspopup="true">
					<span class="cf-user-menu__avatar" aria-hidden="true"><?php echo esc_html( strtoupper( substr( wp_get_current_user()->display_name, 0, 1 ) ) ); ?></span>
				</button>
				<div class="cf-user-menu__dropdown" id="cf-user-menu-dropdown" hidden>
					<span class="cf-user-menu__name"><?php echo esc_html( wp_get_current_user()->display_name ); ?></span>
					<a href="<?php echo esc_url( Users\dashboard_url() ); ?>"><?php esc_html_e( 'Dashboard', 'prose-app' ); ?></a>
					<a href="<?php echo esc_url( wp_logout_url( get_permalink() ) ); ?>"><?php esc_html_e( 'Log out', 'prose-app' ); ?></a>
				</div>
			</div>
		<?php else : ?>
			<a href="<?php echo esc_url( Users\login_url( get_permalink() ) ); ?>" class="cf-btn cf-btn--ghost cf-btn--sm"><?php esc_html_e( 'Log in', 'prose-app' ); ?></a>
		<?php endif; ?>
	</div>
</header>
