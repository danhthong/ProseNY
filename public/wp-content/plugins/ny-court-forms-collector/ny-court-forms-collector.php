<?php
/**
 * Plugin Name: NY Court Forms Collector
 * Description: Collect and process NY court forms from CSV uploads with web crawling
 * Version: 1.0.0
 * Author: Proseny
 * Text Domain: ny-court-forms-collector
 * Domain Path: /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'NYCFC_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'NYCFC_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once NYCFC_PLUGIN_DIR . 'includes/class-csv.php';
require_once NYCFC_PLUGIN_DIR . 'includes/class-crawler.php';
require_once NYCFC_PLUGIN_DIR . 'includes/class-export.php';
require_once NYCFC_PLUGIN_DIR . 'includes/class-admin.php';

function nycfc_load_plugin() {
	$plugin = new NYCFC\Admin();
}

add_action( 'plugins_loaded', 'nycfc_load_plugin' );
