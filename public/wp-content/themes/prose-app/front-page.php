<?php
/**
 * Front page template (chat-first landing).
 *
 * Matches Figma Homepage / Desktop (file: xVPgq7caO1qyHwi7EbkPRw, node 30:2):
 * 95% viewport width, 30% left sidebar, 70% chat column.
 *
 * @package ProseApp
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

get_template_part( 'template-parts/prose-site-shell-start' );
?>
<main id="content" class="prose-homepage flex flex-1 justify-center pb-[180px] pt-12 md:pb-12 md:pt-16">
	<?php
	if ( shortcode_exists( 'prose_intake_chat' ) ) {
		echo do_shortcode( '[prose_intake_chat layout="homepage"]' );
	}
	?>
</main>
<?php
get_template_part( 'template-parts/prose-site-shell-end' );
