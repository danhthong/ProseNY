<?php
/**
 * Manual test harness for the Document Generation Engine.
 *
 * Runs database-free from the command line and prints a readable walk
 * through field resolution, validation, package completeness, generation
 * status, and the audit trail for the five core packages.
 *
 * Usage:
 *   php bin/manual-document-generation.php
 *   php bin/manual-document-generation.php uncontested
 *   php bin/manual-document-generation.php custody
 *
 * @package ProSeCore
 */

use ProSe\Core\Forms\Classification\Vocabulary;
use ProSe\Core\Forms\Documents\Document_Generation_Service;
use ProSe\Core\Forms\Documents\Generated_Document;
use ProSe\Core\Forms\Documents\Package_Document_Bundle;
use ProSe\Core\Forms\Engine\Case_Catalog;
use ProSe\Core\Forms\Engine\Case_Service;
use ProSe\Core\Forms\Engine\Case_State;

// --- Minimal WordPress shims so the engine runs without a WP bootstrap. ---
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', '/tmp/wordpress/' );
}

if ( ! defined( 'PROSE_CORE_PATH' ) ) {
	define( 'PROSE_CORE_PATH', dirname( __DIR__ ) . '/' );
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

/**
 * Print a section heading.
 *
 * @param string $title Heading text.
 * @return void
 */
function heading( string $title ): void {
	echo "\n" . str_repeat( '=', 72 ) . "\n";
	echo $title . "\n";
	echo str_repeat( '=', 72 ) . "\n";
}

/**
 * Render a completeness snapshot.
 *
 * @param Package_Document_Bundle $bundle Bundle.
 * @return void
 */
function print_completeness( Package_Document_Bundle $bundle ): void {
	$c = $bundle->completeness();

	echo sprintf(
		"  completion: %d%%   ready_to_generate: %s\n",
		$c->completion_percentage(),
		$c->is_ready_to_generate() ? 'YES' : 'no'
	);
	echo '  missing_forms:  ' . ( $c->missing_forms() ? implode( ', ', $c->missing_forms() ) : '(none)' ) . "\n";
	echo '  missing_fields: ' . ( $c->missing_fields() ? implode( ', ', $c->missing_fields() ) : '(none)' ) . "\n";
}

/**
 * Render the forms in a bundle with status and resolved fields.
 *
 * @param Package_Document_Bundle $bundle Bundle.
 * @return void
 */
function print_documents( Package_Document_Bundle $bundle ): void {
	foreach ( $bundle->documents() as $form_code => $doc ) {
		echo sprintf(
			"\n  [%s] %s — %s (%s)\n",
			$form_code,
			$doc->title(),
			$doc->status(),
			$doc->requirement()
		);

		foreach ( $doc->fields() as $field ) {
			$value = $field->value();
			$value = is_scalar( $value ) ? (string) $value : wp_json_encode( $value );

			if ( ! $field->is_visible() ) {
				$state_tag = '[hidden]';
			} elseif ( $field->is_resolved() ) {
				$state_tag = '[resolved]';
			} else {
				$state_tag = '[MISSING]';
			}

			echo sprintf(
				"      %-18s %-12s %-16s %s%s%s\n",
				$field->key(),
				$state_tag,
				'{' . $field->field_class() . '}',
				'' === (string) $value ? '—' : $value,
				$field->is_required() ? '  *required' : '',
				$field->source() ? '  <' . $field->source() . '>' : ''
			);
		}

		$validation = $doc->validation();

		if ( $validation && ! $validation->is_valid() ) {
			echo '      ! validation: ' . implode( ', ', $validation->errors() ) . "\n";
		}
	}
}

/**
 * Render a generated document's audit trail.
 *
 * @param Generated_Document|null $doc Document.
 * @return void
 */
function print_audit( ?Generated_Document $doc ): void {
	if ( null === $doc || null === $doc->audit() ) {
		echo "  (no audit trail)\n";
		return;
	}

	$a = $doc->audit();

	echo sprintf(
		"  generated_at=%s  generated_by=%d  version=%d  source_case_id=%d  source_package_id=%s\n",
		$a->generated_at(),
		$a->generated_by(),
		$a->version(),
		$a->source_case_id(),
		$a->source_package_id()
	);
}

/**
 * Scenario: uncontested divorce (incomplete -> service event -> generated).
 *
 * @return void
 */
function scenario_uncontested(): void {
	$cases = new Case_Service();
	$gen   = new Document_Generation_Service();

	$state = $cases->create_case(
		Vocabulary::WF_UNCONTESTED_DIVORCE,
		array(
			'petitioner_name' => 'Jane Doe',
			'respondent_name' => 'John Doe',
			'marriage_date'   => '2010-06-01',
			'children'        => false,
		)
	);
	$state->set_county( Vocabulary::COUNTY_NEW_YORK );
	$state->set_court_routing( Vocabulary::ROUTE_SUPREME_COURT );

	$package = Vocabulary::PKG_UNCONTESTED_NO_CHILDREN;

	heading( 'UNCONTESTED DIVORCE — step 1: before service is recorded' );
	$bundle = $gen->assemble_package( $state, $package );
	print_completeness( $bundle );
	print_documents( $bundle );

	heading( 'UNCONTESTED DIVORCE — step 2: record SERVICE_COMPLETED, then generate' );
	$cases->record_event( $state, Case_Catalog::EVENT_SERVICE_COMPLETED, array( 'date' => '2026-02-01 00:00:00' ) );
	$generated = $gen->generate_package( $state, $package );
	print_completeness( $generated );
	print_documents( $generated );

	heading( 'UNCONTESTED DIVORCE — audit trail (UD-1)' );
	print_audit( $generated->document( 'UD-1' ) );
}

/**
 * Scenario: a single family-court package.
 *
 * @param string               $label    Display label.
 * @param string               $workflow Workflow key.
 * @param string               $package  Package key.
 * @param array<string, mixed> $answers  Intake answers.
 * @return void
 */
function scenario_family( string $label, string $workflow, string $package, array $answers ): void {
	$cases = new Case_Service();
	$gen   = new Document_Generation_Service();

	$state = $cases->create_case( $workflow, $answers );
	$state->set_county( Vocabulary::COUNTY_KINGS );
	$state->set_court_routing( Vocabulary::ROUTE_FAMILY_COURT );

	heading( $label . ' — generate package ' . $package );
	$bundle = $gen->generate_package( $state, $package );
	print_completeness( $bundle );
	print_documents( $bundle );
}

/**
 * Scenario: contested divorce commencement.
 *
 * @return void
 */
function scenario_contested(): void {
	$cases = new Case_Service();
	$gen   = new Document_Generation_Service();

	$state = $cases->create_case(
		Vocabulary::WF_CONTESTED_DIVORCE,
		array(
			'petitioner_name' => 'Jane Doe',
			'respondent_name' => 'John Doe',
			'marriage_date'   => '2010-06-01',
		)
	);
	$state->set_county( Vocabulary::COUNTY_NEW_YORK );
	$state->set_court_routing( Vocabulary::ROUTE_SUPREME_COURT );
	$cases->record_event( $state, Case_Catalog::EVENT_SERVICE_COMPLETED, array( 'date' => '2026-03-10 00:00:00' ) );

	heading( 'CONTESTED DIVORCE — generate commencement package' );
	$bundle = $gen->generate_package( $state, Vocabulary::PKG_CONTESTED_COMMENCEMENT );
	print_completeness( $bundle );
	print_documents( $bundle );
}

// --- Dispatch -----------------------------------------------------------
$which       = isset( $argv[1] ) ? strtolower( (string) $argv[1] ) : 'all';
$output_file = isset( $argv[2] ) && '' !== trim( (string) $argv[2] )
	? (string) $argv[2]
	: PROSE_CORE_PATH . 'bin/manual-document-generation-output.txt';

// Capture all scenario output so it can be written to a text file as well
// as echoed to the console.
ob_start();

switch ( $which ) {
	case 'uncontested':
		scenario_uncontested();
		break;

	case 'contested':
		scenario_contested();
		break;

	case 'custody':
		scenario_family(
			'CUSTODY',
			Vocabulary::WF_CUSTODY,
			Vocabulary::PKG_CUSTODY_PETITION,
			array(
				'petitioner_name'  => 'Maria Cruz',
				'respondent_name'  => 'Luis Cruz',
				'children_count'   => 2,
				'relief_requested' => 'Sole legal and physical custody',
			)
		);
		break;

	case 'child-support':
	case 'support':
		scenario_family(
			'CHILD SUPPORT',
			Vocabulary::WF_CHILD_SUPPORT,
			Vocabulary::PKG_CHILD_SUPPORT_PETITION,
			array(
				'petitioner_name' => 'Ann Lee',
				'respondent_name' => 'Bo Lee',
				'children_count'  => 1,
				'support_amount'  => '650.00',
			)
		);
		break;

	case 'op':
	case 'protection':
		scenario_family(
			'ORDER OF PROTECTION',
			Vocabulary::WF_ORDER_OF_PROTECTION,
			Vocabulary::PKG_ORDER_OF_PROTECTION,
			array(
				'petitioner_name'  => 'Sam Park',
				'respondent_name'  => 'Kim Park',
				'incident_date'    => '2026-01-10',
				'relief_requested' => 'Stay-away order of protection',
			)
		);
		break;

	case 'all':
	default:
		scenario_uncontested();
		scenario_contested();
		scenario_family(
			'CUSTODY',
			Vocabulary::WF_CUSTODY,
			Vocabulary::PKG_CUSTODY_PETITION,
			array(
				'petitioner_name'  => 'Maria Cruz',
				'respondent_name'  => 'Luis Cruz',
				'children_count'   => 2,
				'relief_requested' => 'Sole legal and physical custody',
			)
		);
		scenario_family(
			'CHILD SUPPORT',
			Vocabulary::WF_CHILD_SUPPORT,
			Vocabulary::PKG_CHILD_SUPPORT_PETITION,
			array(
				'petitioner_name' => 'Ann Lee',
				'respondent_name' => 'Bo Lee',
				'children_count'  => 1,
				'support_amount'  => '650.00',
			)
		);
		scenario_family(
			'ORDER OF PROTECTION',
			Vocabulary::WF_ORDER_OF_PROTECTION,
			Vocabulary::PKG_ORDER_OF_PROTECTION,
			array(
				'petitioner_name'  => 'Sam Park',
				'respondent_name'  => 'Kim Park',
				'incident_date'    => '2026-01-10',
				'relief_requested' => 'Stay-away order of protection',
			)
		);
		break;
}

echo "\nDone.\n";

// --- Write captured output to a text file and echo to the console. ------
$captured = ob_get_clean();

$header = sprintf(
	"Document Generation Engine — manual test\nscenario: %s\ngenerated: %s\n",
	$which,
	gmdate( 'Y-m-d H:i:s' ) . ' UTC'
);

$written = file_put_contents( $output_file, $header . $captured );

echo $captured;

if ( false === $written ) {
	fwrite( STDERR, "\n[!] Could not write output to {$output_file}\n" );
} else {
	echo "\nOutput written to: {$output_file}\n";
}
