<?php
/**
 * Official form edit template.
 *
 * @package ProseCore
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$list_url = admin_url( 'admin.php?page=courtflow-forms' );

$json_lines = array();
foreach ( (array) ( $form['mappings'] ?? array() ) as $field => $path ) {
	$json_lines[] = $field . ' | ' . $path;
}
$json_text = implode( "\n", $json_lines );

$db_lines = array();
foreach ( (array) $db_mappings as $row ) {
	$transform = isset( $row['transform'] ) && null !== $row['transform']
		? wp_json_encode( $row['transform'] )
		: '';
	$db_lines[] = $row['field_name'] . ' | ' . $row['source_path'] . ( '' !== $transform ? ' | ' . $transform : '' );
}
$db_text = implode( "\n", $db_lines );
?>
<div class="wrap courtflow-admin">
	<h1>
		<?php
		if ( (int) $form['id'] > 0 ) {
			esc_html_e( 'Edit Official Form', 'prose-core' );
		} else {
			esc_html_e( 'Add Official Form', 'prose-core' );
		}
		?>
	</h1>
	<p><a href="<?php echo esc_url( $list_url ); ?>">&larr; <?php esc_html_e( 'Back to all forms', 'prose-core' ); ?></a></p>

	<form method="post" enctype="multipart/form-data" class="courtflow-form-edit">
		<?php wp_nonce_field( 'courtflow_official_form' ); ?>
		<input type="hidden" name="form_id" value="<?php echo esc_attr( (string) (int) $form['id'] ); ?>" />

		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="form_title"><?php esc_html_e( 'Title', 'prose-core' ); ?></label></th>
				<td><input type="text" name="form_title" id="form_title" class="regular-text" value="<?php echo esc_attr( $form['title'] ); ?>" required /></td>
			</tr>
			<tr>
				<th scope="row"><label for="cf_form_slug"><?php esc_html_e( 'Form code', 'prose-core' ); ?></label></th>
				<td>
					<input type="text" name="cf_form_slug" id="cf_form_slug" class="regular-text" value="<?php echo esc_attr( $form['slug'] ); ?>" placeholder="UD-2" required />
					<p class="description"><?php esc_html_e( 'Court form identifier (e.g. UD-2, UCS-111). Used for PDF and mapping filenames.', 'prose-core' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="post_status"><?php esc_html_e( 'Status', 'prose-core' ); ?></label></th>
				<td>
					<select name="post_status" id="post_status">
						<option value="publish" <?php selected( $form['post_status'] ?? 'publish', 'publish' ); ?>><?php esc_html_e( 'Published', 'prose-core' ); ?></option>
						<option value="draft" <?php selected( $form['post_status'] ?? 'publish', 'draft' ); ?>><?php esc_html_e( 'Draft', 'prose-core' ); ?></option>
					</select>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="form_description"><?php esc_html_e( 'Description', 'prose-core' ); ?></label></th>
				<td>
					<textarea name="form_description" id="form_description" class="large-text" rows="4"><?php echo esc_textarea( $form['description'] ?? '' ); ?></textarea>
					<p class="description"><?php esc_html_e( 'Internal notes about when this form is filed.', 'prose-core' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="cf_pdf_path"><?php esc_html_e( 'PDF path', 'prose-core' ); ?></label></th>
				<td>
					<input type="text" name="cf_pdf_path" id="cf_pdf_path" class="large-text code" value="<?php echo esc_attr( $form['pdf_path'] ?? '' ); ?>" />
					<p class="description">
						<?php
						if ( ! empty( $form['pdf_exists'] ) ) {
							esc_html_e( 'PDF file found on disk.', 'prose-core' );
						} else {
							esc_html_e( 'PDF not found at this path. Upload a template below.', 'prose-core' );
						}
						?>
					</p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="cf_pdf_upload"><?php esc_html_e( 'Upload PDF', 'prose-core' ); ?></label></th>
				<td>
					<input type="file" name="cf_pdf_upload" id="cf_pdf_upload" accept="application/pdf,.pdf" />
					<p class="description"><?php esc_html_e( 'Saved as data/forms/{CODE}.pdf in the plugin.', 'prose-core' ); ?></p>
				</td>
			</tr>
		</table>

		<h2><?php esc_html_e( 'Default field mappings (JSON file)', 'prose-core' ); ?></h2>
		<p class="description">
			<?php esc_html_e( 'One mapping per line: PDF field name | facts source path (e.g. PetitionerName | user.full_name). Saved to data/mappings/{CODE}.json.', 'prose-core' ); ?>
		</p>
		<textarea name="json_mappings" class="large-text code" rows="12" spellcheck="false"><?php echo esc_textarea( $json_text ); ?></textarea>

		<?php if ( (int) $form['id'] > 0 ) : ?>
			<h2><?php esc_html_e( 'Database override mappings', 'prose-core' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'Optional overrides stored in the database (applied in addition to JSON). Format: field | source | transform JSON (optional).', 'prose-core' ); ?>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=courtflow-mappings&form_id=' . (int) $form['id'] ) ); ?>"><?php esc_html_e( 'Quick-add single mapping', 'prose-core' ); ?></a>
			</p>
			<textarea name="db_mappings" class="large-text code" rows="8" spellcheck="false"><?php echo esc_textarea( $db_text ); ?></textarea>

			<?php if ( ! empty( $db_mappings ) ) : ?>
				<h3><?php esc_html_e( 'Remove individual DB rows', 'prose-core' ); ?></h3>
				<table class="widefat striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Field', 'prose-core' ); ?></th>
							<th><?php esc_html_e( 'Source', 'prose-core' ); ?></th>
							<th><?php esc_html_e( 'Action', 'prose-core' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $db_mappings as $row ) : ?>
							<tr>
								<td><?php echo esc_html( $row['field_name'] ); ?></td>
								<td><code><?php echo esc_html( $row['source_path'] ); ?></code></td>
								<td>
									<form method="post" style="display:inline">
										<?php wp_nonce_field( 'courtflow_official_form' ); ?>
										<input type="hidden" name="mapping_id" value="<?php echo esc_attr( (string) (int) $row['id'] ); ?>" />
										<button type="submit" name="courtflow_delete_mapping" class="button button-link-delete" onclick="return confirm('<?php echo esc_js( __( 'Delete this mapping?', 'prose-core' ) ); ?>');">
											<?php esc_html_e( 'Delete', 'prose-core' ); ?>
										</button>
									</form>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		<?php else : ?>
			<p class="description"><?php esc_html_e( 'Save the form once to enable database override mappings.', 'prose-core' ); ?></p>
		<?php endif; ?>

		<?php submit_button( __( 'Save Official Form', 'prose-core' ), 'primary', 'courtflow_save_form' ); ?>
	</form>
</div>
