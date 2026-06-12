<?php
/**
 * Manual harness for the Overlay Rendering foundation.
 *
 * Renders, for UD-1:
 *   - overlay-debug.pdf : field boundaries, labels and coordinates (calibration)
 *   - UD-1-overlay.pdf  : sample field values placed at calibrated coordinates
 *
 * Both are sized to the official court PDF page geometry. The official PDF is
 * never modified; the overlay layer is intended to be stamped onto it by a
 * downstream compositor. Also validates the layout and prints a report.
 *
 * Usage:
 *   php tests/manual/generate_overlay_debug.php
 *
 * @package ProSeCore
 */

use ProSe\Core\Forms\Documents\Overlay\Form_Layout_Registry;
use ProSe\Core\Forms\Documents\Overlay\Layout_Validation_Service;
use ProSe\Core\Forms\Documents\Overlay\Overlay_Renderer;
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

require_once PROSE_CORE_PATH . 'includes/class-autoloader.php';
\ProSe\Core\Autoloader::register();

$output_dir = PROSE_CORE_PATH . 'tests/manual/overlay-output';

if ( ! is_dir( $output_dir ) ) {
	mkdir( $output_dir, 0775, true );
}

$storage   = new Pdf_Storage_Service( $output_dir, 'https://example.test/prose-documents' );
$registry  = new Form_Layout_Registry();
$renderer  = new Overlay_Renderer( $registry, $storage );
$validator = new Layout_Validation_Service();

// Official template path (page geometry source; never modified).
$template_path = realpath( PROSE_CORE_PATH . '../../../wp-content/uploads/prose/forms/ud-1.pdf' );
$template_path = false === $template_path ? '' : $template_path;

$layout = $registry->load( 'UD-1' );
$result = $validator->validate( $layout, array( 'template_path' => $template_path ) );

$report  = "Overlay Rendering foundation - manual report\n";
$report .= 'generated: ' . gmdate( 'Y-m-d H:i:s' ) . " UTC\n";
$report .= 'output dir: ' . $storage->base_dir() . "\n";
$report .= 'official template: ' . ( '' !== $template_path ? $template_path : '(not found; using Letter default)' ) . "\n";
$report .= str_repeat( '=', 64 ) . "\n\n";

$report .= "LAYOUT VALIDATION (UD-1)\n" . str_repeat( '-', 64 ) . "\n";
$report .= '  valid:    ' . ( $result['valid'] ? 'yes' : 'NO' ) . "\n";
$report .= '  errors:   ' . ( empty( $result['errors'] ) ? '(none)' : "\n    - " . implode( "\n    - ", $result['errors'] ) ) . "\n";
$report .= '  warnings: ' . ( empty( $result['warnings'] ) ? '(none)' : "\n    - " . implode( "\n    - ", $result['warnings'] ) ) . "\n\n";

// --- Debug overlay. ------------------------------------------------------
$debug = $renderer->render_debug(
	'UD-1',
	array(
		'template_path' => $template_path,
		'filename'      => 'overlay-debug.pdf',
		'store'         => true,
		'grid'          => true,
	)
);

$report .= "DEBUG OVERLAY (overlay-debug.pdf)\n" . str_repeat( '-', 64 ) . "\n";
$report .= '  page size:   ' . $debug->page_size()['width'] . ' x ' . $debug->page_size()['height'] . " pt\n";
$report .= '  fields:      ' . $debug->field_count() . "\n";
$report .= '  pdf path:    ' . $debug->file_path() . "\n";
$report .= '  checksum:    ' . $debug->checksum() . "\n";
$report .= '  bytes:       ' . $debug->bytes() . "\n\n";

// --- Sample filled overlay. ---------------------------------------------
$values = array(
	'petitioner_name'  => 'Jane Doe',
	'respondent_name'  => 'John Doe',
	'county'           => 'New York',
	'grounds'          => 'Irretrievable breakdown of the marriage for a period of at least six months (DRL 170(7)).',
	'relief_requested' => 'Equitable distribution of marital property; resumption of former surname; such other relief as the Court deems just and proper.',
);

$overlay = $renderer->render(
	'UD-1',
	$values,
	array(
		'template_path' => $template_path,
		'filename'      => 'UD-1-overlay.pdf',
		'store'         => true,
	)
);

$report .= "SAMPLE OVERLAY (UD-1-overlay.pdf)\n" . str_repeat( '-', 64 ) . "\n";
$report .= '  mode:        ' . $overlay->mode() . "\n";
$report .= '  page size:   ' . $overlay->page_size()['width'] . ' x ' . $overlay->page_size()['height'] . " pt\n";
$report .= '  fields:      ' . $overlay->field_count() . "\n";
$report .= '  rendered:    ' . $overlay->rendered_count() . "\n";
$report .= '  skipped:     ' . $overlay->skipped_count() . "\n";
$report .= '  pdf path:    ' . $overlay->file_path() . "\n";
$report .= '  checksum:    ' . $overlay->checksum() . "\n";
$report .= '  bytes:       ' . $overlay->bytes() . "\n";
$report .= "\nNote: the official PDF is preserved (never modified). This overlay layer\n";
$report .= "is sized to the official page geometry and is intended to be stamped onto\n";
$report .= "the original PDF by a downstream compositor (FPDI/pdftk/Ghostscript).\n";

$report_path = $output_dir . '/overlay-report.txt';
file_put_contents( $report_path, $report ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_put_contents_file_put_contents

echo $report;
echo "\nReport written to: {$report_path}\n";
