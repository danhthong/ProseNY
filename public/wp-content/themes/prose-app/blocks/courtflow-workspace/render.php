<?php
/**
 * CourtFlow workspace shell render.
 *
 * @package ProseApp
 */

use ProseApp\Courtflow;

$session_id = (int) ( $attributes['sessionId'] ?? 0 );
\ProseApp\Interactivity\seed_interactivity_store( $session_id );

wp_enqueue_style( 'courtflow-workspace' );
wp_enqueue_script( 'courtflow-workspace' );
\ProseApp\Enqueue\localize();

$disclaimer = '';
if ( class_exists( '\Prose\Core\Security\Disclaimer' ) ) {
	$disclaimer = \Prose\Core\Security\Disclaimer::render_html();
}
?>
<div <?php echo get_block_wrapper_attributes( array( 'class' => 'courtflow-workspace cf-shell' ) ); ?> data-wp-interactive="courtflow" id="cf-workspace-root">
	<a class="cf-skip-link" href="#courtflow-chat-input"><?php esc_html_e( 'Skip to chat input', 'prose-app' ); ?></a>
	<?php
	if ( $disclaimer ) {
		echo str_replace( 'courtflow-disclaimer', 'courtflow-disclaimer cf-legal-notice', $disclaimer );
	}
	?>
	<?php get_template_part( 'template-parts/courtflow', 'header' ); ?>
	<div class="cf-workspace-body courtflow-grid">
		<div class="courtflow-col courtflow-col-left" id="cf-col-left">
			<?php echo do_blocks( '<!-- wp:courtflow/progress-rail /-->' ); ?>
		</div>
		<div class="courtflow-col courtflow-col-center" id="cf-col-center">
			<?php echo do_blocks( '<!-- wp:courtflow/intake-chat /-->' ); ?>
		</div>
		<div class="courtflow-col courtflow-col-right" id="cf-col-right">
			<?php echo do_blocks( '<!-- wp:courtflow/context-panel /-->' ); ?>
		</div>
	</div>
</div>
