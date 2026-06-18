<?php
/**
 * Classification admin UI: tab, manual override, reclassify actions.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Forms;

use ProSe\Core\Forms\Classification\Classification_Engine;
use ProSe\Core\Loader;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Form_Classification_Admin
 */
final class Form_Classification_Admin {

	private const NONCE_ACTION   = 'prose_form_classification';
	private const AJAX_RECLASSIFY = 'prose_reclassify_form';

	/**
	 * Classification engine.
	 *
	 * @var Classification_Engine
	 */
	private Classification_Engine $engine;

	/**
	 * Constructor.
	 *
	 * @param Classification_Engine $engine Classification engine.
	 */
	public function __construct( Classification_Engine $engine ) {
		$this->engine = $engine;
	}

	/**
	 * Register hooks.
	 *
	 * @param Loader $loader Hook loader.
	 * @return void
	 */
	public function register( Loader $loader ): void {
		$loader->add_action( 'admin_enqueue_scripts', $this, 'enqueue_assets' );
		$loader->add_action( 'save_post_' . Form_CPT::POST_TYPE, $this, 'save_manual_override', 20, 2 );
		$loader->add_filter( 'post_row_actions', $this, 'row_actions', 10, 2 );
		$loader->add_filter( 'bulk_actions-edit-' . Form_CPT::POST_TYPE, $this, 'bulk_actions' );
		$loader->add_filter( 'handle_bulk_actions-edit-' . Form_CPT::POST_TYPE, $this, 'handle_bulk_reclassify', 10, 3 );
		$loader->add_action( 'wp_ajax_' . self::AJAX_RECLASSIFY, $this, 'ajax_reclassify' );
		$loader->add_action( 'admin_notices', $this, 'bulk_notice' );
	}

	/**
	 * Enqueue admin assets on form screens.
	 *
	 * @param string $hook_suffix Hook suffix.
	 * @return void
	 */
	public function enqueue_assets( string $hook_suffix ): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( ! $screen || Form_CPT::POST_TYPE !== $screen->post_type ) {
			return;
		}

		wp_enqueue_script(
			'prose-core-admin',
			PROSE_CORE_URL . 'assets/js/admin.js',
			array(),
			PROSE_CORE_VERSION,
			true
		);

		wp_enqueue_style(
			'prose-core-admin',
			PROSE_CORE_URL . 'assets/css/admin.css',
			array(),
			PROSE_CORE_VERSION
		);

