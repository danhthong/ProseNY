<?php
/**
 * Admin page and AJAX handlers.
 *
 * @package NYCourtFormsCollector
 */

namespace NYCourtFormsCollector\Includes;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Admin
 */
class Admin {

	/**
	 * Default NY Courts divorce forms listing URL.
	 */
	public const DEFAULT_LISTING_URL = 'https://www.nycourts.gov/forms?search_api_forms_fulltext=&field_case_type%5B4121%5D=4121&field_case_type%5B4291%5D=4291&field_case_type%5B241%5D=241&field_case_type%5B1476%5D=1476&field_case_type%5B4326%5D=4326&field_case_type%5B1441%5D=1441&field_case_type%5B4281%5D=4281&field_case_type%5B1436%5D=1436&field_case_type%5B4306%5D=4306&field_case_type%5B1431%5D=1431&field_case_type%5B4191%5D=4191&field_case_type%5B4311%5D=4311&field_case_type%5B4256%5D=4256&field_case_type%5B4261%5D=4261&node_field_court_type%5B161%5D=161&node_field_court_type%5B2221%5D=2221';

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
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );

		add_action( 'wp_ajax_nycfc_start_collection', array( $this, 'ajax_start_collection' ) );
		add_action( 'wp_ajax_nycfc_process_batch', array( $this, 'ajax_process_batch' ) );
		add_action( 'wp_ajax_nycfc_pause_crawl', array( $this, 'ajax_pause_crawl' ) );
		add_action( 'wp_ajax_nycfc_resume_crawl', array( $this, 'ajax_resume_crawl' ) );
		add_action( 'wp_ajax_nycfc_reset_crawl', array( $this, 'ajax_reset_crawl' ) );
		add_action( 'wp_ajax_nycfc_get_progress', array( $this, 'ajax_get_progress' ) );

		add_action( 'admin_post_nycfc_download_export', array( $this, 'handle_download_export' ) );
	}

	/**
	 * Register admin menu page.
	 *
	 * @return void
	 */
	public function register_menu(): void {
		add_submenu_page(
			'tools.php',
			__( 'NY Court Forms Collector', 'ny-court-forms-collector' ),
			__( 'NY Court Forms Collector', 'ny-court-forms-collector' ),
			'manage_options',
			$this->slug,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Enqueue admin assets.
	 *
	 * @param string $hook Current admin page hook.
	 * @return void
	 */
	public function enqueue_assets( string $hook ): void {
		if ( 'tools_page_' . $this->slug !== $hook ) {
			return;
		}

		wp_enqueue_style(
			'nycfc-admin',
			NYCFC_PLUGIN_URL . 'assets/admin.css',
			array(),
			NYCFC_VERSION
		);

		wp_enqueue_script(
			'nycfc-admin',
			NYCFC_PLUGIN_URL . 'assets/admin.js',
			array( 'jquery' ),
			NYCFC_VERSION,
			true
		);

		wp_localize_script(
			'nycfc-admin',
			'nycfcAdmin',
			array(
				'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
				'nonce'     => wp_create_nonce( 'nycfc_ajax' ),
				'batchSize' => defined( 'NYCFC_BATCH_SIZE' ) ? (int) NYCFC_BATCH_SIZE : 5,
				'pollMs'    => 2000,
				'strings'   => array(
					'startSuccess'  => __( 'Collection started.', 'ny-court-forms-collector' ),
					'startError'    => __( 'Could not start collection.', 'ny-court-forms-collector' ),
					'crawlPaused'   => __( 'Crawl paused.', 'ny-court-forms-collector' ),
					'crawlResumed'  => __( 'Crawl resumed.', 'ny-court-forms-collector' ),
					'crawlReset'    => __( 'Crawl reset.', 'ny-court-forms-collector' ),
					'completed'     => __( 'Crawl completed.', 'ny-court-forms-collector' ),
					'invalidUrl'    => __( 'Please enter a valid listing URL.', 'ny-court-forms-collector' ),
				),
			)
		);
	}

	/**
	 * Render admin page.
	 *
	 * @return void
	 */
	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$progress     = CSV::get_progress();
		$log_entries  = CSV::get_log_entries();
		$download_url = Export::get_download_url();
		$has_export   = '' !== CSV::get_export_file() && file_exists( CSV::get_export_file() );
		$has_rows     = ! empty( CSV::get_rows() );
		$status       = $progress['crawl_status'] ?? 'idle';
		$phase        = $progress['phase'] ?? 'collect';
		?>
		<div class="wrap nycfc-wrap">
			<h1><?php esc_html_e( 'NY Court Forms Collector', 'ny-court-forms-collector' ); ?></h1>
			<p class="description">
				<?php esc_html_e( 'Enter a NY Courts forms listing URL. The collector will gather form links across pagination, enrich each form page, resolve PDF redirects, and export forms_enriched.csv.', 'ny-court-forms-collector' ); ?>
			</p>

			<div class="nycfc-panel">
				<h2><?php esc_html_e( 'Listing URL', 'ny-court-forms-collector' ); ?></h2>
				<form id="nycfc-start-form">
					<p>
						<label for="nycfc-listing-url"><?php esc_html_e( 'Forms Listing URL', 'ny-court-forms-collector' ); ?></label><br>
						<input
							type="url"
							id="nycfc-listing-url"
							name="listing_url"
							class="large-text code"
							value="<?php echo esc_attr( (string) ( $progress['listing_url'] ?? self::DEFAULT_LISTING_URL ) ); ?>"
							required
						>
					</p>
					<p>
						<button type="submit" class="button button-primary" id="nycfc-start-btn" <?php disabled( 'running' === $status ); ?>>
							<?php esc_html_e( 'Start Collection', 'ny-court-forms-collector' ); ?>
						</button>
					</p>
				</form>
				<div id="nycfc-start-message" class="nycfc-message" aria-live="polite"></div>
			</div>

			<div class="nycfc-panel">
				<h2><?php esc_html_e( 'Crawl Controls', 'ny-court-forms-collector' ); ?></h2>
				<div class="nycfc-controls">
					<button type="button" class="button" id="nycfc-pause-btn" <?php disabled( 'running' !== $status ); ?>>
						<?php esc_html_e( 'Pause', 'ny-court-forms-collector' ); ?>
					</button>
					<button type="button" class="button" id="nycfc-resume-btn" <?php disabled( 'paused' !== $status ); ?>>
						<?php esc_html_e( 'Resume', 'ny-court-forms-collector' ); ?>
					</button>
					<button type="button" class="button button-secondary" id="nycfc-reset-btn">
						<?php esc_html_e( 'Reset', 'ny-court-forms-collector' ); ?>
					</button>
					<a href="<?php echo esc_url( $download_url ); ?>" class="button button-secondary" id="nycfc-download-btn" <?php echo $has_export ? '' : 'style="display:none;"'; ?>>
						<?php esc_html_e( 'Download forms_enriched.csv', 'ny-court-forms-collector' ); ?>
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
					<div><strong><?php esc_html_e( 'Phase:', 'ny-court-forms-collector' ); ?></strong> <span id="nycfc-phase"><?php echo esc_html( ucfirst( $phase ) ); ?></span></div>
					<div><strong><?php esc_html_e( 'Pages Crawled:', 'ny-court-forms-collector' ); ?></strong> <span id="nycfc-pages-crawled"><?php echo esc_html( (string) ( $progress['pages_crawled'] ?? 0 ) ); ?></span></div>
					<div><strong><?php esc_html_e( 'Current Row:', 'ny-court-forms-collector' ); ?></strong> <span id="nycfc-current-row"><?php echo esc_html( (string) ( $progress['current_row'] ?? 0 ) ); ?></span></div>
					<div><strong><?php esc_html_e( 'Current URL:', 'ny-court-forms-collector' ); ?></strong> <span id="nycfc-current-url"><?php echo esc_html( $progress['current_url'] ?? __( 'None', 'ny-court-forms-collector' ) ); ?></span></div>
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
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'ny-court-forms-collector' ) ), 403 );
		}

		return true;
	}

	/**
	 * Build progress payload for JSON responses.
	 *
	 * @return array<string, mixed>
	 */
	private function build_progress_payload(): array {
		$progress    = CSV::get_progress();
		$total       = (int) ( $progress['total_rows'] ?? 0 );
		$processed   = (int) ( $progress['processed_rows'] ?? 0 );
		$remaining   = max( 0, $total - $processed );
		$export_file = CSV::get_export_file();
		$has_export  = '' !== $export_file && file_exists( $export_file );

		return array(
			'progress'       => $progress,
			'log'            => CSV::get_log_entries(),
			'rows_remaining' => $remaining,
			'has_export'     => $has_export,
			'download_url'   => Export::get_download_url(),
			'has_rows'       => ! empty( CSV::get_rows() ),
		);
	}

	/**
	 * AJAX: start collection from listing URL.
	 *
	 * @return void
	 */
	public function ajax_start_collection(): void {
		$this->verify_ajax_request();

		$listing_url = isset( $_POST['listing_url'] ) ? esc_url_raw( wp_unslash( $_POST['listing_url'] ) ) : '';

		$result = CSV::start_session( $listing_url );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success(
			array_merge(
				$result,
				$this->build_progress_payload()
			)
		);
	}

	/**
	 * AJAX: process batch.
	 *
	 * @return void
	 */
	public function ajax_process_batch(): void {
		$this->verify_ajax_request();

		$result = Crawler::process_batch();

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
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
	 *
	 * @return void
	 */
	public function ajax_pause_crawl(): void {
		$this->verify_ajax_request();

		CSV::update_progress( array( 'crawl_status' => 'paused' ) );
		CSV::add_log_entry( __( 'Crawl paused.', 'ny-court-forms-collector' ) );

		wp_send_json_success( $this->build_progress_payload() );
	}

	/**
	 * AJAX: resume crawl.
	 *
	 * @return void
	 */
	public function ajax_resume_crawl(): void {
		$this->verify_ajax_request();

		CSV::update_progress( array( 'crawl_status' => 'running' ) );
		CSV::add_log_entry( __( 'Crawl resumed.', 'ny-court-forms-collector' ) );

		wp_send_json_success( $this->build_progress_payload() );
	}

	/**
	 * AJAX: reset crawl.
	 *
	 * @return void
	 */
	public function ajax_reset_crawl(): void {
		$this->verify_ajax_request();

		CSV::reset_session_data();
		CSV::add_log_entry( __( 'Crawl reset.', 'ny-court-forms-collector' ) );

		wp_send_json_success( $this->build_progress_payload() );
	}

	/**
	 * AJAX: get progress.
	 *
	 * @return void
	 */
	public function ajax_get_progress(): void {
		$this->verify_ajax_request();
		wp_send_json_success( $this->build_progress_payload() );
	}

	/**
	 * Handle export download.
	 *
	 * @return void
	 */
	public function handle_download_export(): void {
		Export::download_file();
	}
}
