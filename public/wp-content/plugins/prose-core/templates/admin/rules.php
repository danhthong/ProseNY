<?php
/**
 * Rules admin template.
 *
 * @package ProseCore
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="wrap courtflow-admin">
	<h1><?php esc_html_e( 'Procedural Rules', 'prose-core' ); ?></h1>

	<?php if ( ! empty( $dry_run ) ) : ?>
		<div class="notice notice-info">
			<p><strong><?php esc_html_e( 'Dry Run Result:', 'prose-core' ); ?></strong></p>
			<pre><?php echo esc_html( wp_json_encode( $dry_run, JSON_PRETTY_PRINT ) ); ?></pre>
		</div>
	<?php endif; ?>

	<h2><?php esc_html_e( 'Add Rule', 'prose-core' ); ?></h2>
	<form method="post">
		<?php wp_nonce_field( 'courtflow_rules' ); ?>
		<table class="form-table">
			<tr>
				<th><label for="slug"><?php esc_html_e( 'Slug', 'prose-core' ); ?></label></th>
				<td><input type="text" name="slug" id="slug" class="regular-text" required /></td>
			</tr>
			<tr>
				<th><label for="priority"><?php esc_html_e( 'Priority', 'prose-core' ); ?></label></th>
				<td><input type="number" name="priority" id="priority" value="100" /></td>
			</tr>
			<tr>
				<th><label for="conditions"><?php esc_html_e( 'Conditions (JSON)', 'prose-core' ); ?></label></th>
				<td><textarea name="conditions" id="conditions" rows="8" class="large-text code">{"all":[]}</textarea></td>
			</tr>
			<tr>
				<th><label for="actions"><?php esc_html_e( 'Actions (JSON)', 'prose-core' ); ?></label></th>
				<td><textarea name="actions" id="actions" rows="8" class="large-text code">[]</textarea></td>
			</tr>
		</table>
		<?php submit_button( __( 'Save Rule', 'prose-core' ), 'primary', 'courtflow_save_rule' ); ?>
	</form>

	<h2><?php esc_html_e( 'Dry Run Sandbox', 'prose-core' ); ?></h2>
	<form method="post">
		<?php wp_nonce_field( 'courtflow_dry_run' ); ?>
		<textarea name="dry_run_facts" rows="6" class="large-text code">{"case":{"county":"Queens","contested":true,"children":true}}</textarea>
		<?php submit_button( __( 'Run Dry Run', 'prose-core' ), 'secondary', 'courtflow_dry_run' ); ?>
	</form>

	<h2><?php esc_html_e( 'Existing Rules', 'prose-core' ); ?></h2>
	<table class="widefat striped">
		<thead>
			<tr><th>Slug</th><th>Priority</th><th>Version</th><th>Enabled</th></tr>
		</thead>
		<tbody>
			<?php foreach ( $rules as $rule ) : ?>
				<tr>
					<td><?php echo esc_html( $rule['slug'] ); ?></td>
					<td><?php echo esc_html( (string) $rule['priority'] ); ?></td>
					<td><?php echo esc_html( (string) $rule['version'] ); ?></td>
					<td><?php echo esc_html( (string) $rule['enabled'] ); ?></td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>
</div>
