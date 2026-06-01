<?php
/**
 * Header template.
 *
 * @package ProseApp
 */

use ProseApp\Courtflow;
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<?php wp_head(); ?>
</head>
<body <?php body_class( 'bg-white text-gray-900 antialiased' ); ?>>
<?php wp_body_open(); ?>
<?php
if ( class_exists( '\Prose\Core\Security\Disclaimer' ) && ! Courtflow\is_workspace_page() ) {
	echo \Prose\Core\Security\Disclaimer::render_html();
}
?>
<header class="border-b border-gray-200">
	<div class="mx-auto flex max-w-5xl items-center justify-between gap-6 px-4 py-6">
		<div class="site-branding">
			<?php if ( is_front_page() && is_home() ) : ?>
				<h1 class="text-2xl font-semibold tracking-tight">
					<a href="<?php echo esc_url( home_url( '/' ) ); ?>" rel="home" class="no-underline">
						<?php bloginfo( 'name' ); ?>
					</a>
				</h1>
			<?php else : ?>
				<p class="text-2xl font-semibold tracking-tight">
					<a href="<?php echo esc_url( home_url( '/' ) ); ?>" rel="home" class="no-underline">
						<?php bloginfo( 'name' ); ?>
					</a>
				</p>
			<?php endif; ?>

			<?php
			$description = get_bloginfo( 'description', 'display' );
			if ( $description || is_customize_preview() ) :
				?>
				<p class="mt-1 text-sm text-gray-600"><?php echo esc_html( $description ); ?></p>
			<?php endif; ?>
		</div>

		<?php if ( has_nav_menu( 'primary' ) ) : ?>
			<nav class="primary-navigation" aria-label="<?php esc_attr_e( 'Primary menu', 'prose-app' ); ?>">
				<?php
				wp_nav_menu(
					array(
						'theme_location' => 'primary',
						'menu_class'     => 'flex flex-wrap gap-4 text-sm font-medium',
						'container'      => false,
						'fallback_cb'    => false,
					)
				);
				?>
			</nav>
		<?php endif; ?>
	</div>
</header>

<?php if ( ! Courtflow\is_workspace_page() ) : ?>
<main id="content" class="mx-auto max-w-5xl px-4 py-10">
<?php endif; ?>
