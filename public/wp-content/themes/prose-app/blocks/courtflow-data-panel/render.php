<?php
/**
 * Extracted data panel render.
 *
 * @package ProseApp
 */
?>
<aside class="courtflow-data-panel" data-wp-interactive="courtflow" aria-label="<?php esc_attr_e( 'Extracted information', 'prose-app' ); ?>">
	<h3><?php esc_html_e( 'Your Information', 'prose-app' ); ?></h3>
	<div id="courtflow-facts-display" class="courtflow-facts-display"></div>
	<h4><?php esc_html_e( 'Missing Information', 'prose-app' ); ?></h4>
	<ul id="courtflow-missing-fields" class="courtflow-missing-list"></ul>
</aside>
