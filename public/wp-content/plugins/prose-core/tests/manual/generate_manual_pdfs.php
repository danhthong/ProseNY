<?php
/**
 * Manual test harness for the PDF Renderer.
 *
 * Runs database-free from the command line: it assembles the core packages
 * with the Document Generation Engine, renders single forms and combined
 * package bundles to PDF via the PDF Renderer, stores the artifacts under
 * tests/manual/pdf-render-output/, and writes a render-report.txt summary.
 *
 * Usage:
 *   php tests/manual/generate_manual_pdfs.php
 *
 * @package ProSeCore
 */

use ProSe\Core\Forms\Classification\Vocabulary;
use ProSe\Core\Forms\Documents\Document_Generation_Service;
use ProSe\Core\Forms\Documents\Generated_Document;
use ProSe\Core\Forms\Documents\Package_Document_Bundle;
use ProSe\Core\Forms\Documents\Pdf\Pdf_Render_Result;
use ProSe\Core\Forms\Documents\Pdf\Pdf_Renderer;
use ProSe\Core\Forms\Documents\Pdf\Pdf_Storage_Service;
use ProSe\Core\Forms\Engine\Case_Catalog;
use ProSe\Core\Forms\Engine\Case_Service;
use ProSe\Core\Forms\Engine\Case_State;

// --- Minimal WordPress shims so the engine runs without a WP bootstrap. ---
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

if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( $str ) {
		return trim( (string) $str );
	}
}

if ( ! function_exists( 'current_time' ) ) {
	function current_time( $type ) {
		unset( $type );
		return gmdate( 'Y-m-d H:i:s' );
	}
}

if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $data ) {
		return json_encode( $data ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
	}
}

if ( ! function_exists( 'trailingslashit' ) ) {
	function trailingslashit( $string ) {
		return rtrim( (string) $string, '/\\' ) . '/';
	}
}

require_once PROSE_CORE_PATH . 'includes/class-autoloader.php';
\ProSe\Core\Autoloader::register();

$output_dir = PROSE_CORE_PATH . 'tests/manual/pdf-render-output';
$storage    = new Pdf_Storage_Service( $output_dir, 'https://example.test/prose-documents' );
$renderer   = new Pdf_Renderer( null, null, $storage );
$service    = new Case_Service();
$gen        = new Document_Generation_Service();

/**
 * Build an uncontested divorce case (service recorded) ready to generate.
 *
 * @return Case_State
 */
function uncontested_case(): Case_State {
	$state = ( new Case_Service() )->create_case(
		Vocabulary::WF_UNCONTESTED_DIVORCE,
		array(
			'petitioner_name' => 'Jane Doe',
			'respondent_name' => 'John Doe',
			'marriage_date'   => '2010-06-01',
			'marriage_place'  => 'New York, NY',
			'children'        => false,
		)
	);
	$state->set_case_id( 5001 );
	$state->set_county( Vocabulary::COUNTY_NEW_YORK );
	$state->set_court_routing( Vocabulary::ROUTE_SUPREME_COURT );
	( new Case_Service() )->record_event( $state, Case_Catalog::EVENT_SERVICE_COMPLETED, array( 'date' => '2026-02-01 00:00:00' ) );

	return $state;
}

/**
 * Build a custody case ready to generate.
 *
 * @return Case_State
 */
function custody_case(): Case_State {
	$state = ( new Case_Service() )->create_case(
		Vocabulary::WF_CUSTODY,
		array(
			'petitioner_name'  => 'Maria Cruz',
			'respondent_name'  => 'Luis Cruz',
			'children_count'   => 2,
			'relief_requested' => 'Sole legal and physical custody',
		)
	);
	$state->set_case_id( 5002 );
	$state->set_county( Vocabulary::COUNTY_KINGS );
	$state->set_court_routing( Vocabulary::ROUTE_FAMILY_COURT );

	return $state;
}

/**
 * Build an order-of-protection case ready to generate.
 *
 * @return Case_State
 */
function order_of_protection_case(): Case_State {
	$state = ( new Case_Service() )->create_case(
		Vocabulary::WF_ORDER_OF_PROTECTION,
		array(
			'petitioner_name'  => 'Sam Park',
			'respondent_name'  => 'Kim Park',
			'incident_date'    => '2026-01-10',
			'relief_requested' => 'Stay-away order of protection',
		)
	);
	$state->set_case_id( 5003 );
	$state->set_county( Vocabulary::COUNTY_KINGS );
	$state->set_court_routing( Vocabulary::ROUTE_FAMILY_COURT );

	return $state;
}

