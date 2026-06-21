<?php
/**
 * Front page template (chat-first landing).
 *
 * Static UI built to match the Figma "Homepage" template
 * (file: xVPgq7caO1qyHwi7EbkPRw, node 30:2).
 *
 * @package ProseApp
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use ProseApp\Courtflow;

get_template_part( 'template-parts/prose-site-shell-start' );
?>
<main id="content" class="flex flex-1 justify-center px-4 pb-[180px] pt-12 md:px-8 md:pb-12 md:pt-24">
	<div class="flex w-full max-w-[900px] flex-col items-center gap-6">
		<h1 class="text-center text-[28px] font-bold leading-tight text-slate-900 md:text-[36px] md:leading-[44px]">
			<?php esc_html_e( 'How can I help you today?', 'prose-app' ); ?>
		</h1>
		<p class="max-w-[620px] text-center text-[16px] leading-[26px] text-slate-500">
			<?php esc_html_e( 'Guided intake for NYC divorce and Family Court matters — Supreme Court matrimonial filings, custody, support, and orders of protection.', 'prose-app' ); ?>
		</p>

		<div class="hidden h-4 w-px md:block" aria-hidden="true"></div>

		<div class="w-full md:w-[720px] md:max-w-full">
			<?php
			if ( shortcode_exists( 'prose_intake_chat' ) ) {
				echo do_shortcode( '[prose_intake_chat]' );
			}
			if ( shortcode_exists( 'prose_package_preview' ) ) {
				echo do_shortcode( '[prose_package_preview]' );
			}
			?>
			<div class="mt-4 w-full">
				<?php Courtflow\render_intake_disclaimer(); ?>
			</div>
		</div>

		<?php Courtflow\render_prompt_chip_cards(); ?>

		<div class="flex items-center justify-center gap-1.5">
			<svg class="size-[14px] text-slate-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
				<path d="M12 3l7 3v6c0 4.5-3 8-7 9-4-1-7-4.5-7-9V6z" />
			</svg>
			<span class="text-[13px] text-slate-500"><?php esc_html_e( 'Your conversations are private and secure.', 'prose-app' ); ?></span>
		</div>
	</div>
</main>
<?php
get_template_part( 'template-parts/prose-site-shell-end' );
