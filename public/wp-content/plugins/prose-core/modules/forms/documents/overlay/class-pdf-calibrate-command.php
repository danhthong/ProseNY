<?php
/**
 * WP-CLI command: overlay layout calibration.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Forms\Documents\Overlay;

use ProSe\Core\Forms\Documents\Pdf\Pdf_Storage_Service;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Pdf_Calibrate_Command
 *
 * Registers `wp prose pdf calibrate <form>` (the `pdf:calibrate` command). It
 * prints the current field coordinates and page size for a form's overlay
 * layout and writes a calibration grid overlay (e.g. UD-1-grid.pdf) over the
 * official PDF so positions can be calibrated by eye in minutes.
 *
 * Calibration tooling only: it does not modify the renderer, the layout JSON,
 * or document generation.
 */
final class Pdf_Calibrate_Command {

	/**
	 * Generate a calibration grid overlay for a form.
	 *
	 * ## OPTIONS
	 *
	 * [<form>]
	 * : The form code to calibrate. Default: UD-1.
	 *
	 * [--output-dir=<dir>]
	 * : Directory to write the grid overlay into. Defaults to the plugin
	 *   tests/manual/overlay-output directory.
	 *
	 * [--dpi=<dpi>]
	 * : Background raster resolution. Default: 150.
	 *
	 * [--no-background]
	 * : Draw the grid without compositing the official PDF behind it.
	 *
	 * ## EXAMPLES
	 *
	 *     wp prose pdf calibrate UD-1
	 *     wp prose pdf calibrate UD-1 --dpi=200 --output-dir=/tmp/calib
	 *
	 * @param array<int, string>    $args       Positional args.
	 * @param array<string, string> $assoc_args Flags.
	 * @return void
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		$form_code  = '' !== ( $args[0] ?? '' ) ? (string) $args[0] : 'UD-1';
		$output_dir = isset( $assoc_args['output-dir'] )
			? rtrim( (string) $assoc_args['output-dir'], '/\\' )
			: $this->default_output_dir();
		$dpi        = (int) ( $assoc_args['dpi'] ?? 150 );
		$background = ! isset( $assoc_args['no-background'] );

		if ( ! is_dir( $output_dir ) ) {
			wp_mkdir_p( $output_dir );
		}

		$registry = new Form_Layout_Registry();

		if ( ! $registry->has( $form_code ) ) {
			$this->fail( 'No overlay layout registered for ' . $form_code );
			return;
		}

		$storage  = new Pdf_Storage_Service( $output_dir, $this->base_url() );
		$debugger = new Pdf_Layout_Debugger( $registry, $storage, new Pdf_Rasterizer( $dpi ) );

		$report = $debugger->generate(
			$form_code,
			array(
				'template_path' => $this->template_path( $form_code ),
				'filename'      => $form_code . '-grid.pdf',
				'store'         => true,
				'background'    => $background,
				'dpi'           => $dpi,
			)
		);

		$this->report( $report );
	}

	/**
	 * Print the calibration report (current coordinates, page size, overlay).
	 *
	 * @param array<string, mixed> $report Report.
	 * @return void
	 */
	private function report( array $report ): void {
		if ( ! class_exists( '\WP_CLI' ) ) {
			return;
		}

		$size = (array) $report['page_size'];

		\WP_CLI::log( 'Form:      ' . (string) $report['form_code'] );
		\WP_CLI::log( 'Template:  ' . ( '' !== $report['template'] ? (string) $report['template'] : '(none)' ) );
		\WP_CLI::log( sprintf( 'Page size: %s x %s pt', $size['width'], $size['height'] ) );
		\WP_CLI::log( 'Grid step: ' . (int) $report['grid_step'] . ' pt' );
		\WP_CLI::log( 'Composite: ' . ( $report['composited'] ? 'official PDF background' : 'grid only' ) );
		\WP_CLI::log( '' );
		\WP_CLI::log( 'Current coordinates:' );

		$rows = array();

		foreach ( (array) $report['fields'] as $field ) {
			$rows[] = array(
				'field'     => (string) $field['key'],
				'source'    => (string) $field['source'],
				'page'      => (string) $field['page'],
				'x'         => (string) $field['x'],
				'y'         => (string) $field['y'],
				'font'      => (string) $field['font_size'],
				'multiline' => $field['multiline'] ? 'yes' : '-',
			);
		}

		\WP_CLI\Utils\format_items( 'table', $rows, array( 'field', 'source', 'page', 'x', 'y', 'font', 'multiline' ) );

		foreach ( (array) $report['warnings'] as $warning ) {
			\WP_CLI::warning( (string) $warning );
		}

		\WP_CLI::success( 'Grid overlay written to ' . (string) $report['file_path'] );
	}

	/**
	 * Resolve the official PDF path for a form (uploads/prose/forms/<code>.pdf).
	 *
	 * @param string $form_code Form code.
	 * @return string
	 */
	private function template_path( string $form_code ): string {
		if ( ! function_exists( 'wp_upload_dir' ) ) {
			return '';
		}

		$uploads = wp_upload_dir();
		$base    = isset( $uploads['basedir'] ) ? rtrim( (string) $uploads['basedir'], '/\\' ) : '';

		if ( '' === $base ) {
			return '';
		}

		$path = $base . '/prose/forms/' . strtolower( $form_code ) . '.pdf';

		return is_readable( $path ) ? $path : '';
	}

	/**
	 * Base URL for stored overlays.
	 *
	 * @return string
	 */
	private function base_url(): string {
		if ( function_exists( 'wp_upload_dir' ) ) {
			$uploads = wp_upload_dir();

			if ( isset( $uploads['baseurl'] ) ) {
				return rtrim( (string) $uploads['baseurl'], '/' ) . '/prose/calibration';
			}
		}

		return 'https://example.test/prose-calibration';
	}

	/**
	 * Default output directory.
	 *
	 * @return string
	 */
	private function default_output_dir(): string {
		if ( defined( 'PROSE_CORE_PATH' ) ) {
			return rtrim( PROSE_CORE_PATH, '/\\' ) . '/tests/manual/overlay-output';
		}

		return rtrim( sys_get_temp_dir(), '/\\' ) . '/overlay-output';
	}

	/**
	 * Emit an error (WP-CLI when available).
	 *
	 * @param string $message Message.
	 * @return void
	 */
	private function fail( string $message ): void {
		if ( class_exists( '\WP_CLI' ) ) {
			\WP_CLI::error( $message );
		}
	}
}
