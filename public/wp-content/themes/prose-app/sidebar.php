<?php
/**
 * Sidebar template.
 *
 * @package ProseApp
 */

if ( ! is_active_sidebar( 'sidebar-1' ) ) {
	return;
}
?>

<aside class="space-y-8" aria-label="<?php esc_attr_e( 'Sidebar', 'prose-app' ); ?>">
	<?php dynamic_sidebar( 'sidebar-1' ); ?>
</aside>
