<?php
/**
 * Validation alerts block render.
 *
 * @package ProseApp
 */
?>
<div class="courtflow-validation-alerts" data-wp-interactive="courtflow" id="courtflow-validation-alerts" role="alert" aria-live="polite">
	<h3><?php esc_html_e( 'Validation', 'prose-app' ); ?></h3>
	<ul id="courtflow-validation-list"></ul>
	<button type="button" id="courtflow-generate-package" class="button button-primary" disabled>
		<?php esc_html_e( 'Generate Filing Package', 'prose-app' ); ?>
	</button>
</div>
