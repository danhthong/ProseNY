<?php
/**
 * Form admin edit screen: PDF viewer, tabbed metabox, workflow preview.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Forms;

use ProSe\Core\Loader;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Form_Admin
 */
class Form_Admin {

	/**
	 * Nonce action for saving form meta.
	 */
	private const NONCE_ACTION = 'prose_form_meta_save';

	/**
	 * Form repository.
	 *
	 * @var Form_Repository
	 */
	private Form_Repository $repository;

	/**
	 * Classification admin (optional tab renderer).
	 *
	 * @var Form_Classification_Admin|null
	 */
	private ?Form_Classification_Admin $classification_admin;

	/**
	 * Constructor.
	 *
	 * @param Form_Repository              $repository           Form repository.
	 * @param Form_Classification_Admin|null $classification_admin Classification admin.
	 */
	public function __construct( Form_Repository $repository, ?Form_Classification_Admin $classification_admin = null ) {
		$this->repository           = $repository;
		$this->classification_admin = $classification_admin;
	}

	/**
	 * Register hooks.
	 *
	 * @param Loader $loader Hook loader.
	 * @return void
	 */
	public function register( Loader $loader ): void {
		$loader->add_filter( 'use_block_editor_for_post_type', $this, 'disable_block_editor', 10, 2 );
		$loader->add_action( 'edit_form_after_title', $this, 'render_pdf_viewer' );
		$loader->add_action( 'add_meta_boxes', $this, 'register_meta_boxes' );
		$loader->add_action( 'save_post_' . Form_CPT::POST_TYPE, $this, 'save_form_meta', 10, 2 );
	}

	/**
	 * Disable block editor for prose_form.
	 *
	 * @param bool   $use_block_editor Whether to use block editor.
	 * @param string $post_type        Post type.
	 * @return bool
	 */
	public function disable_block_editor( bool $use_block_editor, string $post_type ): bool {
		if ( Form_CPT::POST_TYPE === $post_type ) {
			return false;
		}

		return $use_block_editor;
	}

