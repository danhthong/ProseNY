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

		<div class="prose-dashboard__grid">
			<section class="prose-dashboard__widget" aria-labelledby="prose-case-progress-title">
				<h2 id="prose-case-progress-title" class="prose-dashboard__widget-title"><?php esc_html_e( 'Case Progress', 'prose-app' ); ?></h2>
				<div id="prose-case-progress" class="prose-dashboard__widget-body"></div>
			</section>

			<section class="prose-dashboard__widget" aria-labelledby="prose-subscription-title">
				<h2 id="prose-subscription-title" class="prose-dashboard__widget-title"><?php esc_html_e( 'Subscription', 'prose-app' ); ?></h2>
				<div id="prose-subscription" class="prose-dashboard__widget-body"></div>
			</section>

			<section class="prose-dashboard__widget prose-dashboard__widget--wide" aria-labelledby="prose-conversations-title">
				<h2 id="prose-conversations-title" class="prose-dashboard__widget-title"><?php esc_html_e( 'Recent Conversations', 'prose-app' ); ?></h2>
				<div id="prose-conversations" class="prose-dashboard__widget-body"></div>
			</section>

			<section class="prose-dashboard__widget prose-dashboard__widget--wide" aria-labelledby="prose-documents-title">
				<h2 id="prose-documents-title" class="prose-dashboard__widget-title"><?php esc_html_e( 'Generated Documents', 'prose-app' ); ?></h2>
				<div id="prose-documents" class="prose-dashboard__widget-body"></div>
			</section>
		</div>
	</div>
</main>
<?php
get_template_part( 'template-parts/prose-site-shell-end' );
