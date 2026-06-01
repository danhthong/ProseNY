<?php
/**
 * Template Name: CourtFlow Workspace
 * Full-width guided procedural workspace.
 *
 * @package ProseApp
 */

get_header();
?>
<main id="primary" class="site-main courtflow-page">
	<?php
	while ( have_posts() ) {
		the_post();
		the_content();
	}
	?>
</main>
<?php
get_footer();
