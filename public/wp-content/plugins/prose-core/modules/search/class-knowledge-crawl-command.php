<?php
/**
 * WP-CLI: document how to refresh the NY Courts knowledge corpus.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Search;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Knowledge_Crawl_Command
 *
 * Registers `wp prose knowledge crawl`.
 */
final class Knowledge_Crawl_Command {

	/**
	 * Run or document the Python knowledge crawler.
	 *
	 * ## OPTIONS
	 *
	 * [--forms-only]
	 * : Crawl form pages only.
	 *
	 * [--topics-only]
	 * : Crawl CourtHelp topics only.
	 *
	 * [--limit=<n>]
	 * : Limit targets per phase.
	 *
	 * [--run]
	 * : Execute the Python crawler (requires collect_forms venv).
	 *
	 * ## EXAMPLES
	 *
	 *     wp prose knowledge crawl
	 *     wp prose knowledge crawl --run --limit=10
	 *
	 * @param array<int, string>    $args       Positional args.
	 * @param array<string, mixed>  $assoc_args Named args.
	 * @return void
	 */
	public function __invoke( array $args, array $assoc_args ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
		if ( ! class_exists( '\WP_CLI' ) ) {
			return;
		}

		$script = trailingslashit( dirname( PROSE_CORE_PATH, 3 ) ) . 'collect_forms/crawl_knowledge.py';

		if ( ! file_exists( $script ) ) {
			\WP_CLI::error( 'Crawler script not found: ' . $script );
		}

		if ( empty( $assoc_args['run'] ) ) {
			\WP_CLI::log( 'Knowledge corpus path: ' . ( new Knowledge_Article_Loader() )->court_knowledge_dir() );
			\WP_CLI::log( 'Run the crawler with:' );
			\WP_CLI::log( '  cd collect_forms && .venv/Scripts/python.exe crawl_knowledge.py' );
			\WP_CLI::log( 'Or pass --run to execute from WP-CLI (Windows venv path required).' );
			return;
		}

		$python = trailingslashit( dirname( PROSE_CORE_PATH, 3 ) ) . 'collect_forms/.venv/Scripts/python.exe';

		if ( ! file_exists( $python ) ) {
			$python = 'python';
		}

		$cmd = array( $python, $script );

		if ( ! empty( $assoc_args['forms-only'] ) ) {
			$cmd[] = '--forms-only';
		}

		if ( ! empty( $assoc_args['topics-only'] ) ) {
			$cmd[] = '--topics-only';
		}

		if ( ! empty( $assoc_args['limit'] ) ) {
			$cmd[] = '--limit';
			$cmd[] = (string) (int) $assoc_args['limit'];
		}

		$escaped = array_map( 'escapeshellarg', $cmd );
		$command = implode( ' ', $escaped );

		\WP_CLI::log( 'Running: ' . $command );
		passthru( $command, $exit_code ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_passthru

		if ( 0 !== (int) $exit_code ) {
			\WP_CLI::error( 'Knowledge crawl failed with exit code ' . (int) $exit_code );
		}

		\WP_CLI::success( 'Knowledge crawl completed.' );
	}
}
