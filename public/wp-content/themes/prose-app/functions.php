<?php
/**
 * Prose App theme functions.
 *
 * @package ProseApp
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'PROSE_APP_VERSION', '0.3.0' );

require_once get_template_directory() . '/inc/blocks.php';
require_once get_template_directory() . '/inc/enqueue.php';
require_once get_template_directory() . '/inc/interactivity.php';
require_once get_template_directory() . '/inc/courtflow/bootstrap.php';

/**
 * Theme setup.
 */
function prose_app_setup(): void {
	load_theme_textdomain( 'prose-app', get_template_directory() . '/languages' );

	add_theme_support( 'title-tag' );
	add_theme_support( 'post-thumbnails' );
	add_theme_support(
		'html5',
		array(
			'search-form',
			'comment-form',
			'comment-list',
			'gallery',
			'caption',
			'style',
			'script',
		)
	);

	register_nav_menus(
		array(
			'primary' => __( 'Primary Menu', 'prose-app' ),
		)
	);
}
add_action( 'after_setup_theme', 'prose_app_setup' );

/**
 * Enqueue scripts and styles.
 */
function prose_app_enqueue_assets(): void {
	$stylesheet = get_template_directory() . '/assets/css/main.css';

	if ( file_exists( $stylesheet ) ) {
		wp_enqueue_style(
			'prose-app',
			get_template_directory_uri() . '/assets/css/main.css',
			array(),
			(string) filemtime( $stylesheet )
		);
	}
}
add_action( 'wp_enqueue_scripts', 'prose_app_enqueue_assets' );

/**
 * Register widget areas.
 */
function prose_app_widgets_init(): void {
	register_sidebar(
		array(
			'name'          => __( 'Sidebar', 'prose-app' ),
			'id'            => 'sidebar-1',
			'description'   => __( 'Add widgets here.', 'prose-app' ),
			'before_widget' => '<section id="%1$s" class="widget %2$s">',
			'after_widget'  => '</section>',
			'before_title'  => '<h2 class="widget-title">',
			'after_title'   => '</h2>',
		)
	);
}
add_action( 'widgets_init', 'prose_app_widgets_init' );
