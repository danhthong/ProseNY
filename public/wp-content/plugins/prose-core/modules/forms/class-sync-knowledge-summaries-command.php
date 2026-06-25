<?php
/**
 * WP-CLI: sync plain-language descriptions from crawled knowledge markdown.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Forms;

use ProSe\Core\Search\Knowledge_Article_Loader;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Sync_Knowledge_Summaries_Command
 *
 * Registers `wp prose forms sync-knowledge-summaries`.
 */
final class Sync_Knowledge_Summaries_Command {

	/**
	 * Populate empty form plain-language descriptions from knowledge corpus.
	 *
	 * ## OPTIONS
	 *
	 * [--dry-run]
	 * : Report updates without writing post meta.
	 *
	 * ## EXAMPLES
	 *
	 *     wp prose forms sync-knowledge-summaries
	 *     wp prose forms sync-knowledge-summaries --dry-run
	 *
	 * @param array<int, string>    $args       Positional args.
	 * @param array<string, mixed>  $assoc_args Named args.
	 * @return void
	 */
	public function __invoke( array $args, array $assoc_args ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
		if ( ! class_exists( '\WP_CLI' ) ) {
			return;
		}

		$dry_run = isset( $assoc_args['dry-run'] );
		$loader  = new Knowledge_Article_Loader();
		$updated = 0;
		$skipped = 0;

		$posts = get_posts(
			array(
				'post_type'      => Form_CPT::POST_TYPE,
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
			)
		);

		foreach ( $posts as $post_id ) {
			$post_id = (int) $post_id;

			$user_summary = (string) get_post_meta( $post_id, Form_Meta::META_USER_SUMMARY, true );
			$plain        = (string) get_post_meta( $post_id, Form_Meta::META_PLAIN_LANGUAGE_DESCRIPTION, true );

			if ( '' !== $user_summary || '' !== $plain ) {
				++$skipped;
				continue;
			}

			$form_code = (string) get_post_meta( $post_id, Form_Meta::META_FORM_CODE, true );

			if ( '' === $form_code ) {
				$form_code = (string) get_post_meta( $post_id, Form_Meta::META_FORM_ID, true );
			}

			if ( '' === $form_code ) {
				++$skipped;
				continue;
			}

			$article = $loader->find_by_form_code( $form_code );

			if ( null === $article ) {
				++$skipped;
				continue;
			}

			$excerpt = (string) ( $article['summary'] ?? '' );

			if ( '' === $excerpt ) {
				$excerpt = wp_trim_words( (string) ( $article['content'] ?? '' ), 80, '…' );
			}

			if ( '' === $excerpt ) {
				++$skipped;
				continue;
			}

			if ( $dry_run ) {
				\WP_CLI::log( sprintf( 'Would update %s (%d): %s', $form_code, $post_id, $excerpt ) );
			} else {
				update_post_meta( $post_id, Form_Meta::META_PLAIN_LANGUAGE_DESCRIPTION, sanitize_textarea_field( $excerpt ) );

				$official = (string) get_post_meta( $post_id, Form_Meta::META_OFFICIAL_URL, true );

				if ( '' === $official && ! empty( $article['source_url'] ) ) {
					update_post_meta( $post_id, Form_Meta::META_OFFICIAL_URL, esc_url_raw( (string) $article['source_url'] ) );
				}
			}

			++$updated;
		}

		if ( $dry_run ) {
			\WP_CLI::success( sprintf( 'Dry run: %d would update, %d skipped.', $updated, $skipped ) );
		} else {
			\WP_CLI::success( sprintf( 'Updated %d forms, skipped %d.', $updated, $skipped ) );
		}
	}
}
