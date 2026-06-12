<?php
/**
 * Manual test harness for the Court Filing Packet Engine.
 *
 * Runs database-free from the command line: it assembles the core packages
 * with the Document Generation Engine, fills every court form, merges each
 * package into a single filing-ready packet PDF in filing order, writes the
 * individual filled forms, packet PDFs, packet-manifest.json and per-packet
 * manifests under tests/manual/filing-packet-output/, and writes a
 * packet-render-report.txt summary.
 *
 * Usage:
 *   php tests/manual/generate_filing_packets.php
 *
 * @package ProSeCore
 */

use ProSe\Core\Forms\Classification\Vocabulary;
use ProSe\Core\Forms\Documents\Document_Generation_Service;
use ProSe\Core\Forms\Documents\Filing\Court_Pdf_Fill_Service;
use ProSe\Core\Forms\Documents\Filing\Filing_Packet;
use ProSe\Core\Forms\Documents\Filing\Filing_Packet_Service;
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
		return json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
	}
}

if ( ! function_exists( 'trailingslashit' ) ) {
	function trailingslashit( $string ) {
		return rtrim( (string) $string, '/\\' ) . '/';
	}
}

require_once PROSE_CORE_PATH . 'includes/class-autoloader.php';
\ProSe\Core\Autoloader::register();

$output_dir = PROSE_CORE_PATH . 'tests/manual/filing-packet-output';

if ( ! is_dir( $output_dir ) ) {
	mkdir( $output_dir, 0775, true );
}

$storage = new Pdf_Storage_Service( $output_dir, 'https://example.test/prose-documents' );
$filler  = new Court_Pdf_Fill_Service();
$gen     = new Document_Generation_Service();
$service = new Filing_Packet_Service( $gen, null, null, $storage );

/**
 * Build an uncontested (no children) divorce case ready to generate.
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
	$state->set_case_id( 6001 );
	$state->set_county( Vocabulary::COUNTY_NEW_YORK );
	$state->set_court_routing( Vocabulary::ROUTE_SUPREME_COURT );
	( new Case_Service() )->record_event( $state, Case_Catalog::EVENT_SERVICE_COMPLETED, array( 'date' => '2026-02-01 00:00:00' ) );

	return $state;
}

/**
 * Build an uncontested (with children) divorce case.
 *
 * @return Case_State
 */
function uncontested_children_case(): Case_State {
	$state = ( new Case_Service() )->create_case(
		Vocabulary::WF_UNCONTESTED_DIVORCE,
		array(
			'petitioner_name' => 'Aisha Khan',
			'respondent_name' => 'Omar Khan',
			'marriage_date'   => '2008-09-15',
			'marriage_place'  => 'Brooklyn, NY',
			'children'        => true,
			'children_count'  => 2,
			'support_amount'  => '850.00',
		)
	);
	$state->set_case_id( 6002 );
	$state->set_county( Vocabulary::COUNTY_QUEENS );
	$state->set_court_routing( Vocabulary::ROUTE_SUPREME_COURT );
	( new Case_Service() )->record_event( $state, Case_Catalog::EVENT_SERVICE_COMPLETED, array( 'date' => '2026-02-15 00:00:00' ) );

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
	$state->set_case_id( 6003 );
	$state->set_county( Vocabulary::COUNTY_KINGS );
	$state->set_court_routing( Vocabulary::ROUTE_FAMILY_COURT );

	return $state;
}

/**
 * Build a child support case ready to generate.
 *
 * @return Case_State
 */
function child_support_case(): Case_State {
	$state = ( new Case_Service() )->create_case(
		Vocabulary::WF_CHILD_SUPPORT,
		array(
			'petitioner_name'  => 'Dana Lee',
			'respondent_name'  => 'Chris Lee',
			'children_count'   => 1,
			'support_amount'   => '650.00',
			'relief_requested' => 'Order of child support',
		)
	);
	$state->set_case_id( 6004 );
	$state->set_county( Vocabulary::COUNTY_BRONX );
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
	$state->set_case_id( 6005 );
	$state->set_county( Vocabulary::COUNTY_KINGS );
	$state->set_court_routing( Vocabulary::ROUTE_FAMILY_COURT );

	return $state;
}

/**
 * Fill a single form to "<code>-filled.pdf" and return a report block.
 *
 * @param Court_Pdf_Fill_Service      $filler   Fill service.
 * @param Pdf_Storage_Service         $storage  Storage.
 * @param Document_Generation_Service $gen      Generation service.
 * @param Case_State                  $state    Case state.
 * @param string                      $form     Form code.
 * @param string                      $package  Package key.
 * @param string                      $filename Output file name.
 * @return string
 */
function fill_form_block(
	Court_Pdf_Fill_Service $filler,
	Pdf_Storage_Service $storage,
	Document_Generation_Service $gen,
	Case_State $state,
	string $form,
	string $package,
	string $filename
): string {
	$document = $gen->generate_form( $state, $form, $package );
	$filled   = $filler->fill( $document );
	$stored   = $storage->store( (string) $filled['bytes'], $filename );

	$lines   = array();
	$lines[] = $filename;
	$lines[] = str_repeat( '-', 64 );
	$lines[] = '  form:                 ' . $filled['form_code'] . '  ' . $filled['title'];
	$lines[] = '  fill strategy:        ' . $filled['strategy'];
	$lines[] = '  template version:     ' . $filled['template_version'];
	$lines[] = '  field count:          ' . $filled['field_count'];
	$lines[] = '  resolved field count: ' . $filled['resolved_count'];
	$lines[] = '  missing field count:  ' . $filled['missing_count'];
	$lines[] = '  pages:                ' . $filled['page_count'];
	$lines[] = '  pdf path:             ' . $stored['file_path'];
	$lines[] = '  checksum:             ' . $stored['checksum'];
	$lines[] = '  bytes:                ' . $stored['bytes'];

	return implode( "\n", $lines ) . "\n";
}

