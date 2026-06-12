<?php
/**
 * Manual harness for the Layout Calibration Tool (Pdf_Layout_Debugger).
 *
 * Generates a calibration grid overlay (UD-1-grid.pdf) composited over the
 * official ud-1.pdf: 25 pt grid, X/Y rulers, field markers, labels, bounding
 * boxes and page dimensions. Also prints the current coordinates and page size
 * (mirroring `wp prose pdf calibrate UD-1`).
 *
 * Usage:
 *   php tests/manual/generate_layout_grid.php [FORM_CODE]
 *
 * @package ProSeCore
 */

use ProSe\Core\Forms\Documents\Overlay\Form_Layout_Registry;
use ProSe\Core\Forms\Documents\Overlay\Pdf_Layout_Debugger;
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

require_once PROSE_CORE_PATH . 'includes/class-autoloader.php';
\ProSe\Core\Autoloader::register();

$form_code  = isset( $argv[1] ) && '' !== $argv[1] ? (string) $argv[1] : 'UD-1';
$output_dir = PROSE_CORE_PATH . 'tests/manual/overlay-output';

if ( ! is_dir( $output_dir ) ) {
	mkdir( $output_dir, 0775, true );
}

$template_path = realpath( PROSE_CORE_PATH . '../../../wp-content/uploads/prose/forms/' . strtolower( $form_code ) . '.pdf' );
$template_path = false === $template_path ? '' : $template_path;

$storage    = new Pdf_Storage_Service( $output_dir, 'https://example.test/prose-calibration' );
$registry   = new Form_Layout_Registry();
$rasterizer = new Pdf_Rasterizer( 150 );
$debugger   = new Pdf_Layout_Debugger( $registry, $storage, $rasterizer );

$report = $debugger->generate(
	$form_code,
	array(
		'template_path' => $template_path,
		'filename'      => $form_code . '-grid.pdf',
		'store'         => true,
		'background'    => true,
		'dpi'           => 150,
	)
);

$size = $report['page_size'];

echo "Layout Calibration Tool\n";
echo str_repeat( '=', 60 ) . "\n";
echo 'Form:      ' . $report['form_code'] . "\n";
echo 'Template:  ' . ( '' !== $template_path ? $template_path : '(missing; grid only)' ) . "\n";
echo 'Page size: ' . $size['width'] . ' x ' . $size['height'] . " pt\n";
echo 'Grid step: ' . $report['grid_step'] . " pt\n";
echo 'Composite: ' . ( $report['composited'] ? 'official PDF background' : 'grid only' ) . "\n\n";

echo "Current coordinates:\n";
printf( "  %-18s %-20s %-4s %-7s %-7s %-5s %s\n", 'field', 'source', 'page', 'x', 'y', 'font', 'multiline' );
echo '  ' . str_repeat( '-', 70 ) . "\n";

foreach ( $report['fields'] as $field ) {
	printf(
		"  %-18s %-20s %-4d %-7s %-7s %-5s %s\n",
		$field['key'],
		$field['source'],
		$field['page'],
		$field['x'],
		$field['y'],
		$field['font_size'],
		$field['multiline'] ? 'yes' : '-'
	);
}

foreach ( $report['warnings'] as $warning ) {
	echo "  ! {$warning}\n";
}

echo "\nGrid overlay written to: " . $report['file_path'] . "\n";
echo 'Bytes: ' . $report['bytes'] . "\n";
