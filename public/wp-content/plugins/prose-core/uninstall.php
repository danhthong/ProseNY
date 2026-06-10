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

/**
 * Optional destructive cleanup when PROSE_CORE_UNINSTALL_DELETE_DATA is defined.
 */
if ( defined( 'PROSE_CORE_UNINSTALL_DELETE_DATA' ) && PROSE_CORE_UNINSTALL_DELETE_DATA ) {
	$post_types = array( 'prose_form', 'prose_package', 'prose_county_rule' );

	foreach ( $post_types as $post_type ) {
		$posts = get_posts(
			array(
				'post_type'      => $post_type,
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
			)
		);

		foreach ( $posts as $post_id ) {
			wp_delete_post( (int) $post_id, true );
		}
	}
}
