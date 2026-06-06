<?php

namespace NYCourtFormsCollector\Includes;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin page and AJAX handlers.
 */
class Admin {

	/**
	 * Singleton instance.
	 *
	 * @var Admin|null
	 */
	private static ?Admin $instance = null;

	/**
	 * Plugin slug.
	 *
	 * @var string
	 */
	private string $slug = 'ny-court-forms-collector';

	/**
	 * Get singleton instance.
	 *
	 * @return Admin
	 */
	public static function instance(): Admin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		add_action( 'admin_menu', [ $this, 'register_menu' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );

		add_action( 'wp_ajax_nycfc_upload_csv', [ $this, 'ajax_upload_csv' ] );
		add_action( 'wp_ajax_nycfc_start_crawl', [ $this, 'ajax_start_crawl' ] );
		add_action( 'wp_ajax_nycfc_process_batch', [ $this, 'ajax_process_batch' ] );
		add_action( 'wp_ajax_nycfc_pause_crawl', [ $this, 'ajax_pause_crawl' ] );
		add_action( 'wp_ajax_nycfc_resume_crawl', [ $this, 'ajax_resume_crawl' ] );
		add_action( 'wp_ajax_nycfc_reset_crawl', [ $this, 'ajax_reset_crawl' ] );
		add_action( 'wp_ajax_nycfc_get_progress', [ $this, 'ajax_get_progress' ] );

		add_action( 'admin_post_nycfc_download_export', [ $this, 'handle_download_export' ] );
	}

	/**
	 * Register admin menu page.
	 */
	public function register_menu(): void {
		add_submenu_page(
			'tools.php',
			__( 'NY Court Forms Collector', 'ny-court-forms-collector' ),
			__( 'NY Court Forms Collector', 'ny-court-forms-collector' ),
			'manage_options',
			$this->slug,
			[ $this, 'render_page' ]
		);
	}

	/**
	 * Enqueue admin assets.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue_assets( string $hook ): void {
		if ( 'tools_page_' . $this->slug !== $hook ) {
			return;
		}

		wp_enqueue_style(
			'nycfc-admin',
			NYCFC_PLUGIN_URL . 'assets/admin.css',
			[],
			NYCFC_VERSION
		);

		wp_enqueue_script(
			'nycfc-admin',
			NYCFC_PLUGIN_URL . 'assets/admin.js',
			[ 'jquery' ],
			NYCFC_VERSION,
			true
		);

		wp_localize_script(
			'nycfc-admin',
			'nycfcAdmin',
			[
				'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
				'nonce'     => wp_create_nonce( 'nycfc_ajax' ),
				'batchSize' => defined( 'NYCFC_BATCH_SIZE' ) ? (int) NYCFC_BATCH_SIZE : 5,
				'pollMs'    => 2000,
				'strings'   => [
					'uploadSuccess' => __( 'CSV uploaded successfully.', 'ny-court-forms-collector' ),
					'uploadError'   => __( 'CSV upload failed.', 'ny-court-forms-collector' ),
					'crawlStarted'  => __( 'Crawl started.', 'ny-court-forms-collector' ),
					'crawlPaused'   => __( 'Crawl paused.', 'ny-court-forms-collector' ),
					'crawlResumed'  => __( 'Crawl resumed.', 'ny-court-forms-collector' ),
					'crawlReset'    => __( 'Crawl reset.', 'ny-court-forms-collector' ),
					'noCsv'         => __( 'Upload a CSV before starting the crawl.', 'ny-court-forms-collector' ),
					'completed'     => __( 'Crawl completed.', 'ny-court-forms-collector' ),
				],
			]
		);
	}

	/**
	 * Render admin page.
	 */
	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$progress      = CSV::get_progress();
		$log_entries   = CSV::get_log_entries();
		$download_url  = Export::get_download_url();
		$has_export    = '' !== CSV::get_export_file() && file_exists( CSV::get_export_file() );
		$has_rows      = ! empty( CSV::get_rows() );
		$status        = $progress['crawl_status'] ?? 'idle';
		?>
		<div class="wrap nycfc-wrap">
			<h1><?php esc_html_e( 'NY Court Forms Collector', 'ny-court-forms-collector' ); ?></h1>
			<p class="description">
				<?php esc_html_e( 'Upload a CSV with columns Form Number, Form Title, and Form URL. Crawl each URL and export extracted data.', 'ny-court-forms-collector' ); ?>
			</p>

