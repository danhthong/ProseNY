<?php
/**
 * Field mappings admin template.
 *
 * @package ProseCore
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="wrap courtflow-admin">
	<h1><?php esc_html_e( 'Form Field Mappings', 'prose-core' ); ?></h1>

	<form method="get">
		<input type="hidden" name="page" value="courtflow-mappings" />
		<select name="form_id" onchange="this.form.submit()">
			<?php foreach ( $forms as $form ) : ?>
				<option value="<?php echo esc_attr( (string) $form->ID ); ?>" <?php selected( $selected_form, $form->ID ); ?>>
					<?php echo esc_html( $form->post_title ); ?>
				</option>
			<?php endforeach; ?>
		</select>
	</form>

	<h2><?php esc_html_e( 'Add Mapping', 'prose-core' ); ?></h2>
	<form method="post">
		<?php wp_nonce_field( 'courtflow_mappings' ); ?>
		<input type="hidden" name="form_id" value="<?php echo esc_attr( (string) $selected_form ); ?>" />
		<table class="form-table">
			<tr>
				<th><label for="field_name"><?php esc_html_e( 'PDF Field Name', 'prose-core' ); ?></label></th>
				<td><input type="text" name="field_name" id="field_name" class="regular-text" required /></td>
			</tr>
			<tr>
				<th><label for="source_path"><?php esc_html_e( 'Source Path', 'prose-core' ); ?></label></th>
				<td><input type="text" name="source_path" id="source_path" class="regular-text" placeholder="user.full_name" required /></td>
			</tr>
			<tr>
				<th><label for="transform"><?php esc_html_e( 'Transform (JSON)', 'prose-core' ); ?></label></th>
				<td><input type="text" name="transform" id="transform" class="regular-text" placeholder='{"type":"upper"}' /></td>
			</tr>
		</table>
		<?php submit_button( __( 'Save Mapping', 'prose-core' ), 'primary', 'courtflow_save_mapping' ); ?>
	</form>

	<h2><?php esc_html_e( 'Current Mappings', 'prose-core' ); ?></h2>
	<table class="widefat striped">
		<thead><tr><th>Field</th><th>Source</th><th>Transform</th></tr></thead>
		<tbody>
			<?php foreach ( $mappings as $map ) : ?>
				<tr>
					<td><?php echo esc_html( $map['field_name'] ); ?></td>
					<td><code><?php echo esc_html( $map['source_path'] ); ?></code></td>
					<td><code><?php echo esc_html( $map['transform'] ?? '' ); ?></code></td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>
</div>
