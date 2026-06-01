<?php
/**
 * 404 template.
 *
 * @package ProseApp
 */

get_header();
?>

<section class="py-10 text-center">
	<h1 class="text-3xl font-semibold tracking-tight"><?php esc_html_e( 'Page not found', 'prose-app' ); ?></h1>
	<p class="mt-4 text-gray-600">
		<?php esc_html_e( 'The page you are looking for does not exist.', 'prose-app' ); ?>
	</p>
	<p class="mt-8">
		<a href="<?php echo esc_url( home_url( '/' ) ); ?>" class="font-medium text-gray-900 underline">
			<?php esc_html_e( 'Back to home', 'prose-app' ); ?>
		</a>
	</p>
</section>

<?php
get_footer();