			<div class="nycfc-panel">
				<h2><?php esc_html_e( 'CSV Upload', 'ny-court-forms-collector' ); ?></h2>
				<form id="nycfc-upload-form" enctype="multipart/form-data">
					<p>
						<label for="nycfc-csv-file"><?php esc_html_e( 'CSV File', 'ny-court-forms-collector' ); ?></label><br>
						<input type="file" id="nycfc-csv-file" name="csv_file" accept=".csv,text/csv" required>
					</p>
					<p>
						<button type="submit" class="button button-primary" id="nycfc-upload-btn">
							<?php esc_html_e( 'Upload CSV', 'ny-court-forms-collector' ); ?>
						</button>
					</p>
				</form>
				<div id="nycfc-upload-message" class="nycfc-message" aria-live="polite"></div>
			</div>

			<div class="nycfc-panel">
				<h2><?php esc_html_e( 'Crawl Controls', 'ny-court-forms-collector' ); ?></h2>
				<div class="nycfc-controls">
					<button type="button" class="button button-primary" id="nycfc-start-btn" <?php disabled( ! $has_rows ); ?>>
						<?php esc_html_e( 'Start Crawl', 'ny-court-forms-collector' ); ?>
					</button>
					<button type="button" class="button" id="nycfc-pause-btn" <?php disabled( 'running' !== $status ); ?>>
						<?php esc_html_e( 'Pause Crawl', 'ny-court-forms-collector' ); ?>
					</button>
					<button type="button" class="button" id="nycfc-resume-btn" <?php disabled( 'paused' !== $status ); ?>>
						<?php esc_html_e( 'Resume Crawl', 'ny-court-forms-collector' ); ?>
					</button>
					<button type="button" class="button button-secondary" id="nycfc-reset-btn">
						<?php esc_html_e( 'Reset Crawl', 'ny-court-forms-collector' ); ?>
					</button>
					<a href="<?php echo esc_url( $download_url ); ?>" class="button button-secondary" id="nycfc-download-btn" <?php echo $has_export ? '' : 'style="display:none;"'; ?>>
						<?php esc_html_e( 'Download Export CSV', 'ny-court-forms-collector' ); ?>
					</a>
				</div>
			</div>

			<div class="nycfc-panel">
				<h2><?php esc_html_e( 'Progress', 'ny-court-forms-collector' ); ?></h2>

				<div class="nycfc-progress-wrap">
					<div class="nycfc-progress-track">
						<div id="nycfc-progress-bar" class="nycfc-progress-bar" style="width:0%;"></div>
					</div>
				</div>

				<div class="nycfc-stats">
					<div><strong><?php esc_html_e( 'Current Row:', 'ny-court-forms-collector' ); ?></strong> <span id="nycfc-current-row">0</span></div>
					<div><strong><?php esc_html_e( 'Current URL:', 'ny-court-forms-collector' ); ?></strong> <span id="nycfc-current-url"><?php esc_html_e( 'None', 'ny-court-forms-collector' ); ?></span></div>
					<div><strong><?php esc_html_e( 'Progress:', 'ny-court-forms-collector' ); ?></strong> <span id="nycfc-progress-text">0 / 0</span></div>
					<div><strong><?php esc_html_e( 'Rows Completed:', 'ny-court-forms-collector' ); ?></strong> <span id="nycfc-rows-completed">0</span></div>
					<div><strong><?php esc_html_e( 'Rows Remaining:', 'ny-court-forms-collector' ); ?></strong> <span id="nycfc-rows-remaining">0</span></div>
					<div><strong><?php esc_html_e( 'Success:', 'ny-court-forms-collector' ); ?></strong> <span id="nycfc-success-count">0</span></div>
					<div><strong><?php esc_html_e( 'Failed:', 'ny-court-forms-collector' ); ?></strong> <span id="nycfc-failed-count">0</span></div>
					<div><strong><?php esc_html_e( 'Speed:', 'ny-court-forms-collector' ); ?></strong> <span id="nycfc-speed">0 <?php esc_html_e( 'rows/min', 'ny-court-forms-collector' ); ?></span></div>
					<div><strong><?php esc_html_e( 'ETA:', 'ny-court-forms-collector' ); ?></strong> <span id="nycfc-eta"><?php esc_html_e( 'N/A', 'ny-court-forms-collector' ); ?></span></div>
					<div><strong><?php esc_html_e( 'Status:', 'ny-court-forms-collector' ); ?></strong> <span id="nycfc-status"><?php echo esc_html( ucfirst( $status ) ); ?></span></div>
				</div>
			</div>

