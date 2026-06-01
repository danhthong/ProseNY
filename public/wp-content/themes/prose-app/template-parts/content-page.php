<?php
/**
 * Page content partial.
 *
 * @package ProseApp
 */
?>
<article id="post-<?php the_ID(); ?>" <?php post_class(); ?>>
	<header class="mb-6">
		<?php the_title( '<h1 class="text-3xl font-semibold tracking-tight">', '</h1>' ); ?>
	</header>

	<div class="prose max-w-none text-gray-800">
		<?php the_content(); ?>
	</div>
</article>