		wp_localize_script(
			'prose-core-admin',
			'proseClassification',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'action'  => self::AJAX_RECLASSIFY,
				'nonce'   => wp_create_nonce( self::AJAX_RECLASSIFY ),
				'i18n'    => array(
					'reclassifying' => __( 'Reclassifying form…', 'prose-core' ),
					'success'       => __( 'Form reclassified successfully.', 'prose-core' ),
					'error'         => __( 'Reclassification failed.', 'prose-core' ),
					'confirm'       => __( 'Reclassify this form from its PDF? Manual overrides will be preserved unless you force reclassify.', 'prose-core' ),
					'confirmForce'  => __( 'Force reclassify and overwrite manual overrides?', 'prose-core' ),
				),
			)
		);
	}

	/**
	 * Render the Classification tab panel.
	 *
	 * @param \WP_Post $post Current post.
	 * @return void
	 */
	public function render_tab( \WP_Post $post ): void {
		wp_nonce_field( self::NONCE_ACTION, 'prose_classification_nonce' );

		$values = $this->get_classification_values( $post->ID );
		?>
		<div class="prose-classification-actions">
			<button type="button" class="button prose-reclassify-btn" data-post-id="<?php echo esc_attr( (string) $post->ID ); ?>" data-force="0">
				<?php esc_html_e( 'Reclassify Form', 'prose-core' ); ?>
			</button>
			<button type="button" class="button prose-reclassify-btn" data-post-id="<?php echo esc_attr( (string) $post->ID ); ?>" data-force="1">
				<?php esc_html_e( 'Force Reclassify', 'prose-core' ); ?>
			</button>
			<span class="prose-reclassify-status" aria-live="polite"></span>
		</div>

		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Needs Review', 'prose-core' ); ?></th>
				<td>
					<span class="prose-readonly <?php echo $values['needs_review'] ? 'prose-badge prose-badge--warning' : 'prose-badge prose-badge--ok'; ?>">
						<?php echo $values['needs_review'] ? esc_html__( 'Yes', 'prose-core' ) : esc_html__( 'No', 'prose-core' ); ?>
					</span>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Classification Confidence', 'prose-core' ); ?></th>
				<td><span class="prose-readonly"><?php echo esc_html( $values['confidence'] !== '' ? $values['confidence'] . '%' : '—' ); ?></span></td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Classification Source', 'prose-core' ); ?></th>
				<td><span class="prose-readonly"><?php echo esc_html( $values['source'] ?: '—' ); ?></span></td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Classification Signals', 'prose-core' ); ?></th>
				<td>
					<?php if ( ! empty( $values['signals'] ) ) : ?>
						<ul class="prose-signals-list">
							<?php foreach ( $values['signals'] as $signal ) : ?>
								<li><?php echo esc_html( $signal ); ?></li>
							<?php endforeach; ?>
						</ul>
					<?php else : ?>
						<span class="prose-readonly">&#8212;</span>
					<?php endif; ?>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Classification Warning', 'prose-core' ); ?></th>
				<td><span class="prose-readonly"><?php echo esc_html( $values['warning'] ?: '—' ); ?></span></td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Supported Court', 'prose-core' ); ?></th>
				<td><span class="prose-readonly"><?php echo $values['supported_court'] ? esc_html__( 'Yes', 'prose-core' ) : esc_html__( 'No', 'prose-core' ); ?></span></td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Fillable PDF', 'prose-core' ); ?></th>
				<td><span class="prose-readonly"><?php echo $values['pdf_fillable'] ? esc_html__( 'Yes', 'prose-core' ) : esc_html__( 'No', 'prose-core' ); ?></span></td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Field Count', 'prose-core' ); ?></th>
				<td><span class="prose-readonly"><?php echo esc_html( $values['pdf_field_count'] ?: '0' ); ?></span></td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Dependencies', 'prose-core' ); ?></th>
				<td><textarea class="large-text code prose-readonly-field" rows="3" readonly><?php echo esc_textarea( $values['dependencies'] ); ?></textarea></td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Questionnaire Keys', 'prose-core' ); ?></th>
				<td><textarea class="large-text code prose-readonly-field" rows="4" readonly><?php echo esc_textarea( $values['questionnaire_keys'] ); ?></textarea></td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Workflow Package', 'prose-core' ); ?></th>
				<td><textarea class="large-text code prose-readonly-field" rows="3" readonly><?php echo esc_textarea( $values['workflow_package'] ); ?></textarea></td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'AI Summary', 'prose-core' ); ?></th>
				<td><span class="prose-readonly"><?php echo esc_html( $values['ai_summary'] ?: '—' ); ?></span></td>
			</tr>
		</table>

		<h3><?php esc_html_e( 'Manual Override', 'prose-core' ); ?></h3>
		<p class="description"><?php esc_html_e( 'Override detected values. Saving sets Manual Override and preserves values across reclassification.', 'prose-core' ); ?></p>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="prose_override_county"><?php esc_html_e( 'County', 'prose-core' ); ?></label></th>
				<td>
					<input type="text" class="regular-text" id="prose_override_county" name="prose_override_county" value="<?php echo esc_attr( $values['override_county'] ); ?>" />
					<p class="description"><?php printf( esc_html__( 'Detected: %s', 'prose-core' ), esc_html( $values['detected_county'] ?: '—' ) ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="prose_override_case_type"><?php esc_html_e( 'Case Type', 'prose-core' ); ?></label></th>
				<td>
					<input type="text" class="regular-text" id="prose_override_case_type" name="prose_override_case_type" value="<?php echo esc_attr( $values['override_case_type'] ); ?>" />
					<p class="description"><?php printf( esc_html__( 'Detected: %s', 'prose-core' ), esc_html( $values['detected_case_type'] ?: '—' ) ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Manual Override Active', 'prose-core' ); ?></th>
				<td>
					<label for="prose_manual_override">
						<input type="checkbox" id="prose_manual_override" name="prose_manual_override" value="1" <?php checked( $values['manual_override'] ); ?> />
						<?php esc_html_e( 'Manual override is active for this form.', 'prose-core' ); ?>
					</label>
				</td>
			</tr>
		</table>
		<?php
	}

	/**
	 * Save manual override fields.
	 *
	 * @param int      $post_id Post ID.
	 * @param \WP_Post $post    Post object.
	 * @return void
	 */
	public function save_manual_override( int $post_id, \WP_Post $post ): void {
		if ( ! isset( $_POST['prose_classification_nonce'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			return;
		}

		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['prose_classification_nonce'] ) ), self::NONCE_ACTION ) ) {
			return;
		}

		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$has_override = false;
		$taxonomy     = new Form_Taxonomy();

		if ( ! empty( $_POST['prose_override_county'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$county = sanitize_text_field( wp_unslash( $_POST['prose_override_county'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
			update_post_meta( $post_id, Form_Meta::META_DETECTED_COUNTY, $county );
			update_post_meta( $post_id, Form_Meta::META_COUNTY, $county );
			$has_override = true;
		}

		if ( ! empty( $_POST['prose_override_case_type'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$case_type = sanitize_text_field( wp_unslash( $_POST['prose_override_case_type'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
			update_post_meta( $post_id, Form_Meta::META_DETECTED_CASE_TYPE, $case_type );
			$court = (string) get_post_meta( $post_id, Form_Meta::META_DETECTED_COURT, true );
			$parent = $taxonomy->case_type_parent_for_court( $court, $case_type );
			$term_id = $taxonomy->ensure_child_term( $case_type, $parent, Form_Taxonomy::TAXONOMY_CASE_TYPE );

			if ( $term_id ) {
				wp_set_object_terms( $post_id, array( $term_id ), Form_Taxonomy::TAXONOMY_CASE_TYPE );
			}

			$has_override = true;
		}

		$manual_flag = isset( $_POST['prose_manual_override'] ) || $has_override; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		update_post_meta( $post_id, Form_Meta::META_MANUAL_OVERRIDE, $manual_flag );

		if ( $manual_flag ) {
			update_post_meta( $post_id, Form_Meta::META_NEEDS_REVIEW, false );
		}
	}

	/**
	 * Add reclassify row action.
	 *
	 * @param array<string, string> $actions Row actions.
	 * @param \WP_Post                $post    Post object.
	 * @return array<string, string>
	 */
	public function row_actions( array $actions, \WP_Post $post ): array {
		if ( Form_CPT::POST_TYPE !== $post->post_type || ! current_user_can( 'edit_post', $post->ID ) ) {
			return $actions;
		}

		$actions['prose_reclassify'] = sprintf(
			'<a href="#" class="prose-reclassify-link" data-post-id="%1$d" data-force="0">%2$s</a>',
			(int) $post->ID,
			esc_html__( 'Reclassify', 'prose-core' )
		);

		return $actions;
	}

	/**
	 * Register bulk reclassify action.
	 *
	 * @param array<string, string> $actions Bulk actions.
	 * @return array<string, string>
	 */
	public function bulk_actions( array $actions ): array {
		$actions['prose_reclassify'] = __( 'Reclassify Forms', 'prose-core' );
		return $actions;
	}

	/**
	 * Handle bulk reclassify.
	 *
	 * @param string $redirect_to Redirect URL.
	 * @param string $action      Action name.
	 * @param int[]  $post_ids    Post IDs.
	 * @return string
	 */
	public function handle_bulk_reclassify( string $redirect_to, string $action, array $post_ids ): string {
		if ( 'prose_reclassify' !== $action ) {
			return $redirect_to;
		}

		$count = 0;

		foreach ( $post_ids as $post_id ) {
			$result = $this->engine->reclassify( (int) $post_id, false );

			if ( ! empty( $result['success'] ) ) {
				++$count;
			}
		}

		return add_query_arg( 'prose_reclassified', (string) $count, $redirect_to );
	}

	/**
	 * Show bulk reclassify notice.
	 *
	 * @return void
	 */
	public function bulk_notice(): void {
		if ( ! isset( $_GET['prose_reclassified'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		$count = absint( wp_unslash( $_GET['prose_reclassified'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		printf(
			'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
			esc_html(
				sprintf(
					/* translators: %d: number of forms reclassified */
					_n( '%d form reclassified.', '%d forms reclassified.', $count, 'prose-core' ),
					$count
				)
			)
		);
	}

	/**
	 * AJAX handler for single-form reclassify.
	 *
	 * @return void
	 */
	public function ajax_reclassify(): void {
		check_ajax_referer( self::AJAX_RECLASSIFY, 'nonce' );

		$post_id = isset( $_POST['post_id'] ) ? absint( wp_unslash( $_POST['post_id'] ) ) : 0;
		$force   = ! empty( $_POST['force'] );

		if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'prose-core' ) ), 403 );
		}

		if ( function_exists( 'set_time_limit' ) ) {
			set_time_limit( 120 );
		}

		$result = $this->engine->reclassify( $post_id, $force );

		if ( empty( $result['success'] ) ) {
			wp_send_json_error(
				array(
					'message' => (string) ( $result['message'] ?? __( 'Reclassification failed.', 'prose-core' ) ),
				),
				400
			);
		}

		wp_send_json_success(
			array(
				'message' => __( 'Form reclassified successfully.', 'prose-core' ),
				'result'  => $result,
			)
		);
	}

	/**
	 * Get classification display values.
	 *
	 * @param int $post_id Post ID.
	 * @return array<string, mixed>
	 */
	private function get_classification_values( int $post_id ): array {
		$court_terms = get_the_terms( $post_id, Form_Taxonomy::TAXONOMY_COURT );
		$case_terms  = get_the_terms( $post_id, Form_Taxonomy::TAXONOMY_CASE_TYPE );
		$stage_terms = get_the_terms( $post_id, Form_Taxonomy::TAXONOMY_WORKFLOW_STAGE );

		return array(
			'needs_review'           => (bool) get_post_meta( $post_id, Form_Meta::META_NEEDS_REVIEW, true ),
			'confidence'             => (string) get_post_meta( $post_id, Form_Meta::META_CLASSIFICATION_CONFIDENCE, true ),
			'source'                 => (string) get_post_meta( $post_id, Form_Meta::META_CLASSIFICATION_SOURCE, true ),
			'signals'                => $this->decode_signals( (string) get_post_meta( $post_id, Form_Meta::META_CLASSIFICATION_SIGNALS, true ) ),
			'warning'                => (string) get_post_meta( $post_id, Form_Meta::META_CLASSIFICATION_WARNING, true ),
			'supported_court'        => (bool) get_post_meta( $post_id, Form_Meta::META_SUPPORTED_COURT, true ),
			'pdf_fillable'           => (bool) get_post_meta( $post_id, Form_Meta::META_PDF_FILLABLE, true ),
			'pdf_field_count'        => (string) get_post_meta( $post_id, Form_Meta::META_PDF_FIELD_COUNT, true ),
			'dependencies'           => $this->format_json( (string) get_post_meta( $post_id, Form_Meta::META_DEPENDENCIES, true ) ),
			'questionnaire_keys'     => $this->format_json( (string) get_post_meta( $post_id, Form_Meta::META_QUESTIONNAIRE_KEYS, true ) ),
			'workflow_package'       => $this->format_json( (string) get_post_meta( $post_id, Form_Meta::META_WORKFLOW_PACKAGE, true ) ),
			'ai_summary'             => (string) get_post_meta( $post_id, Form_Meta::META_AI_SUMMARY, true ),
			'detected_court'         => (string) get_post_meta( $post_id, Form_Meta::META_DETECTED_COURT, true ),
			'detected_county'        => (string) get_post_meta( $post_id, Form_Meta::META_DETECTED_COUNTY, true ),
			'detected_case_type'     => (string) get_post_meta( $post_id, Form_Meta::META_DETECTED_CASE_TYPE, true ),
			'detected_workflow_stage' => (string) get_post_meta( $post_id, Form_Meta::META_DETECTED_WORKFLOW_STAGE, true ),
			'manual_override'        => (bool) get_post_meta( $post_id, Form_Meta::META_MANUAL_OVERRIDE, true ),
			'override_court'         => ( ! empty( $court_terms ) && ! is_wp_error( $court_terms ) ) ? $court_terms[0]->name : '',
			'override_county'        => (string) get_post_meta( $post_id, Form_Meta::META_COUNTY, true ),
			'override_case_type'     => ( ! empty( $case_terms ) && ! is_wp_error( $case_terms ) ) ? $case_terms[0]->name : '',
			'override_workflow_stage' => ( ! empty( $stage_terms ) && ! is_wp_error( $stage_terms ) ) ? $stage_terms[0]->name : '',
		);
	}

	/**
	 * Decode classification signals JSON into a list of labels.
	 *
	 * @param string $json Raw JSON array.
	 * @return string[]
	 */
	private function decode_signals( string $json ): array {
		if ( '' === trim( $json ) ) {
			return array();
		}

		$decoded = json_decode( $json, true );

		if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $decoded ) ) {
			return array();
		}

		return array_values( array_filter( array_map( 'strval', $decoded ) ) );
	}

	/**
	 * Format JSON for display.
	 *
	 * @param string $json Raw JSON.
	 * @return string
	 */
	private function format_json( string $json ): string {
		if ( '' === trim( $json ) ) {
			return '';
		}

		$decoded = json_decode( $json, true );

		if ( JSON_ERROR_NONE !== json_last_error() ) {
			return $json;
		}

		return wp_json_encode( $decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) ?: $json;
	}
}
