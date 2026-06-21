<?php
/**
 * Opens the Proseny marketing / account page shell (Figma site layout).
 *
 * @package ProseApp
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<?php wp_head(); ?>
</head>
<body <?php body_class( 'min-h-screen bg-slate-50 font-sans text-slate-900 antialiased' ); ?>>
<?php wp_body_open(); ?>

<div class="flex min-h-screen flex-col" data-prose-shell>

	<?php get_template_part( 'template-parts/prose-site-header' ); ?>