			<div class="nycfc-panel">
				<h2><?php esc_html_e( 'Activity Log', 'ny-court-forms-collector' ); ?></h2>
				<div id="nycfc-activity-log" class="nycfc-activity-log" aria-live="polite">
					<?php foreach ( $log_entries as $entry ) : ?>
						<div class="nycfc-log-entry">[<?php echo esc_html( $entry['time'] ?? '' ); ?>] <?php echo esc_html( $entry['message'] ?? '' ); ?></div>
					<?php endforeach; ?>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Verify AJAX request permissions.
	 *
	 * @return bool
	 */
	private function verify_ajax_request(): bool {
		check_ajax_referer( 'nycfc_ajax', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'ny-court-forms-collector' ) ], 403 );
		}

		return true;
	}

	/**
	 * Build progress payload for JSON responses.
	 *
	 * @return array<string, mixed>
	 */
	private function build_progress_payload(): array {
		$progress     = CSV::get_progress();
		$total        = (int) ( $progress['total_rows'] ?? 0 );
		$processed    = (int) ( $progress['processed_rows'] ?? 0 );
		$remaining    = max( 0, $total - $processed );
		$export_file  = CSV::get_export_file();
		$has_export   = '' !== $export_file && file_exists( $export_file );

		return [
			'progress'       => $progress,
			'log'            => CSV::get_log_entries(),
			'rows_remaining' => $remaining,
			'has_export'     => $has_export,
			'download_url'   => Export::get_download_url(),
			'has_rows'       => ! empty( CSV::get_rows() ),
		];
	}

	/**
	 * AJAX: upload CSV.
	 */
	public function ajax_upload_csv(): void {
		$this->verify_ajax_request();

		if ( empty( $_FILES['csv_file'] ) ) {
			wp_send_json_error( [ 'message' => __( 'No CSV file uploaded.', 'ny-court-forms-collector' ) ] );
		}

		$result = CSV::handle_upload( $_FILES['csv_file'] );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( [ 'message' => $result->get_error_message() ] );
		}

		wp_send_json_success( array_merge( $result, $this->build_progress_payload() ) );
	}

	/**
	 * AJAX: start crawl.
	 */
	public function ajax_start_crawl(): void {
		$this->verify_ajax_request();

		$rows = CSV::get_rows();

		if ( empty( $rows ) ) {
			wp_send_json_error( [ 'message' => __( 'Upload a CSV before starting the crawl.', 'ny-court-forms-collector' ) ] );
		}

		$progress = CSV::get_progress();

		if ( 'completed' === ( $progress['crawl_status'] ?? '' ) && (int) $progress['processed_rows'] >= count( $rows ) ) {
			wp_send_json_error( [ 'message' => __( 'Crawl already completed. Reset to start again.', 'ny-court-forms-collector' ) ] );
		}

		if ( 0 === (int) ( $progress['started_at'] ?? 0 ) || 'idle' === ( $progress['crawl_status'] ?? 'idle' ) ) {
			CSV::update_progress(
				[
					'started_at'   => time(),
					'crawl_status' => 'running',
				]
			);
		} else {
			CSV::update_progress( [ 'crawl_status' => 'running' ] );
		}

		CSV::add_log_entry( __( 'Crawl started.', 'ny-court-forms-collector' ) );

		wp_send_json_success( $this->build_progress_payload() );
	}

	/**
	 * AJAX: process batch.
	 */
	public function ajax_process_batch(): void {
		$this->verify_ajax_request();

		$result = Crawler::process_batch();

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( [ 'message' => $result->get_error_message() ] );
		}

		wp_send_json_success(
			array_merge(
				$result,
				$this->build_progress_payload()
			)
		);
	}

	/**
	 * AJAX: pause crawl.
	 */
	public function ajax_pause_crawl(): void {
		$this->verify_ajax_request();

		CSV::update_progress( [ 'crawl_status' => 'paused' ] );
		CSV::add_log_entry( __( 'Crawl paused.', 'ny-court-forms-collector' ) );

		wp_send_json_success( $this->build_progress_payload() );
	}

	/**
	 * AJAX: resume crawl.
	 */
	public function ajax_resume_crawl(): void {
		$this->verify_ajax_request();

		CSV::update_progress( [ 'crawl_status' => 'running' ] );
		CSV::add_log_entry( __( 'Crawl resumed.', 'ny-court-forms-collector' ) );

		wp_send_json_success( $this->build_progress_payload() );
	}

	/**
	 * AJAX: reset crawl.
	 */
	public function ajax_reset_crawl(): void {
		$this->verify_ajax_request();

		CSV::reset_session_data();
		CSV::add_log_entry( __( 'Crawl reset.', 'ny-court-forms-collector' ) );

		wp_send_json_success( $this->build_progress_payload() );
	}

	/**
	 * AJAX: get progress.
	 */
	public function ajax_get_progress(): void {
		$this->verify_ajax_request();
		wp_send_json_success( $this->build_progress_payload() );
	}

	/**
	 * Handle export download.
	 */
	public function handle_download_export(): void {
		Export::download_file();
	}
}
