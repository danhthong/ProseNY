<?php
/**
 * CSV form importer admin page with batched AJAX progress.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Forms;

use ProSe\Core\Loader;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Form_Importer
 */
class Form_Importer {

	/**
	 * Admin page slug.
	 */
	public const PAGE_SLUG = 'prose-import-forms';

	/**
	 * Upload nonce action.
	 */
	private const NONCE_ACTION = 'prose_import_forms';

	/**
	 * AJAX action name.
	 */
	private const AJAX_ACTION = 'prose_import_batch';

	/**
	 * Number of CSV rows processed per AJAX batch.
	 *
	 * Keep this low on shared hosting — each row may download a PDF.
	 */
	private const BATCH_SIZE = 1;

	/**
	 * Transient prefix for import jobs.
	 */
	private const JOB_PREFIX = 'prose_import_job_';

	/**
	 * Form repository.
	 *
	 * @var Form_Repository
	 */
	private Form_Repository $repository;

	/**
	 * File manager.
	 *
	 * @var Form_File_Manager
	 */
	private Form_File_Manager $file_manager;

	/**
	 * Classification engine.
	 *
	 * @var Classification\Classification_Engine|null
	 */
	private ?Classification\Classification_Engine $classification_engine;

	/**
	 * Constructor.
	 *
	 * @param Form_Repository                    $repository            Form repository.
	 * @param Form_File_Manager                  $file_manager          File manager.
	 * @param Classification\Classification_Engine|null $classification_engine Classification engine.
	 */
	public function __construct(
		Form_Repository $repository,
		Form_File_Manager $file_manager,
		?Classification\Classification_Engine $classification_engine = null
	) {
		$this->repository            = $repository;
		$this->file_manager          = $file_manager;
		$this->classification_engine = $classification_engine;
	}

	/**
	 * Register hooks.
	 *
	 * @param Loader $loader Hook loader.
	 * @return void
	 */
	public function register( Loader $loader ): void {
		$loader->add_action( 'admin_menu', $this, 'register_menu', 20 );
		$loader->add_action( 'admin_init', $this, 'handle_upload' );
		$loader->add_action( 'admin_enqueue_scripts', $this, 'enqueue_assets' );
		$loader->add_action( 'wp_ajax_' . self::AJAX_ACTION, $this, 'ajax_process_batch' );
	}

