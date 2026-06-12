<?php
/**
 * UD-1 PDF Rendering Sprint — Overlay Renderer.
 *
 * Composites the official (flat) ud-1.pdf as a layout-preserving background and
 * overlays the resolved field values at the coordinates defined in UD-1.json,
 * producing a filing-ready PDF. The official PDF is never modified.
 *
 * Generates:
 *   tests/manual/overlay-output/UD-1-filled.pdf
 *   tests/manual/overlay-output/UD-1-debug.pdf
 *   tests/manual/overlay-output/overlay-render-report.json
 *
 * Usage:
 *   php tests/manual/generate_ud1_filled.php
 *
 * @package ProSeCore
 */

use ProSe\Core\Forms\Documents\Overlay\Form_Layout_Registry;
use ProSe\Core\Forms\Documents\Overlay\Overlay_Renderer;
use ProSe\Core\Forms\Documents\Overlay\Pdf_Rasterizer;
use ProSe\Core\Forms\Documents\Pdf\Pdf_Storage_Service;

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', '/tmp/wordpress/' );
}

if ( ! defined( 'PROSE_CORE_PATH' ) ) {
	define( 'PROSE_CORE_PATH', dirname( __DIR__, 2 ) . '/' );
}

if ( ! function_exists( '__' ) ) {
	function __( $text, $domain = 'default' ) {
		unset( $domain );
		return (string) $text;
	}
}

if ( ! function_exists( 'trailingslashit' ) ) {
	function trailingslashit( $string ) {
		return rtrim( (string) $string, '/\\' ) . '/';
	}
}

if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $data, $options = 0, $depth = 512 ) {
		return json_encode( $data, $options, $depth );
	}
}

require_once PROSE_CORE_PATH . 'includes/class-autoloader.php';
\ProSe\Core\Autoloader::register();

$output_dir = PROSE_CORE_PATH . 'tests/manual/overlay-output';

if ( ! is_dir( $output_dir ) ) {
	mkdir( $output_dir, 0775, true );
}

// Official court template (page geometry + raster background; never modified).
$template_path = realpath( PROSE_CORE_PATH . '../../../wp-content/uploads/prose/forms/ud-1.pdf' );
$template_path = false === $template_path ? '' : $template_path;

// Dummy case data for UD-1.
$values = array(
	'petitioner_name'  => 'Jane Doe',
	'respondent_name'  => 'John Doe',
	'county'           => 'New York County',
	'grounds'          => 'DRL §170(7) - irretrievable breakdown in relationship',
	'relief_requested' => 'Restoration of maiden name and equitable distribution pursuant to agreement',
);

$storage    = new Pdf_Storage_Service( $output_dir, 'https://example.test/prose-documents' );
$registry   = new Form_Layout_Registry();
$rasterizer = new Pdf_Rasterizer( 200 );
$renderer   = new Overlay_Renderer( $registry, $storage, $rasterizer );

echo "UD-1 Overlay Rendering Sprint\n";
echo str_repeat( '=', 56 ) . "\n";
echo 'template:   ' . ( '' !== $template_path ? $template_path : '(missing)' ) . "\n";
echo 'rasterizer: ' . ( $rasterizer->available() ? 'pdftoppm available' : 'UNAVAILABLE (overlay-only fallback)' ) . "\n\n";

// --- Filled PDF. ---------------------------------------------------------
$filled = $renderer->render_filled(
	'UD-1',
	$values,
	array(
		'template_path' => $template_path,
		'filename'      => 'UD-1-filled.pdf',
		'store'         => true,
		'dpi'           => 200,
	)
);

echo "UD-1-filled.pdf\n";
echo '  mode:            ' . $filled->mode() . "\n";
echo '  page size:       ' . $filled->page_size()['width'] . ' x ' . $filled->page_size()['height'] . " pt\n";
echo '  fields rendered: ' . $filled->rendered_count() . '/' . $filled->field_count() . "\n";
echo '  fields failed:   ' . $filled->skipped_count() . "\n";
echo '  duration:        ' . $filled->render_duration_ms() . " ms\n";
echo '  path:            ' . $filled->file_path() . "\n";
echo '  bytes:           ' . $filled->bytes() . "\n";

if ( ! empty( $filled->warnings() ) ) {
	echo '  warnings:        ' . implode( '; ', $filled->warnings() ) . "\n";
}

// --- Debug overlay (labels, values, coordinate markers, bounding boxes). --
$debug = $renderer->render_debug(
	'UD-1',
	array(
		'template_path' => $template_path,
		'filename'      => 'UD-1-debug.pdf',
		'store'         => true,
		'background'    => true,
		'values'        => $values,
	)
);

echo "\nUD-1-debug.pdf\n";
echo '  mode:            ' . $debug->mode() . "\n";
echo '  fields:          ' . $debug->field_count() . "\n";
echo '  path:            ' . $debug->file_path() . "\n";
echo '  bytes:           ' . $debug->bytes() . "\n";

// --- Render report. ------------------------------------------------------
$report = array(
	'form_code'          => $filled->form_code(),
	'template'           => $filled->template(),
	'fields_rendered'    => $filled->rendered_count(),
	'fields_failed'      => $filled->skipped_count(),
	'render_duration_ms' => $filled->render_duration_ms(),
	'mode'               => $filled->mode(),
	'page_size'          => $filled->page_size(),
	'filled_pdf'         => $filled->file_path(),
	'debug_pdf'          => $debug->file_path(),
	'checksum'           => $filled->checksum(),
	'warnings'           => $filled->warnings(),
);

$report_path = $output_dir . '/overlay-render-report.json';
file_put_contents( $report_path, wp_json_encode( $report, JSON_PRETTY_PRINT ) . "\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_put_contents_file_put_contents

echo "\noverlay-render-report.json\n";
echo wp_json_encode( $report, JSON_PRETTY_PRINT ) . "\n";
echo "\nReport written to: {$report_path}\n";
