<?php
/**
 * Official forms list template.
 *
 * @package ProseCore
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$list_url = admin_url( 'admin.php?page=courtflow-forms' );
$edit_url = admin_url( 'admin.php?page=courtflow-forms&action=edit' );
?>
<div class="wrap courtflow-admin">
	<h1 class="wp-heading-inline"><?php esc_html_e( 'Official Forms', 'prose-core' ); ?></h1>
	<a href="<?php echo esc_url( $edit_url ); ?>" class="page-title-action"><?php esc_html_e( 'Add New', 'prose-core' ); ?></a>
	<hr class="wp-header-end" />

	<p class="description">
		<?php esc_html_e( 'Manage court PDF templates, form codes, and field mappings. Default mappings live in JSON files; database rows override at generation time.', 'prose-core' ); ?>
	</p>

	<table class="widefat striped courtflow-forms-table">
		<thead>
			<tr>
				<th><?php esc_html_e( 'Code', 'prose-core' ); ?></th>
				<th><?php esc_html_e( 'Title', 'prose-core' ); ?></th>
				<th><?php esc_html_e( 'PDF', 'prose-core' ); ?></th>
				<th><?php esc_html_e( 'JSON mappings', 'prose-core' ); ?></th>
				<th><?php esc_html_e( 'Status', 'prose-core' ); ?></th>
				<th><?php esc_html_e( 'Actions', 'prose-core' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php if ( empty( $forms ) ) : ?>
				<tr>
					<td colspan="6"><?php esc_html_e( 'No forms found. Add a form or seed defaults from CourtFlow setup.', 'prose-core' ); ?></td>
				</tr>
			<?php else : ?>
				<?php foreach ( $forms as $form ) : ?>
					<?php
					$edit_link = $form['id'] > 0
						? add_query_arg( 'form_id', (string) $form['id'], $edit_url )
						: add_query_arg( 'slug', $form['slug'], $edit_url );
					$mapping_count = count( $form['mappings'] ?? array() );
					?>
					<tr>
						<td><code><?php echo esc_html( $form['slug'] ); ?></code></td>
						<td><strong><?php echo esc_html( $form['title'] ); ?></strong></td>
						<td>
							<?php if ( ! empty( $form['pdf_exists'] ) ) : ?>
								<span class="courtflow-badge courtflow-badge--ok"><?php esc_html_e( 'On disk', 'prose-core' ); ?></span>
							<?php else : ?>
								<span class="courtflow-badge courtflow-badge--warn"><?php esc_html_e( 'Missing', 'prose-core' ); ?></span>
							<?php endif; ?>
						</td>
						<td><?php echo esc_html( (string) $mapping_count ); ?></td>
						<td>
							<?php
							if ( ( $form['id'] ?? 0 ) > 0 ) {
								echo esc_html( $form['post_status'] ?? 'publish' );
							} else {
								esc_html_e( 'JSON only', 'prose-core' );
							}
							?>
						</td>
						<td>
							<a href="<?php echo esc_url( $edit_link ); ?>"><?php esc_html_e( 'Edit', 'prose-core' ); ?></a>
							<?php if ( ( $form['id'] ?? 0 ) > 0 ) : ?>
								| <a href="<?php echo esc_url( admin_url( 'admin.php?page=courtflow-mappings&form_id=' . (int) $form['id'] ) ); ?>"><?php esc_html_e( 'DB mappings', 'prose-core' ); ?></a>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			<?php endif; ?>
		</tbody>
	</table>
</div>
