<?php
/**
 * Default post content partial.
 *
 * @package ProseApp
 */
?>
<article id="post-<?php the_ID(); ?>" <?php post_class( 'mb-12 border-b border-gray-200 pb-12 last:mb-0 last:border-b-0 last:pb-0' ); ?>>
	<header class="mb-4">
		<?php the_title( sprintf( '<h2 class="text-2xl font-semibold tracking-tight"><a href="%s" class="no-underline">', esc_url( get_permalink() ) ), '</a></h2>' ); ?>

		<div class="mt-2 text-sm text-gray-600">
			<time datetime="<?php echo esc_attr( get_the_date( DATE_W3C ) ); ?>">
				<?php echo esc_html( get_the_date() ); ?>
			</time>
			<?php if ( ! is_single() ) : ?>
				<span aria-hidden="true"> &middot; </span>
				<span><?php the_author(); ?></span>
			<?php endif; ?>
		</div>
	</header>

	<div class="prose max-w-none text-gray-800">
		<?php if ( is_single() ) : ?>
			<?php the_content(); ?>
		<?php else : ?>
			<?php the_excerpt(); ?>
		<?php endif; ?>
	</div>
</article>
