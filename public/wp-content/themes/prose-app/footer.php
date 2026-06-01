<?php
/**
 * Footer template.
 *
 * @package ProseApp
 */

use ProseApp\Courtflow;
?>
<?php if ( ! Courtflow\is_workspace_page() ) : ?>
</main>
<?php endif; ?>

<footer class="border-t border-gray-200">
	<div class="mx-auto max-w-5xl px-4 py-8 text-sm text-gray-600">
		<p>
			&copy; <?php echo esc_html( gmdate( 'Y' ) ); ?>
			<a href="<?php echo esc_url( home_url( '/' ) ); ?>" class="font-medium text-gray-900 no-underline">
				<?php bloginfo( 'name' ); ?>
			</a>
		</p>
	</div>
</footer>

<?php wp_footer(); ?>
</body>
</html>
