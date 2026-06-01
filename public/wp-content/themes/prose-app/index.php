<?php
/**
 * Main template.
 *
 * @package ProseApp
 */

get_header();
?>

<div class="grid gap-10 lg:grid-cols-[minmax(0,1fr)_16rem]">
	<div>
		<?php if ( have_posts() ) : ?>
			<?php while ( have_posts() ) : ?>
				<?php the_post(); ?>
				<?php get_template_part( 'template-parts/content', get_post_type() ); ?>
			<?php endwhile; ?>

			<nav class="mt-10 flex justify-between gap-4 text-sm" aria-label="<?php esc_attr_e( 'Posts navigation', 'prose-app' ); ?>">
				<div><?php previous_posts_link( __( '&larr; Newer posts', 'prose-app' ) ); ?></div>
				<div><?php next_posts_link( __( 'Older posts &rarr;', 'prose-app' ) ); ?></div>
			</nav>
		<?php else : ?>
			<?php get_template_part( 'template-parts/content', 'none' ); ?>
		<?php endif; ?>
	</div>

	<?php get_sidebar(); ?>
</div>

<?php
get_footer();
