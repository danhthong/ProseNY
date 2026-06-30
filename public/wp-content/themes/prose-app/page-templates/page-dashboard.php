<?php
/**
 * Template Name: ProSe Dashboard
 *
 * @package ProseApp
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

get_template_part( 'template-parts/prose-site-shell-start' );
?>
<main id="primary" class="site-main prose-dashboard-page">
	<div class="prose-dashboard">
		<header class="prose-dashboard__header">
			<h1 class="prose-dashboard__title"><?php esc_html_e( 'Your Dashboard', 'prose-app' ); ?></h1>
			<p id="prose-dashboard-greeting" class="prose-dashboard__greeting"><?php esc_html_e( 'Loading…', 'prose-app' ); ?></p>
		</header>

		<div id="prose-dashboard-status" class="prose-dashboard__status" aria-live="polite"></div>

		<div class="prose-dashboard__stack">
			<section class="prose-dashboard__cases-intro" aria-labelledby="prose-cases-title">
				<h2 id="prose-cases-title" class="prose-dashboard__cases-title"><?php esc_html_e( 'Your cases', 'prose-app' ); ?></h2>
				<p class="prose-dashboard__cases-lead"><?php esc_html_e( 'Each conversation is a case record. Expand a row to see progress, lifecycle, courts, and documents. The most recent case opens by default.', 'prose-app' ); ?></p>
			</section>

			<div id="prose-conversation-records" class="prose-dashboard__records"></div>

			<section class="prose-dashboard__widget prose-dashboard__widget--subscription" aria-labelledby="prose-subscription-title">
				<h2 id="prose-subscription-title" class="prose-dashboard__widget-title"><?php esc_html_e( 'Subscription', 'prose-app' ); ?></h2>
				<div id="prose-subscription" class="prose-dashboard__widget-body"></div>
			</section>
		</div>
	</div>
</main>
<?php
get_template_part( 'template-parts/prose-site-shell-end' );