/**
 * Format a filing packet as a report block.
 *
 * @param Filing_Packet $packet Packet.
 * @return string
 */
function packet_block( Filing_Packet $packet ): string {
	$audit = $packet->audit();

	$lines   = array();
	$lines[] = $packet->package_key();
	$lines[] = str_repeat( '-', 64 );
	$lines[] = '  forms (filing order): ' . implode( ', ', $packet->forms() );
	$lines[] = '  merge strategy:       ' . $packet->strategy();
	$lines[] = '  page count:           ' . $packet->page_count();
	$lines[] = '  field count:          ' . $packet->field_count();
	$lines[] = '  resolved field count: ' . $packet->resolved_count();
	$lines[] = '  missing field count:  ' . $packet->missing_count();
	$lines[] = '  packet pdf:           ' . $packet->file_path();
	$lines[] = '  manifest:             ' . $packet->manifest_path();
	$lines[] = '  download url:         ' . ( '' !== $packet->download_url() ? $packet->download_url() : '(n/a)' );
	$lines[] = '  checksum:             ' . $packet->checksum();
	$lines[] = '  bytes:                ' . $packet->bytes();
	$lines[] = '  render duration (ms): ' . $packet->duration_ms();
	$lines[] = '  audit:                generated_at=' . (string) ( $audit['generated_at'] ?? '' )
		. ' generated_by=' . (string) ( $audit['generated_by'] ?? '' )
		. ' source_case_id=' . (string) ( $audit['source_case_id'] ?? '' )
		. ' source_package_id=' . (string) ( $audit['source_package_id'] ?? '' )
		. ' version=' . (string) ( $audit['version'] ?? '' );

	return implode( "\n", $lines ) . "\n";
}

$report  = "Court Filing Packet Engine - manual render report\n";
$report .= 'generated: ' . gmdate( 'Y-m-d H:i:s' ) . " UTC\n";
$report .= 'output dir: ' . $storage->base_dir() . "\n";
$report .= 'acroform fill available: ' . ( $filler->is_acroform_available() ? 'yes' : 'no (builtin layout fallback)' ) . "\n";
$report .= str_repeat( '=', 64 ) . "\n\n";

// --- Individual filled forms. -------------------------------------------
$uncontested = uncontested_case();
$op          = order_of_protection_case();

$report .= "INDIVIDUAL FILLED FORMS\n" . str_repeat( '=', 64 ) . "\n\n";
$report .= fill_form_block( $filler, $storage, $gen, $uncontested, 'UD-1', Vocabulary::PKG_UNCONTESTED_NO_CHILDREN, 'ud1-filled.pdf' ) . "\n";
$report .= fill_form_block( $filler, $storage, $gen, $uncontested, 'UD-2', Vocabulary::PKG_UNCONTESTED_NO_CHILDREN, 'ud2-filled.pdf' ) . "\n";
$report .= fill_form_block( $filler, $storage, $gen, $op, 'FC-1', Vocabulary::PKG_ORDER_OF_PROTECTION, 'fc1-filled.pdf' ) . "\n";
$report .= fill_form_block( $filler, $storage, $gen, $op, 'FC-7', Vocabulary::PKG_ORDER_OF_PROTECTION, 'fc7-filled.pdf' ) . "\n";

// --- Package filing packets. --------------------------------------------
$packages = array(
	Vocabulary::PKG_UNCONTESTED_NO_CHILDREN   => uncontested_case(),
	Vocabulary::PKG_UNCONTESTED_WITH_CHILDREN => uncontested_children_case(),
	Vocabulary::PKG_CUSTODY_PETITION          => custody_case(),
	Vocabulary::PKG_CHILD_SUPPORT_PETITION    => child_support_case(),
	Vocabulary::PKG_ORDER_OF_PROTECTION       => order_of_protection_case(),
);

$report .= "FILING PACKETS\n" . str_repeat( '=', 64 ) . "\n\n";

$packets = array();

foreach ( $packages as $package_key => $state ) {
	$packet = $service->generate(
		$state,
		$package_key,
		array(
			'filename'          => $package_key . '.pdf',
			'manifest_filename' => $package_key . '.manifest.json',
		)
	);

	if ( null === $packet ) {
		$report .= $package_key . ": FAILED (no bundle)\n\n";
		continue;
	}

	$packets[ $package_key ] = $packet;
	$report                 .= packet_block( $packet ) . "\n";
}

// --- packet-manifest.json (example shape for the no-children package). ---
if ( isset( $packets[ Vocabulary::PKG_UNCONTESTED_NO_CHILDREN ] ) ) {
	$example  = $packets[ Vocabulary::PKG_UNCONTESTED_NO_CHILDREN ];
	$manifest = array(
		'package_key' => $example->package_key(),
		'forms'       => $example->forms(),
		'page_count'  => $example->page_count(),
	);

	file_put_contents( // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		$output_dir . '/packet-manifest.json',
		(string) wp_json_encode( $manifest )
	);
}

$report_path = $output_dir . '/packet-render-report.txt';
file_put_contents( $report_path, $report ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

echo $report;
echo "\nReport written to: {$report_path}\n";