	/**
	 * Render PDF viewer below the title editor.
	 *
	 * @param \WP_Post $post Current post.
	 * @return void
	 */
	public function render_pdf_viewer( \WP_Post $post ): void {
		if ( Form_CPT::POST_TYPE !== $post->post_type ) {
			return;
		}

		$file_url   = (string) get_post_meta( $post->ID, Form_Meta::META_FILE_URL, true );
		$source_url = (string) get_post_meta( $post->ID, Form_Meta::META_SOURCE_PDF_URL, true );
		?>
		<div class="prose-pdf-viewer">
			<h2><?php esc_html_e( 'PDF Preview', 'prose-core' ); ?></h2>

			<?php if ( '' !== $file_url ) : ?>
				<div class="prose-pdf-viewer__actions">
					<a href="<?php echo esc_url( $file_url ); ?>" class="button" target="_blank" rel="noopener noreferrer">
						<?php esc_html_e( 'Open PDF', 'prose-core' ); ?>
					</a>
					<a href="<?php echo esc_url( $file_url ); ?>" class="button" download>
						<?php esc_html_e( 'Download PDF', 'prose-core' ); ?>
					</a>
					<?php if ( '' !== $source_url ) : ?>
						<a href="<?php echo esc_url( $source_url ); ?>" class="button" target="_blank" rel="noopener noreferrer">
							<?php esc_html_e( 'Open Source PDF', 'prose-core' ); ?>
						</a>
					<?php endif; ?>
				</div>
				<iframe
					class="prose-pdf-viewer__frame"
					src="<?php echo esc_url( $file_url ); ?>#toolbar=1"
					title="<?php esc_attr_e( 'PDF Preview', 'prose-core' ); ?>"
					width="100%"
					height="800"
				></iframe>
			<?php else : ?>
				<p class="prose-pdf-viewer__empty"><?php esc_html_e( 'No PDF attached.', 'prose-core' ); ?></p>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Register form metaboxes.
	 *
	 * @return void
	 */
	public function register_meta_boxes(): void {
		add_meta_box(
			'prose_form_details',
			__( 'Form Details', 'prose-core' ),
			array( $this, 'render_form_details_metabox' ),
			Form_CPT::POST_TYPE,
			'normal',
			'high'
		);

		add_meta_box(
			'prose_workflow_preview',
			__( 'Workflow Preview', 'prose-core' ),
			array( $this, 'render_workflow_preview_metabox' ),
			Form_CPT::POST_TYPE,
			'side',
			'default'
		);
	}

	/**
	 * Render the tabbed Form Details metabox.
	 *
	 * @param \WP_Post $post Current post.
	 * @return void
	 */
	public function render_form_details_metabox( \WP_Post $post ): void {
		wp_nonce_field( self::NONCE_ACTION, 'prose_form_meta_nonce' );

		$values = $this->get_meta_values( $post->ID );
		?>
		<div class="prose-form-tabs" data-prose-form-tabs>
			<nav class="prose-form-tabs__nav" role="tablist" aria-label="<?php esc_attr_e( 'Form details sections', 'prose-core' ); ?>">
				<button type="button" class="prose-form-tabs__tab is-active" role="tab" aria-selected="true" data-tab="general"><?php esc_html_e( 'General', 'prose-core' ); ?></button>
				<button type="button" class="prose-form-tabs__tab" role="tab" aria-selected="false" data-tab="pdf"><?php esc_html_e( 'PDF', 'prose-core' ); ?></button>
				<button type="button" class="prose-form-tabs__tab" role="tab" aria-selected="false" data-tab="analysis"><?php esc_html_e( 'PDF Analysis', 'prose-core' ); ?></button>
				<button type="button" class="prose-form-tabs__tab" role="tab" aria-selected="false" data-tab="classification"><?php esc_html_e( 'Classification', 'prose-core' ); ?></button>
				<button type="button" class="prose-form-tabs__tab" role="tab" aria-selected="false" data-tab="automation"><?php esc_html_e( 'Automation', 'prose-core' ); ?></button>
				<button type="button" class="prose-form-tabs__tab" role="tab" aria-selected="false" data-tab="ai"><?php esc_html_e( 'AI', 'prose-core' ); ?></button>
			</nav>

			<div class="prose-form-tabs__panel is-active" data-panel="general" role="tabpanel">
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="prose_form_code"><?php esc_html_e( 'Form Code', 'prose-core' ); ?></label></th>
						<td><input type="text" class="regular-text" id="prose_form_code" name="prose_form_code" value="<?php echo esc_attr( $values['form_code'] ); ?>" /></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Case Type', 'prose-core' ); ?></th>
						<td><?php $this->render_taxonomy_summary( $post->ID, Form_Taxonomy::TAXONOMY_CASE_TYPE ); ?></td>
					</tr>
					<tr>
						<th scope="row"><label for="prose_county"><?php esc_html_e( 'County', 'prose-core' ); ?></label></th>
						<td><input type="text" class="regular-text" id="prose_county" name="prose_county" value="<?php echo esc_attr( $values['county'] ); ?>" placeholder="<?php esc_attr_e( 'e.g. Kings, Queens, All NYC Counties', 'prose-core' ); ?>" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="prose_workflow_key"><?php esc_html_e( 'Workflow Key', 'prose-core' ); ?></label></th>
						<td><input type="text" class="regular-text" id="prose_workflow_key" name="prose_workflow_key" value="<?php echo esc_attr( $values['workflow_key'] ); ?>" placeholder="<?php esc_attr_e( 'e.g. uncontested_divorce', 'prose-core' ); ?>" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="prose_workflow_order"><?php esc_html_e( 'Workflow Order', 'prose-core' ); ?></label></th>
						<td><input type="number" class="small-text" id="prose_workflow_order" name="prose_workflow_order" value="<?php echo esc_attr( $values['workflow_order'] ); ?>" min="0" step="1" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="prose_packet_group"><?php esc_html_e( 'Packet Group', 'prose-core' ); ?></label></th>
						<td><input type="text" class="regular-text" id="prose_packet_group" name="prose_packet_group" value="<?php echo esc_attr( $values['packet_group'] ); ?>" placeholder="<?php esc_attr_e( 'e.g. Initial Filing', 'prose-core' ); ?>" /></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Required', 'prose-core' ); ?></th>
						<td>
							<label for="prose_required">
								<input type="checkbox" id="prose_required" name="prose_required" value="1" <?php checked( $values['required'] ); ?> />
								<?php esc_html_e( 'This form is required in its workflow stage.', 'prose-core' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="prose_dependencies"><?php esc_html_e( 'Dependencies', 'prose-core' ); ?></label></th>
						<td>
							<textarea class="large-text code" rows="4" id="prose_dependencies" name="prose_dependencies" data-prose-json><?php echo esc_textarea( $values['dependencies'] ); ?></textarea>
							<p class="description"><?php esc_html_e( 'JSON array of form codes, e.g. ["UD-2","UD-3"]', 'prose-core' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="prose_conditions"><?php esc_html_e( 'Conditions', 'prose-core' ); ?></label></th>
						<td>
							<textarea class="large-text code" rows="6" id="prose_conditions" name="prose_conditions" data-prose-json><?php echo esc_textarea( $values['conditions'] ); ?></textarea>
							<p class="description"><?php esc_html_e( 'JSON array of condition objects.', 'prose-core' ); ?></p>
						</td>
					</tr>
				</table>
			</div>

			<div class="prose-form-tabs__panel" data-panel="pdf" role="tabpanel" hidden>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="prose_pdf"><?php esc_html_e( 'PDF Filename', 'prose-core' ); ?></label></th>
						<td>
							<input type="text" class="regular-text" id="prose_pdf" name="prose_pdf" value="<?php echo esc_attr( $values['pdf'] ); ?>" />
							<p class="description"><?php esc_html_e( 'Blank PDF filename used for downloads (e.g. adop1-a.pdf). Synced to the Forms Repository when saved.', 'prose-core' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="prose_file_name"><?php esc_html_e( 'File Name', 'prose-core' ); ?></label></th>
						<td><input type="text" class="regular-text" id="prose_file_name" name="prose_file_name" value="<?php echo esc_attr( $values['file_name'] ); ?>" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="prose_file_url"><?php esc_html_e( 'File URL', 'prose-core' ); ?></label></th>
						<td><input type="url" class="large-text" id="prose_file_url" name="prose_file_url" value="<?php echo esc_attr( $values['file_url'] ); ?>" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="prose_source_pdf_url"><?php esc_html_e( 'Source PDF URL', 'prose-core' ); ?></label></th>
						<td><input type="url" class="large-text" id="prose_source_pdf_url" name="prose_source_pdf_url" value="<?php echo esc_attr( $values['source_pdf_url'] ); ?>" /></td>
					</tr>
				</table>

				<?php $this->render_source_files_table( $values['source_files'] ); ?>
			</div>

			<div class="prose-form-tabs__panel" data-panel="analysis" role="tabpanel" hidden>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Fillable PDF', 'prose-core' ); ?></th>
						<td><span class="prose-readonly"><?php echo $values['pdf_fillable'] ? esc_html__( 'Yes', 'prose-core' ) : esc_html__( 'No', 'prose-core' ); ?></span></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Field Count', 'prose-core' ); ?></th>
						<td><span class="prose-readonly"><?php echo esc_html( $values['pdf_field_count'] ); ?></span></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'PDF Fields JSON', 'prose-core' ); ?></th>
						<td><textarea class="large-text code prose-readonly-field" rows="8" readonly><?php echo esc_textarea( $values['pdf_fields_json'] ); ?></textarea></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Analyzed At', 'prose-core' ); ?></th>
						<td><span class="prose-readonly"><?php echo esc_html( $values['pdf_analyzed_at'] ?: '—' ); ?></span></td>
					</tr>
				</table>
				<p class="description"><?php esc_html_e( 'PDF analysis fields are populated automatically by the Form Intelligence Engine.', 'prose-core' ); ?></p>
			</div>

			<?php if ( $this->classification_admin ) : ?>
			<div class="prose-form-tabs__panel" data-panel="classification" role="tabpanel" hidden>
				<?php $this->classification_admin->render_tab( $post ); ?>
			</div>
			<?php endif; ?>

			<div class="prose-form-tabs__panel" data-panel="automation" role="tabpanel" hidden>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="prose_fillable_fields"><?php esc_html_e( 'Fillable Fields', 'prose-core' ); ?></label></th>
						<td>
							<textarea class="large-text code" rows="8" id="prose_fillable_fields" name="prose_fillable_fields" data-prose-json><?php echo esc_textarea( $values['fillable_fields'] ); ?></textarea>
							<p class="description"><?php esc_html_e( 'JSON array of fillable field definitions.', 'prose-core' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="prose_field_mapping_json"><?php esc_html_e( 'Field Mapping JSON', 'prose-core' ); ?></label></th>
						<td>
							<textarea class="large-text code" rows="8" id="prose_field_mapping_json" name="prose_field_mapping_json" data-prose-json><?php echo esc_textarea( $values['field_mapping_json'] ); ?></textarea>
							<p class="description"><?php esc_html_e( 'JSON object mapping questionnaire fields to PDF fields.', 'prose-core' ); ?></p>
						</td>
					</tr>
				</table>
			</div>

			<div class="prose-form-tabs__panel" data-panel="ai" role="tabpanel" hidden>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="prose_ai_summary"><?php esc_html_e( 'AI Summary', 'prose-core' ); ?></label></th>
						<td><textarea class="large-text" rows="5" id="prose_ai_summary" name="prose_ai_summary"><?php echo esc_textarea( $values['ai_summary'] ); ?></textarea></td>
					</tr>
					<tr>
						<th scope="row"><label for="prose_plain_language_description"><?php esc_html_e( 'Plain Language Description', 'prose-core' ); ?></label></th>
						<td><textarea class="large-text" rows="5" id="prose_plain_language_description" name="prose_plain_language_description"><?php echo esc_textarea( $values['plain_language_description'] ); ?></textarea></td>
					</tr>
					<tr>
						<th scope="row"><label for="prose_common_mistakes"><?php esc_html_e( 'Common Mistakes', 'prose-core' ); ?></label></th>
						<td>
							<textarea class="large-text code" rows="6" id="prose_common_mistakes" name="prose_common_mistakes" data-prose-json><?php echo esc_textarea( $values['common_mistakes'] ); ?></textarea>
							<p class="description"><?php esc_html_e( 'JSON array of common mistakes.', 'prose-core' ); ?></p>
						</td>
					</tr>
				</table>
			</div>
		</div>
		<?php
	}

	/**
	 * Render workflow preview metabox.
	 *
	 * @param \WP_Post $post Current post.
	 * @return void
	 */
	public function render_workflow_preview_metabox( \WP_Post $post ): void {
		$workflow_key = (string) get_post_meta( $post->ID, Form_Meta::META_WORKFLOW_KEY, true );
		$form_code    = (string) get_post_meta( $post->ID, Form_Meta::META_FORM_CODE, true );

		if ( '' === $form_code ) {
			$form_code = (string) get_post_meta( $post->ID, Form_Meta::META_FORM_ID, true );
		}

		if ( '' === $workflow_key ) {
			echo '<p class="description">' . esc_html__( 'Set a Workflow Key to preview where this form belongs in the filing workflow.', 'prose-core' ) . '</p>';
			return;
		}

		$forms = $this->repository->get_forms_by_workflow( $workflow_key );

		if ( empty( $forms ) ) {
			echo '<p class="description">' . esc_html__( 'No other forms share this workflow key yet.', 'prose-core' ) . '</p>';
			return;
		}

		$grouped = array();

		foreach ( $forms as $form ) {
			$stages = get_the_terms( $form->ID, Form_Taxonomy::TAXONOMY_WORKFLOW_STAGE );
			$stage  = __( 'Uncategorized', 'prose-core' );

			if ( ! empty( $stages ) && ! is_wp_error( $stages ) ) {
				$leaf = $this->get_deepest_term( $stages );
				$stage = $leaf ? $leaf->name : $stages[0]->name;
			}

			if ( ! isset( $grouped[ $stage ] ) ) {
				$grouped[ $stage ] = array();
			}

			$grouped[ $stage ][] = $form;
		}

		$workflow_label = ucwords( str_replace( '_', ' ', $workflow_key ) );
		?>
		<div class="prose-workflow-preview">
			<div class="prose-workflow-preview__root"><?php echo esc_html( $workflow_label ); ?></div>
			<?php foreach ( $grouped as $stage_name => $stage_forms ) : ?>
				<div class="prose-workflow-preview__stage">
					<div class="prose-workflow-preview__branch">&#9492;&#9472;&#9472; <?php echo esc_html( $stage_name ); ?></div>
					<ul class="prose-workflow-preview__forms">
						<?php
						$count = count( $stage_forms );
						foreach ( $stage_forms as $index => $form ) :
							$code = (string) get_post_meta( $form->ID, Form_Meta::META_FORM_CODE, true );

							if ( '' === $code ) {
								$code = (string) get_post_meta( $form->ID, Form_Meta::META_FORM_ID, true );
							}

							if ( '' === $code ) {
								$code = $form->post_title;
							}

							$is_current = (int) $form->ID === (int) $post->ID;
							$prefix     = ( $index === $count - 1 ) ? '&#9492;&#9472;&#9472; ' : '&#9500;&#9472;&#9472; ';
							$label      = $code;

							if ( $is_current ) {
								$label .= ' (' . __( 'Current Form', 'prose-core' ) . ')';
							}
							?>
							<li class="<?php echo $is_current ? 'is-current' : ''; ?>">
								<span class="prose-workflow-preview__prefix"><?php echo wp_kses_post( $prefix ); ?></span>
								<?php if ( $is_current ) : ?>
									<strong><?php echo esc_html( $label ); ?></strong>
								<?php else : ?>
									<a href="<?php echo esc_url( get_edit_post_link( $form->ID ) ); ?>"><?php echo esc_html( $label ); ?></a>
								<?php endif; ?>
							</li>
						<?php endforeach; ?>
					</ul>
				</div>
			<?php endforeach; ?>
		</div>
		<?php
	}

	/**
	 * Save form meta on post save.
	 *
	 * @param int      $post_id Post ID.
	 * @param \WP_Post $post    Post object.
	 * @return void
	 */
	public function save_form_meta( int $post_id, \WP_Post $post ): void {
		if ( ! isset( $_POST['prose_form_meta_nonce'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			return;
		}

		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['prose_form_meta_nonce'] ) ), self::NONCE_ACTION ) ) {
			return;
		}

		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$form_code = isset( $_POST['prose_form_code'] ) ? sanitize_text_field( wp_unslash( $_POST['prose_form_code'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing

		update_post_meta( $post_id, Form_Meta::META_FORM_CODE, $form_code );
		update_post_meta( $post_id, Form_Meta::META_FORM_ID, $form_code );

		$string_fields = array(
			'prose_county'         => Form_Meta::META_COUNTY,
			'prose_workflow_key'   => Form_Meta::META_WORKFLOW_KEY,
			'prose_packet_group'   => Form_Meta::META_PACKET_GROUP,
			'prose_pdf'            => Form_Meta::META_PDF,
			'prose_file_name'      => Form_Meta::META_FILE_NAME,
			'prose_file_url'       => Form_Meta::META_FILE_URL,
			'prose_source_pdf_url' => Form_Meta::META_SOURCE_PDF_URL,
			'prose_ai_summary'     => Form_Meta::META_AI_SUMMARY,
			'prose_plain_language_description' => Form_Meta::META_PLAIN_LANGUAGE_DESCRIPTION,
		);

		foreach ( $string_fields as $post_key => $meta_key ) {
			if ( ! isset( $_POST[ $post_key ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
				continue;
			}

			$value = wp_unslash( $_POST[ $post_key ] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

			if ( in_array( $meta_key, array( Form_Meta::META_FILE_URL, Form_Meta::META_SOURCE_PDF_URL ), true ) ) {
				update_post_meta( $post_id, $meta_key, esc_url_raw( $value ) );
			} elseif ( in_array( $meta_key, array( Form_Meta::META_FILE_NAME, Form_Meta::META_PDF ), true ) ) {
				update_post_meta( $post_id, $meta_key, sanitize_file_name( $value ) );
			} elseif ( in_array( $meta_key, Form_Meta::textarea_keys(), true ) ) {
				update_post_meta( $post_id, $meta_key, sanitize_textarea_field( $value ) );
			} else {
				update_post_meta( $post_id, $meta_key, sanitize_text_field( $value ) );
			}
		}

		$workflow_order = isset( $_POST['prose_workflow_order'] ) ? absint( wp_unslash( $_POST['prose_workflow_order'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		update_post_meta( $post_id, Form_Meta::META_WORKFLOW_ORDER, $workflow_order );

		$required = isset( $_POST['prose_required'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		update_post_meta( $post_id, Form_Meta::META_REQUIRED, $required );

		$json_map = array(
			'prose_dependencies'       => Form_Meta::META_DEPENDENCIES,
			'prose_conditions'         => Form_Meta::META_CONDITIONS,
			'prose_fillable_fields'    => Form_Meta::META_FILLABLE_FIELDS,
			'prose_field_mapping_json' => Form_Meta::META_FIELD_MAPPING_JSON,
			'prose_common_mistakes'    => Form_Meta::META_COMMON_MISTAKES,
		);

		foreach ( $json_map as $post_key => $meta_key ) {
			if ( ! isset( $_POST[ $post_key ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
				continue;
			}

			$value = wp_unslash( $_POST[ $post_key ] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			update_post_meta( $post_id, $meta_key, Form_Meta::sanitize_json( $value ) );
		}

		$this->sync_pdf_meta( $post_id );
	}

	/**
	 * Keep prose_pdf and legacy prose_file_name aligned.
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	private function sync_pdf_meta( int $post_id ): void {
		$pdf       = sanitize_file_name( (string) get_post_meta( $post_id, Form_Meta::META_PDF, true ) );
		$file_name = sanitize_file_name( (string) get_post_meta( $post_id, Form_Meta::META_FILE_NAME, true ) );

		if ( '' === $pdf && '' !== $file_name ) {
			update_post_meta( $post_id, Form_Meta::META_PDF, $file_name );
		} elseif ( '' === $file_name && '' !== $pdf ) {
			update_post_meta( $post_id, Form_Meta::META_FILE_NAME, $pdf );
		}
	}

	/**
	 * Resolve the PDF filename shown in admin.
	 *
	 * @param int $post_id Post ID.
	 * @return string
	 */
	private function pdf_filename_for_admin( int $post_id ): string {
		$post = get_post( $post_id );

		if ( ! $post instanceof \WP_Post ) {
			return '';
		}

		return ( new Form_Pdf_Path_Resolver() )->pdf_filename_for_post( $post );
	}

	/**
	 * Get all meta values for the edit form.
	 *
	 * @param int $post_id Post ID.
	 * @return array<string, mixed>
	 */
	private function get_meta_values( int $post_id ): array {
		$form_code = (string) get_post_meta( $post_id, Form_Meta::META_FORM_CODE, true );

		if ( '' === $form_code ) {
			$form_code = (string) get_post_meta( $post_id, Form_Meta::META_FORM_ID, true );
		}

		return array(
			'form_code'                  => $form_code,
			'county'                     => (string) get_post_meta( $post_id, Form_Meta::META_COUNTY, true ),
			'workflow_key'               => (string) get_post_meta( $post_id, Form_Meta::META_WORKFLOW_KEY, true ),
			'workflow_order'             => (string) get_post_meta( $post_id, Form_Meta::META_WORKFLOW_ORDER, true ),
			'packet_group'               => (string) get_post_meta( $post_id, Form_Meta::META_PACKET_GROUP, true ),
			'required'                   => (bool) get_post_meta( $post_id, Form_Meta::META_REQUIRED, true ),
			'dependencies'               => $this->format_json_for_display( (string) get_post_meta( $post_id, Form_Meta::META_DEPENDENCIES, true ) ),
			'conditions'                 => $this->format_json_for_display( (string) get_post_meta( $post_id, Form_Meta::META_CONDITIONS, true ) ),
			'pdf'                        => $this->pdf_filename_for_admin( $post_id ),
			'file_name'                  => (string) get_post_meta( $post_id, Form_Meta::META_FILE_NAME, true ),
			'file_url'                   => (string) get_post_meta( $post_id, Form_Meta::META_FILE_URL, true ),
			'source_pdf_url'             => (string) get_post_meta( $post_id, Form_Meta::META_SOURCE_PDF_URL, true ),
			'source_files'               => $this->get_source_files( $post_id ),
			'pdf_fillable'               => (bool) get_post_meta( $post_id, Form_Meta::META_PDF_FILLABLE, true ),
			'pdf_field_count'            => (string) get_post_meta( $post_id, Form_Meta::META_PDF_FIELD_COUNT, true ),
			'pdf_fields_json'            => $this->format_json_for_display( (string) get_post_meta( $post_id, Form_Meta::META_PDF_FIELDS_JSON, true ) ),
			'pdf_analyzed_at'            => (string) get_post_meta( $post_id, Form_Meta::META_PDF_ANALYZED_AT, true ),
			'fillable_fields'            => $this->format_json_for_display( (string) get_post_meta( $post_id, Form_Meta::META_FILLABLE_FIELDS, true ) ),
			'field_mapping_json'         => $this->format_json_for_display( (string) get_post_meta( $post_id, Form_Meta::META_FIELD_MAPPING_JSON, true ) ),
			'ai_summary'                 => (string) get_post_meta( $post_id, Form_Meta::META_AI_SUMMARY, true ),
			'plain_language_description' => (string) get_post_meta( $post_id, Form_Meta::META_PLAIN_LANGUAGE_DESCRIPTION, true ),
			'common_mistakes'            => $this->format_json_for_display( (string) get_post_meta( $post_id, Form_Meta::META_COMMON_MISTAKES, true ) ),
		);
	}

	/**
	 * Load source file metadata for admin display.
	 *
	 * @param int $post_id Post ID.
	 * @return array<int, array<string, mixed>>
	 */
	private function get_source_files( int $post_id ): array {
		$raw = get_post_meta( $post_id, Form_Meta::META_SOURCE_FILES, true );

		if ( is_string( $raw ) && '' !== trim( $raw ) ) {
			$decoded = json_decode( $raw, true );

			if ( is_array( $decoded ) && ! empty( $decoded['files'] ) && is_array( $decoded['files'] ) ) {
				return $decoded['files'];
			}
		}

		if ( is_array( $raw ) && ! empty( $raw['files'] ) && is_array( $raw['files'] ) ) {
			return $raw['files'];
		}

		return array();
	}

	/**
	 * Render a read-only table of imported court source files.
	 *
	 * @param array<int, array<string, mixed>> $source_files Source file entries.
	 * @return void
	 */
	private function render_source_files_table( array $source_files ): void {
		if ( empty( $source_files ) ) {
			return;
		}
		?>
		<h3><?php esc_html_e( 'Court Source Files', 'prose-core' ); ?></h3>
		<table class="widefat striped">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Filename', 'prose-core' ); ?></th>
					<th><?php esc_html_e( 'Extension', 'prose-core' ); ?></th>
					<th><?php esc_html_e( 'Status', 'prose-core' ); ?></th>
					<th><?php esc_html_e( 'Download', 'prose-core' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $source_files as $entry ) : ?>
					<?php if ( ! is_array( $entry ) ) : ?>
						<?php continue; ?>
					<?php endif; ?>
					<tr>
						<td><?php echo esc_html( (string) ( $entry['filename'] ?? '' ) ); ?></td>
						<td><?php echo esc_html( (string) ( $entry['extension'] ?? '' ) ); ?></td>
						<td><?php echo esc_html( (string) ( $entry['download_status'] ?? '' ) ); ?></td>
						<td>
							<?php if ( ! empty( $entry['local_url'] ) ) : ?>
								<a href="<?php echo esc_url( (string) $entry['local_url'] ); ?>" target="_blank" rel="noopener noreferrer">
									<?php esc_html_e( 'Open', 'prose-core' ); ?>
								</a>
							<?php else : ?>
								<span class="prose-readonly">—</span>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<p class="description"><?php esc_html_e( 'All court documents imported for this form. Populated automatically during CSV import.', 'prose-core' ); ?></p>
		<?php
	}

	/**
	 * Render read-only taxonomy summary with link to metabox.
	 *
	 * @param int    $post_id  Post ID.
	 * @param string $taxonomy Taxonomy slug.
	 * @return void
	 */
	private function render_taxonomy_summary( int $post_id, string $taxonomy ): void {
		$terms = get_the_terms( $post_id, $taxonomy );

		if ( empty( $terms ) || is_wp_error( $terms ) ) {
			echo '<span class="prose-readonly">—</span>';
			echo '<p class="description">' . esc_html__( 'Assign terms using the taxonomy box in the sidebar.', 'prose-core' ) . '</p>';
			return;
		}

		$names = wp_list_pluck( $terms, 'name' );
		echo '<span class="prose-readonly">' . esc_html( implode( ', ', $names ) ) . '</span>';
		echo '<p class="description">' . esc_html__( 'Edit in the sidebar taxonomy box.', 'prose-core' ) . '</p>';
	}

	/**
	 * Format JSON for display in textareas.
	 *
	 * @param string $json Raw JSON string.
	 * @return string
	 */
	private function format_json_for_display( string $json ): string {
		if ( '' === trim( $json ) ) {
			return '';
		}

		$decoded = json_decode( $json, true );

		if ( JSON_ERROR_NONE !== json_last_error() ) {
			return $json;
		}

		return wp_json_encode( $decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) ?: $json;
	}

	/**
	 * Get the deepest (most specific) workflow stage term.
	 *
	 * @param \WP_Term[] $terms Terms.
	 * @return \WP_Term|null
	 */
	private function get_deepest_term( array $terms ): ?\WP_Term {
		$deepest = null;
		$depth   = -1;

		foreach ( $terms as $term ) {
			$ancestors = get_ancestors( $term->term_id, Form_Taxonomy::TAXONOMY_WORKFLOW_STAGE );
			$level     = count( $ancestors );

			if ( $level > $depth ) {
				$depth   = $level;
				$deepest = $term;
			}
		}

		return $deepest;
	}
}