/**
 * Format a render result as a report block.
 *
 * @param string            $label  Render label.
 * @param Pdf_Render_Result $result Result.
 * @return string
 */
function report_block( string $label, Pdf_Render_Result $result ): string {
	$audit = $result->audit();

	$lines   = array();
	$lines[] = $label;
	$lines[] = str_repeat( '-', 64 );
	$lines[] = '  template used:        ' . ( '' !== $result->template() ? $result->template() : '(none)' ) . ' v' . $result->template_version() . ' (' . $result->renderer_type() . ')';
	$lines[] = '  field count:          ' . $result->field_count();
	$lines[] = '  resolved field count: ' . $result->resolved_count();
	$lines[] = '  missing field count:  ' . $result->missing_count();
	$lines[] = '  pdf path:             ' . $result->file_path();
	$lines[] = '  download url:         ' . ( '' !== $result->download_url() ? $result->download_url() : '(n/a)' );
	$lines[] = '  checksum:             ' . $result->checksum();
	$lines[] = '  bytes:                ' . $result->bytes();
	$lines[] = '  render duration (ms): ' . $result->duration_ms();
	$lines[] = '  audit:                generated_at=' . (string) ( $audit['generated_at'] ?? '' )
		. ' generated_by=' . (string) ( $audit['generated_by'] ?? '' )
		. ' source_case_id=' . (string) ( $audit['source_case_id'] ?? '' )
		. ' source_package_id=' . (string) ( $audit['source_package_id'] ?? '' )
		. ' template_version=' . (string) ( $audit['template_version'] ?? '' );

	return implode( "\n", $lines ) . "\n";
}

// --- Render single-form samples. ----------------------------------------
$uncontested = uncontested_case();
$custody     = custody_case();
$op          = order_of_protection_case();

$ud1 = $gen->generate_form( $uncontested, 'UD-1', Vocabulary::PKG_UNCONTESTED_NO_CHILDREN );
$ud2 = $gen->generate_form( $uncontested, 'UD-2', Vocabulary::PKG_UNCONTESTED_NO_CHILDREN );
$fc1 = $gen->generate_form( $op, 'FC-1', Vocabulary::PKG_ORDER_OF_PROTECTION );
$fc7 = $gen->generate_form( $op, 'FC-7', Vocabulary::PKG_ORDER_OF_PROTECTION );

$report  = "PDF Renderer - manual render report\n";
$report .= 'generated: ' . gmdate( 'Y-m-d H:i:s' ) . " UTC\n";
$report .= 'output dir: ' . $storage->base_dir() . "\n";
$report .= str_repeat( '=', 64 ) . "\n\n";

$report .= report_block( 'ud1-sample.pdf', $renderer->render_document( $ud1, array( 'filename' => 'ud1-sample.pdf' ) ) ) . "\n";
$report .= report_block( 'ud2-sample.pdf', $renderer->render_document( $ud2, array( 'filename' => 'ud2-sample.pdf' ) ) ) . "\n";
$report .= report_block( 'fc1-sample.pdf', $renderer->render_document( $fc1, array( 'filename' => 'fc1-sample.pdf' ) ) ) . "\n";
$report .= report_block( 'fc7-sample.pdf', $renderer->render_document( $fc7, array( 'filename' => 'fc7-sample.pdf' ) ) ) . "\n";

// --- Render package bundles. ---------------------------------------------
$uncontested_bundle = $gen->generate_package( uncontested_case(), Vocabulary::PKG_UNCONTESTED_NO_CHILDREN );
$custody_bundle     = $gen->generate_package( $custody, Vocabulary::PKG_CUSTODY_PETITION );
$op_bundle          = $gen->generate_package( order_of_protection_case(), Vocabulary::PKG_ORDER_OF_PROTECTION );

$report .= report_block(
	'package-uncontested-divorce.pdf',
	$renderer->render_package( $uncontested_bundle, array( 'filename' => 'package-uncontested-divorce.pdf' ) )
) . "\n";
$report .= report_block(
	'package-custody.pdf',
	$renderer->render_package( $custody_bundle, array( 'filename' => 'package-custody.pdf' ) )
) . "\n";
$report .= report_block(
	'package-order-of-protection.pdf',
	$renderer->render_package( $op_bundle, array( 'filename' => 'package-order-of-protection.pdf' ) )
) . "\n";

$report_path = $output_dir . '/render-report.txt';

if ( ! is_dir( $output_dir ) ) {
	mkdir( $output_dir, 0775, true );
}

file_put_contents( $report_path, $report ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

echo $report;
echo "\nReport written to: {$report_path}\n";
