<?php
/**
 * Plugin uninstall handler.
 *
 * Conservatively removes plugin options only.
 * Form posts, taxonomy terms, and uploaded PDFs are preserved by default.
 *
 * @package ProSeCore
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Extension point: add destructive cleanup here if explicitly required in the future.
delete_option( 'prose_core_version' );