	/**
	 * Register Import Forms submenu.
	 *
	 * @return void
	 */
	public function register_menu(): void {
		add_submenu_page(
			'prose',
			__( 'Import Forms', 'prose-core' ),
			__( 'Import Forms', 'prose-core' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Enqueue importer JS data on the import screen.
	 *
	 * @param string $hook_suffix Current admin page hook suffix.
	 * @return void
	 */
	public function enqueue_assets( string $hook_suffix ): void {
		if ( 'prose_page_' . self::PAGE_SLUG !== $hook_suffix ) {
			return;
		}

		wp_enqueue_script(
			'prose-core-admin',
			PROSE_CORE_URL . 'assets/js/admin.js',
			array(),
			PROSE_CORE_VERSION,
			true
		);

		wp_localize_script(
			'prose-core-admin',
			'proseImport',
			array(
				'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
				'action'    => self::AJAX_ACTION,
				'nonce'     => wp_create_nonce( self::AJAX_ACTION ),
				'batchSize' => self::BATCH_SIZE,
				'i18n'      => array(
					'starting'  => __( 'Starting import...', 'prose-core' ),
					'progress'  => __( 'Processing %1$d of %2$d forms...', 'prose-core' ),
					'completed' => __( 'Import complete: %1$d created, %2$d updated, %3$d failed. Files: %4$d URLs processed, %5$d downloaded, %6$d skipped, %7$d failed.', 'prose-core' ),
					'error'     => __( 'An error occurred during import.', 'prose-core' ),
					'httpError' => __( 'Server error (HTTP %s). Check PHP error logs or enable WP_DEBUG.', 'prose-core' ),
				),
			)
		);
	}

	/**
	 * Handle the CSV upload, prepare a job, then redirect to the progress view.
	 *
	 * @return void
	 */
	public function handle_upload(): void {
		if ( ! isset( $_POST['prose_import_submit'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		check_admin_referer( self::NONCE_ACTION, 'prose_import_nonce' );

		if ( empty( $_FILES['prose_csv_file']['tmp_name'] ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			$this->redirect_with_notice( 'no_file' );
		}

		$file = $_FILES['prose_csv_file']; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

		if ( UPLOAD_ERR_OK !== (int) $file['error'] ) {
			$this->redirect_with_notice( 'upload_error' );
		}

		$extension = strtolower( pathinfo( sanitize_file_name( (string) $file['name'] ), PATHINFO_EXTENSION ) );

		if ( 'csv' !== $extension ) {
			$this->redirect_with_notice( 'invalid_type' );
		}

		$stored = $this->store_upload( (string) $file['tmp_name'] );

		if ( is_wp_error( $stored ) ) {
			$this->redirect_with_notice( 'store_failed' );
		}

		$prepared = $this->prepare_job( $stored );

		if ( is_wp_error( $prepared ) ) {
			wp_delete_file( $stored );
			$this->redirect_with_notice( 'parse_failed' );
		}

		$token = $prepared;

		wp_safe_redirect(
			add_query_arg(
				array(
					'page' => self::PAGE_SLUG,
					'job'  => rawurlencode( $token ),
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Render the import admin page (upload form or progress view).
	 *
	 * @return void
	 */
	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'prose-core' ) );
		}

		$token = isset( $_GET['job'] ) ? sanitize_text_field( wp_unslash( $_GET['job'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$job   = '' !== $token ? get_transient( self::JOB_PREFIX . $token ) : false;
		?>
		<div class="wrap prose-admin-wrap">
			<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
			<?php $this->render_notice(); ?>

			<?php if ( is_array( $job ) ) : ?>
				<?php $this->render_progress( $token, (int) $job['total'] ); ?>
			<?php else : ?>
				<?php $this->render_upload_form(); ?>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Render the upload form.
	 *
	 * @return void
	 */
	private function render_upload_form(): void {
		?>
		<p><?php esc_html_e( 'Upload a CSV file to import court forms. Expected columns include Form Number, Form Title, Case Type, Court (optional), PDF Filenames, and Resolved PDF URLs.', 'prose-core' ); ?></p>
		<form method="post" enctype="multipart/form-data" class="prose-import-form">
			<?php wp_nonce_field( self::NONCE_ACTION, 'prose_import_nonce' ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row">
						<label for="prose_csv_file"><?php esc_html_e( 'CSV File', 'prose-core' ); ?></label>
					</th>
					<td>
						<input type="file" name="prose_csv_file" id="prose_csv_file" accept=".csv,text/csv" required />
						<p class="description">
							<?php esc_html_e( 'Supported headers: Form Number (or Extracted Form Number), Form Title (or Original Form Title), Case Type, Court (optional), PDF Filenames, Resolved PDF URLs.', 'prose-core' ); ?>
						</p>
					</td>
				</tr>
			</table>
			<?php submit_button( __( 'Upload and Import', 'prose-core' ), 'primary', 'prose_import_submit' ); ?>
		</form>
		<?php
	}

	/**
	 * Render the progress view.
	 *
	 * @param string $token Job token.
	 * @param int    $total Total rows to process.
	 * @return void
	 */
	private function render_progress( string $token, int $total ): void {
		?>
		<div
			class="prose-import-progress"
			data-token="<?php echo esc_attr( $token ); ?>"
			data-total="<?php echo esc_attr( (string) $total ); ?>"
		>
			<p>
				<?php
				printf(
					/* translators: %d: total number of forms */
					esc_html__( 'Importing %d forms. Please keep this page open.', 'prose-core' ),
					(int) $total
				);
				?>
			</p>

			<div class="prose-progress-bar" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0">
				<div class="prose-progress-bar__fill" style="width:0%"></div>
			</div>

			<p class="prose-progress-status"><?php esc_html_e( 'Starting import...', 'prose-core' ); ?></p>

			<p>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE_SLUG ) ); ?>" class="button prose-import-restart" style="display:none;">
					<?php esc_html_e( 'Import another file', 'prose-core' ); ?>
				</a>
				<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=' . Form_CPT::POST_TYPE ) ); ?>" class="button button-primary prose-import-view" style="display:none;">
					<?php esc_html_e( 'View imported forms', 'prose-core' ); ?>
				</a>
			</p>

			<table class="widefat striped prose-import-results">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Form Number', 'prose-core' ); ?></th>
						<th><?php esc_html_e( 'Title', 'prose-core' ); ?></th>
						<th><?php esc_html_e( 'Status', 'prose-core' ); ?></th>
						<th><?php esc_html_e( 'Message', 'prose-core' ); ?></th>
					</tr>
				</thead>
				<tbody></tbody>
			</table>
		</div>
		<?php
	}

	/**
	 * Process one batch of rows over AJAX.
	 *
	 * @return void
	 */
	public function ajax_process_batch(): void {
		try {
			check_ajax_referer( self::AJAX_ACTION, 'nonce' );

			if ( ! current_user_can( 'manage_options' ) ) {
				wp_send_json_error( array( 'message' => __( 'Permission denied.', 'prose-core' ) ), 403 );
			}

			if ( function_exists( 'set_time_limit' ) ) {
				set_time_limit( 300 );
			}

			$token = isset( $_POST['token'] ) ? sanitize_text_field( wp_unslash( $_POST['token'] ) ) : '';
			$job   = '' !== $token ? get_transient( self::JOB_PREFIX . $token ) : false;

			if ( ! is_array( $job ) ) {
				wp_send_json_error( array( 'message' => __( 'Import job not found or expired.', 'prose-core' ) ), 404 );
			}

			if ( empty( $job['file'] ) || ! file_exists( (string) $job['file'] ) ) {
				wp_send_json_error(
					array(
						'message' => __( 'Import file is missing. Please upload the CSV again.', 'prose-core' ),
					),
					404
				);
			}

			$batch = $this->process_batch( $job );

			set_transient( self::JOB_PREFIX . $token, $batch['job'], HOUR_IN_SECONDS );

			$done = $batch['job']['offset'] >= $batch['job']['total'];

			if ( $done ) {
				if ( ! empty( $batch['job']['file'] ) && file_exists( $batch['job']['file'] ) ) {
					wp_delete_file( $batch['job']['file'] );
				}
				delete_transient( self::JOB_PREFIX . $token );
			}

			wp_send_json_success(
				array(
					'processed'        => (int) $batch['job']['offset'],
					'total'            => (int) $batch['job']['total'],
					'created'          => (int) $batch['job']['created'],
					'updated'          => (int) $batch['job']['updated'],
					'failed'           => (int) $batch['job']['failed'],
					'urls_processed'   => (int) ( $batch['job']['urls_processed'] ?? 0 ),
					'files_downloaded' => (int) ( $batch['job']['files_downloaded'] ?? 0 ),
					'files_skipped'    => (int) ( $batch['job']['files_skipped'] ?? 0 ),
					'files_failed'     => (int) ( $batch['job']['files_failed'] ?? 0 ),
					'done'             => $done,
					'results'          => $batch['results'],
				)
			);
		} catch ( \Throwable $exception ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log( 'ProSe import batch failed: ' . $exception->getMessage() );
			}

			wp_send_json_error(
				array(
					'message' => ( defined( 'WP_DEBUG' ) && WP_DEBUG )
						? $exception->getMessage()
						: __( 'An error occurred during import.', 'prose-core' ),
				),
				500
			);
		}
	}

	/**
	 * Store the uploaded CSV in a private imports directory.
	 *
	 * @param string $tmp_name Temporary upload path.
	 * @return string|\WP_Error Stored file path.
	 */
	private function store_upload( string $tmp_name ) {
		$dir = $this->get_import_dir();

		if ( is_wp_error( $dir ) ) {
			return $dir;
		}

		$dest = $dir . 'import-' . wp_generate_password( 12, false ) . '.csv';

		if ( ! function_exists( 'WP_Filesystem' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		if ( ! @move_uploaded_file( $tmp_name, $dest ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_move_uploaded_file
			return new \WP_Error( 'prose_store_failed', __( 'Could not store the uploaded file.', 'prose-core' ) );
		}

		return $dest;
	}

	/**
	 * Parse the CSV header, count rows, and create a job transient.
	 *
	 * @param string $file_path Stored CSV path.
	 * @return string|\WP_Error Job token.
	 */
	private function prepare_job( string $file_path ) {
		$handle = fopen( $file_path, 'r' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen

		if ( false === $handle ) {
			return new \WP_Error( 'prose_open_failed', __( 'Could not open CSV file.', 'prose-core' ) );
		}

		$header = fgetcsv( $handle );

		if ( false === $header || empty( $header ) ) {
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			return new \WP_Error( 'prose_no_header', __( 'CSV file has no header row.', 'prose-core' ) );
		}

		$column_map = $this->build_column_map( $header );
		$total      = 0;

		while ( ( $row = fgetcsv( $handle ) ) !== false ) { // phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition
			if ( ! $this->is_empty_row( $row ) ) {
				++$total;
			}
		}

		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		$token = wp_generate_password( 20, false );

		$job = array(
			'file'             => $file_path,
			'map'              => $column_map,
			'total'            => $total,
			'offset'           => 0,
			'created'          => 0,
			'updated'          => 0,
			'failed'           => 0,
			'urls_processed'   => 0,
			'files_downloaded' => 0,
			'files_skipped'    => 0,
			'files_failed'     => 0,
		);

		set_transient( self::JOB_PREFIX . $token, $job, HOUR_IN_SECONDS );

		return $token;
	}

	/**
	 * Process a single batch of rows for a job.
	 *
	 * @param array<string, mixed> $job Job state.
	 * @return array{job: array<string, mixed>, results: array<int, array<string, mixed>>}
	 */
	private function process_batch( array $job ): array {
		$results = array();
		$handle  = fopen( (string) $job['file'], 'r' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen

		if ( false === $handle ) {
			$job['offset'] = (int) $job['total'];
			return array(
				'job'     => $job,
				'results' => $results,
			);
		}

		fgetcsv( $handle ); // Skip header.

		$skipped   = 0;
		$processed = 0;
		$offset    = (int) $job['offset'];

		while ( ( $row = fgetcsv( $handle ) ) !== false ) { // phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition
			if ( $this->is_empty_row( $row ) ) {
				continue;
			}

			// Skip rows already processed in previous batches.
			if ( $skipped < $offset ) {
				++$skipped;
				continue;
			}

			$result    = $this->import_row( $row, (array) $job['map'] );
			$results[] = $result;

			switch ( $result['status'] ) {
				case 'created':
					++$job['created'];
					break;
				case 'updated':
					++$job['updated'];
					break;
				default:
					++$job['failed'];
					break;
			}

			if ( ! empty( $result['file_stats'] ) && is_array( $result['file_stats'] ) ) {
				$job['urls_processed']   += (int) ( $result['file_stats']['urls_processed'] ?? 0 );
				$job['files_downloaded'] += (int) ( $result['file_stats']['files_downloaded'] ?? 0 );
				$job['files_skipped']    += (int) ( $result['file_stats']['files_skipped'] ?? 0 );
				$job['files_failed']     += (int) ( $result['file_stats']['files_failed'] ?? 0 );
			}

			++$job['offset'];
			++$processed;

			$batch_size = (int) apply_filters( 'prose_core_import_batch_size', self::BATCH_SIZE );

			if ( $batch_size < 1 ) {
				$batch_size = 1;
			}

			if ( $processed >= $batch_size ) {
				break;
			}
		}

		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		// Guard against drift if the file shrank or all remaining rows were empty.
		if ( 0 === $processed ) {
			$job['offset'] = (int) $job['total'];
		}

		return array(
			'job'     => $job,
			'results' => $results,
		);
	}

	/**
	 * Import a single CSV row.
	 *
	 * @param array<int, string|null> $row        CSV row.
	 * @param array<string, int>      $column_map Column index map.
	 * @return array<string, mixed>
	 */
	private function import_row( array $row, array $column_map ): array {
		$form_id   = $this->normalize_form_number( $this->get_column_value( $row, $column_map, 'form_number' ) );
		$title     = $this->get_column_value( $row, $column_map, 'form_title' );
		$case_type = $this->get_column_value( $row, $column_map, 'case_type' );
		$court     = $this->get_column_value( $row, $column_map, 'court' );
		$filenames = $this->get_column_value( $row, $column_map, 'pdf_filenames' );
		$pdf_urls  = $this->get_column_value( $row, $column_map, 'resolved_pdf_urls' );

		if ( '' === $title && '' === $form_id ) {
			return array(
				'form_id' => '',
				'title'   => '',
				'status'  => 'failed',
				'message' => __( 'Missing form title.', 'prose-core' ),
			);
		}

		$case_types = $this->parse_list( $case_type, ',' );
		$courts     = $this->parse_list( $court, ',' );
		$url_list   = $this->parse_list( $pdf_urls, '|' );
		$name_list  = $this->parse_list( $filenames, '|' );
		$pairs      = $this->build_source_file_pairs( $url_list, $name_list );
		$form_slug  = '' !== $form_id ? sanitize_title( $form_id ) : sanitize_title( $title );

		$data = array(
			'form_code'  => $form_id,
			'form_id'    => $form_id,
			'title'      => '' !== $title ? $title : $form_id,
			'case_types' => $case_types,
			'court'      => $courts,
		);

		$messages   = array();
		$file_stats = array(
			'urls_processed'   => 0,
			'files_downloaded' => 0,
			'files_skipped'    => 0,
			'files_failed'     => 0,
		);

		$existing_files = array();
		$existing_post  = '' !== $form_id
			? $this->repository->get_by_form_code( $form_id )
			: ( '' !== $title ? $this->repository->get_by_title( $title ) : null );

		if ( $existing_post instanceof \WP_Post ) {
			$existing_files = $this->get_existing_source_files( (int) $existing_post->ID );
		}

		if ( ! empty( $pairs ) ) {
			$download_result = $this->file_manager->download_all_source_files(
				$pairs,
				$form_slug,
				$existing_files
			);

			$file_stats = $download_result['stats'];
			$messages   = array_merge( $messages, $download_result['messages'] );

			$data['source_files'] = array(
				'files' => $download_result['files'],
			);

			$primary = $this->select_primary_pdf( $download_result['files'] );

			if ( ! empty( $primary['url'] ) ) {
				$data['source_pdf_url'] = $primary['url'];

				if ( ! empty( $primary['filename'] ) ) {
					$data['file_name'] = $primary['filename'];
				}

				if ( ! empty( $primary['local_url'] ) ) {
					$data['file_url'] = $primary['local_url'];
				} elseif ( ! empty( $primary['filename'] ) ) {
					$local_url = $this->file_manager->resolve_local_path( $form_slug, $primary['filename'] );

					if ( '' !== $local_url ) {
						$upload_dir = $this->file_manager->get_upload_dir();

						if ( ! is_wp_error( $upload_dir ) ) {
							$data['file_url'] = str_replace(
								$upload_dir['path'],
								$upload_dir['url'],
								$local_url
							);
						}
					}
				}
			}
		} elseif ( ! empty( $existing_files ) ) {
			$data['source_files'] = array(
				'files' => $existing_files,
			);
		}

		$result = $this->repository->create_or_update( $data );

		if ( is_wp_error( $result ) ) {
			return array(
				'form_id' => $form_id,
				'title'   => $title,
				'status'  => 'failed',
				'message' => $result->get_error_message(),
			);
		}

		$status  = ! empty( $result['created'] ) ? 'created' : 'updated';
		$message = ! empty( $messages ) ? implode( ' ', $messages ) : __( 'Imported successfully.', 'prose-core' );

		if ( $file_stats['urls_processed'] > 0 ) {
			$message .= ' ' . sprintf(
				/* translators: 1: URLs processed, 2: downloaded, 3: skipped, 4: failed */
				__( 'Files: %1$d URLs, %2$d downloaded, %3$d skipped, %4$d failed.', 'prose-core' ),
				(int) $file_stats['urls_processed'],
				(int) $file_stats['files_downloaded'],
				(int) $file_stats['files_skipped'],
				(int) $file_stats['files_failed']
			);
		}

		$post_id = (int) $result['post_id'];

		if ( $this->classification_engine && apply_filters( 'prose_core_auto_classify_on_import', true ) ) {
			$csv_hints = array(
				'court'          => ! empty( $courts[0] ) ? $courts[0] : '',
				'case_type'      => ! empty( $case_types[0] ) ? $case_types[0] : '',
				'workflow_stage' => '',
			);

			$classify = $this->classification_engine->classify( $post_id, $csv_hints );

			if ( ! empty( $classify['success'] ) ) {
				$message .= ' ' . __( 'Form classified from PDF.', 'prose-core' );

				if ( ! empty( $classify['needs_review'] ) ) {
					$message .= ' ' . __( 'Flagged for manual review.', 'prose-core' );
				}
			} elseif ( ! empty( $classify['message'] ) ) {
				$message .= ' ' . $classify['message'];
			}
		}

		return array(
			'form_id'    => $form_id,
			'title'      => $title,
			'status'     => $status,
			'message'    => $message,
			'file_stats' => $file_stats,
		);
	}

	/**
	 * Load existing source file metadata for a form post.
	 *
	 * @param int $post_id Form post ID.
	 * @return array<int, array<string, mixed>>
	 */
	private function get_existing_source_files( int $post_id ): array {
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
	 * Build deduplicated URL/filename pairs from CSV lists.
	 *
	 * @param string[] $urls      Source URLs.
	 * @param string[] $filenames Source filenames.
	 * @return array<int, array{url: string, filename: string}>
	 */
	public function build_source_file_pairs( array $urls, array $filenames ): array {
		$pairs = array();
		$seen  = array();

		foreach ( $urls as $index => $url ) {
			$url = trim( (string) $url );

			if ( '' === $url ) {
				continue;
			}

			$normalized = esc_url_raw( $url );

			if ( '' === $normalized || isset( $seen[ $normalized ] ) ) {
				continue;
			}

			$filename = $filenames[ $index ] ?? basename( (string) wp_parse_url( $url, PHP_URL_PATH ) );
			$pairs[]  = array(
				'url'      => $url,
				'filename' => trim( (string) $filename ),
			);
			$seen[ $normalized ] = true;
		}

		return $pairs;
	}

	/**
	 * Build a normalized column map from CSV headers.
	 *
	 * @param array<int, string|null> $header CSV header row.
	 * @return array<string, int[]>
	 */
	private function build_column_map( array $header ): array {
		$aliases = array(
			'form_number'       => array( 'form number', 'extracted form number', 'original form number' ),
			'form_title'        => array( 'form title', 'original form title' ),
			'case_type'         => array( 'case type' ),
			'court'             => array( 'court' ),
			'pdf_filenames'     => array( 'pdf filenames' ),
			'resolved_pdf_urls' => array( 'resolved pdf urls' ),
		);

		$normalized_header = array();

		foreach ( $header as $index => $column ) {
			$normalized_header[ (int) $index ] = strtolower( trim( (string) $column ) );
		}

		$map = array();

		foreach ( $aliases as $key => $names ) {
			$map[ $key ] = array();

			foreach ( $names as $alias ) {
				foreach ( $normalized_header as $index => $normalized ) {
					if ( $alias === $normalized ) {
						$map[ $key ][] = $index;
						break;
					}
				}
			}
		}

		return $map;
	}

	/**
	 * Normalize a form number value.
	 *
	 * @param string $value Raw form number.
	 * @return string
	 */
	private function normalize_form_number( string $value ): string {
		$value = trim( $value );

		if ( '' === $value || '--' === $value ) {
			return '';
		}

		return $value;
	}

	/**
	 * Get a column value from a row using the map.
	 *
	 * @param array<int, string|null> $row        CSV row.
	 * @param array<string, int[]>    $column_map Column index map.
	 * @param string                  $key        Column key.
	 * @return string
	 */
	private function get_column_value( array $row, array $column_map, string $key ): string {
		if ( ! isset( $column_map[ $key ] ) ) {
			return '';
		}

		foreach ( $column_map[ $key ] as $index ) {
			if ( ! isset( $row[ $index ] ) ) {
				continue;
			}

			$value = trim( (string) $row[ $index ] );

			if ( 'form_number' === $key ) {
				$value = $this->normalize_form_number( $value );
			}

			if ( '' !== $value ) {
				return $value;
			}
		}

		return '';
	}

	/**
	 * Parse a delimited list into trimmed values.
	 *
	 * @param string $value     Raw value.
	 * @param string $delimiter Delimiter character.
	 * @return string[]
	 */
	private function parse_list( string $value, string $delimiter ): array {
		if ( '' === trim( $value ) ) {
			return array();
		}

		$parts = explode( $delimiter, $value );

		return array_values(
			array_filter(
				array_map( 'trim', $parts ),
				static fn( $part ) => '' !== $part
			)
		);
	}

	/**
	 * Select the primary PDF for legacy single-file metadata.
	 *
	 * Prefers the first successful .pdf entry; falls back to the first PDF URL.
	 *
	 * @param array<int, array<string, mixed>> $files Downloaded file metadata entries.
	 * @return array{url: string, filename: string, local_url: string}
	 */
	public function select_primary_pdf( array $files ): array {
		if ( empty( $files ) ) {
			return array(
				'url'       => '',
				'filename'  => '',
				'local_url' => '',
			);
		}

		foreach ( $files as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}

			$filename  = (string) ( $entry['filename'] ?? '' );
			$url       = (string) ( $entry['source_url'] ?? '' );
			$extension = strtolower( (string) ( $entry['extension'] ?? pathinfo( $filename, PATHINFO_EXTENSION ) ) );
			$status    = (string) ( $entry['download_status'] ?? '' );

			if ( in_array( $status, array( 'failed', 'unsupported' ), true ) ) {
				continue;
			}

			if ( 'pdf' === $extension || str_ends_with( strtolower( $filename ), '.pdf' ) || str_ends_with( strtolower( $url ), '.pdf' ) ) {
				return array(
					'url'       => $url,
					'filename'  => $filename,
					'local_url' => (string) ( $entry['local_url'] ?? '' ),
				);
			}
		}

		$first = $files[0];

		return array(
			'url'       => (string) ( $first['source_url'] ?? '' ),
			'filename'  => (string) ( $first['filename'] ?? '' ),
			'local_url' => (string) ( $first['local_url'] ?? '' ),
		);
	}

	/**
	 * Select the best PDF URL/filename pair from lists.
	 *
	 * Prefers the first .pdf entry; falls back to the first item.
	 *
	 * @param string[] $urls      PDF URLs.
	 * @param string[] $filenames PDF filenames.
	 * @return array{url: string, filename: string}
	 * @deprecated Use build_source_file_pairs() and select_primary_pdf() instead.
	 */
	private function select_pdf( array $urls, array $filenames ): array {
		if ( empty( $urls ) ) {
			return array(
				'url'      => '',
				'filename' => $filenames[0] ?? '',
			);
		}

		foreach ( $urls as $index => $url ) {
			$filename = $filenames[ $index ] ?? basename( (string) wp_parse_url( $url, PHP_URL_PATH ) );

			if ( str_ends_with( strtolower( $filename ), '.pdf' ) || str_ends_with( strtolower( $url ), '.pdf' ) ) {
				return array(
					'url'      => $url,
					'filename' => $filename,
				);
			}
		}

		return array(
			'url'      => $urls[0],
			'filename' => $filenames[0] ?? '',
		);
	}

	/**
	 * Check if a CSV row is empty.
	 *
	 * @param array<int, string|null> $row CSV row.
	 * @return bool
	 */
	private function is_empty_row( array $row ): bool {
		if ( ! is_array( $row ) ) {
			return true;
		}

		foreach ( $row as $cell ) {
			if ( null !== $cell && '' !== trim( (string) $cell ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Get (and ensure) the private imports directory.
	 *
	 * @return string|\WP_Error
	 */
	private function get_import_dir() {
		$upload = wp_upload_dir();

		if ( ! empty( $upload['error'] ) ) {
			return new \WP_Error( 'prose_upload_dir', $upload['error'] );
		}

		$path = trailingslashit( $upload['basedir'] ) . 'prose/imports';

		if ( ! wp_mkdir_p( $path ) ) {
			return new \WP_Error( 'prose_upload_dir', __( 'Could not create the imports directory.', 'prose-core' ) );
		}

		$index = trailingslashit( $path ) . 'index.html';

		if ( ! file_exists( $index ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			file_put_contents( $index, '' );
		}

		return trailingslashit( $path );
	}

	/**
	 * Redirect back to the import page with a notice code.
	 *
	 * @param string $code Notice code.
	 * @return void
	 */
	private function redirect_with_notice( string $code ): void {
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'           => self::PAGE_SLUG,
					'prose_notice'   => $code,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Render a notice based on the prose_notice query arg.
	 *
	 * @return void
	 */
	private function render_notice(): void {
		$code = isset( $_GET['prose_notice'] ) ? sanitize_key( wp_unslash( $_GET['prose_notice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( '' === $code ) {
			return;
		}

		$messages = array(
			'no_file'      => __( 'Please select a CSV file to upload.', 'prose-core' ),
			'upload_error' => __( 'File upload failed.', 'prose-core' ),
			'invalid_type' => __( 'Only CSV files are allowed.', 'prose-core' ),
			'store_failed' => __( 'Could not store the uploaded file.', 'prose-core' ),
			'parse_failed' => __( 'Could not read the CSV file.', 'prose-core' ),
		);

		if ( ! isset( $messages[ $code ] ) ) {
			return;
		}

		printf(
			'<div class="notice notice-error"><p>%s</p></div>',
			esc_html( $messages[ $code ] )
		);
	}
}
