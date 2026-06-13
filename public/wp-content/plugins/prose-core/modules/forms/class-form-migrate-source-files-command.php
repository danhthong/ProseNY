<?php
/**
 * WP-CLI command: migrate flat form files into per-form original directories.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Forms;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Form_Migrate_Source_Files_Command
 *
 * Registers `wp prose forms migrate-source-files`.
 */
final class Form_Migrate_Source_Files_Command {

	/**
	 * Copy legacy flat files into per-form original directories.
	 *
	 * ## OPTIONS
	 *
	 * [--dry-run]
	 * : Report actions without copying files or updating metadata. Default true.
	 *
	 * [--execute]
	 * : Perform the migration (overrides --dry-run).
	 *
	 * ## EXAMPLES
	 *
	 *     wp prose forms migrate-source-files
	 *     wp prose forms migrate-source-files --execute
	 *
	 * @param array<int, string>    $args       Positional args (unused).
	 * @param array<string, string> $assoc_args Flags.
	 * @return void
	 */
	public function migrate_source_files( array $args, array $assoc_args ): void {
		unset( $args );

		if ( ! class_exists( '\WP_CLI' ) ) {
			return;
		}

		$execute = isset( $assoc_args['execute'] );
		$dry_run = ! $execute;

		$file_manager = new Form_File_Manager();
		$upload_dir   = $file_manager->get_upload_dir();

		if ( is_wp_error( $upload_dir ) ) {
			\WP_CLI::error( $upload_dir->get_error_message() );
		}

		$query = new \WP_Query(
			array(
				'post_type'      => Form_CPT::POST_TYPE,
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'meta_query'     => array(
					array(
						'key'     => Form_Meta::META_FILE_NAME,
						'value'   => '',
						'compare' => '!=',
					),
				),
			)
		);

		$migrated = 0;
		$skipped  = 0;
		$failed   = 0;

		foreach ( $query->posts as $post_id ) {
			$post_id   = (int) $post_id;
			$form_code = (string) get_post_meta( $post_id, Form_Meta::META_FORM_CODE, true );

			if ( '' === $form_code ) {
				$form_code = (string) get_post_meta( $post_id, Form_Meta::META_FORM_ID, true );
			}

			$form_slug = sanitize_title( $form_code );

			if ( '' === $form_slug ) {
				++$skipped;
				continue;
			}

			$file_name = sanitize_file_name( (string) get_post_meta( $post_id, Form_Meta::META_FILE_NAME, true ) );

			if ( '' === $file_name ) {
				++$skipped;
				continue;
			}

			$flat_path = $upload_dir['path'] . $file_name;

			if ( ! is_readable( $flat_path ) ) {
				++$skipped;
				continue;
			}

			$source_dir = $file_manager->get_form_source_dir( $form_slug );

			if ( is_wp_error( $source_dir ) ) {
				++$failed;
				\WP_CLI::warning( sprintf( 'Post %d: %s', $post_id, $source_dir->get_error_message() ) );
				continue;
			}

			$dest_path = $source_dir['path'] . $file_name;
			$dest_url  = $source_dir['url'] . $file_name;

			if ( is_readable( $dest_path ) ) {
				++$skipped;
				continue;
			}

			if ( $dry_run ) {
				\WP_CLI::log( sprintf( '[dry-run] Would copy %s -> %s', $flat_path, $dest_path ) );
				++$migrated;
				continue;
			}

			if ( ! copy( $flat_path, $dest_path ) ) {
				++$failed;
				\WP_CLI::warning( sprintf( 'Post %d: failed to copy %s', $post_id, $file_name ) );
				continue;
			}

			$source_url = (string) get_post_meta( $post_id, Form_Meta::META_SOURCE_PDF_URL, true );
			$file_url   = (string) get_post_meta( $post_id, Form_Meta::META_FILE_URL, true );
			$extension  = strtolower( pathinfo( $file_name, PATHINFO_EXTENSION ) );

			$entry = array(
				'filename'        => $file_name,
				'extension'       => $extension,
				'source_url'      => '' !== $source_url ? esc_url_raw( $source_url ) : esc_url_raw( $file_url ),
				'local_path'      => $dest_path,
				'local_url'       => $dest_url,
				'download_status' => 'success',
			);

			update_post_meta(
				$post_id,
				Form_Meta::META_SOURCE_FILES,
				Form_Meta::sanitize_json(
					array(
						'files' => array( $entry ),
					)
				)
			);

			++$migrated;
		}

		if ( $dry_run ) {
			\WP_CLI::success(
				sprintf(
					'Dry run complete: %d would migrate, %d skipped, %d failed. Re-run with --execute to apply.',
					$migrated,
					$skipped,
					$failed
				)
			);
			return;
		}

		\WP_CLI::success(
			sprintf(
				'Migration complete: %d migrated, %d skipped, %d failed.',
				$migrated,
				$skipped,
				$failed
			)
		);
	}
}
