<?php
/**
 * Plugin Name: NY Court Forms Collector
 * Description: Collect and enrich NY court forms from a listing URL and export forms_enriched.csv.
 * Version: 1.0.0
 * Author: Proseny
 * Text Domain: ny-court-forms-collector
 * Domain Path: /languages
 * Requires PHP: 8.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'NYCFC_VERSION', '1.0.0' );
define( 'NYCFC_PLUGIN_FILE', __FILE__ );
define( 'NYCFC_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'NYCFC_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'NYCFC_BATCH_SIZE', 5 );

require_once NYCFC_PLUGIN_DIR . 'includes/class-http.php';
require_once NYCFC_PLUGIN_DIR . 'includes/class-csv.php';
require_once NYCFC_PLUGIN_DIR . 'includes/class-crawler.php';
require_once NYCFC_PLUGIN_DIR . 'includes/class-export.php';
require_once NYCFC_PLUGIN_DIR . 'includes/class-admin.php';

use NYCourtFormsCollector\Includes\Admin;

/**
 * Main plugin bootstrap.
 */
final class NYCFC_Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var NYCFC_Plugin|null
	 */
	private static ?NYCFC_Plugin $instance = null;

	/**
	 * Get singleton instance.
	 *
	 * @return NYCFC_Plugin
	 */
	public static function instance(): NYCFC_Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->init_hooks();
	}

	/**
	 * Register hooks.
	 */
	private function init_hooks(): void {
		add_action( 'plugins_loaded', [ $this, 'load_textdomain' ] );
		Admin::instance();
	}

	/**
	 * Load plugin text domain.
	 */
	public function load_textdomain(): void {
		load_plugin_textdomain(
			'ny-court-forms-collector',
			false,
			dirname( plugin_basename( NYCFC_PLUGIN_FILE ) ) . '/languages'
		);
	}
}

/**
 * Initialize plugin.
 */
function nycfc_init(): void {
	NYCFC_Plugin::instance();
}

nycfc_init();
