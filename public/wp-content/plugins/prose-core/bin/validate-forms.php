#!/usr/bin/env php
<?php
/**
 * Validate Forms Repository JSON files and regenerate manifest + workflow coverage.
 *
 * Usage: php bin/validate-forms.php
 *
 * @package ProSeCore
 */

$plugin_root = dirname( __DIR__ );
$forms_base  = $plugin_root . '/docs/forms';
$workflow_base = $plugin_root . '/docs/workflows';
$manifest_path = $forms_base . '/manifest.json';
$coverage_path = $forms_base . '/workflow_coverage.md';

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', $plugin_root . '/' );
}

if ( ! defined( 'PROSE_CORE_PATH' ) ) {
	define( 'PROSE_CORE_PATH', $plugin_root . '/' );
}

require_once PROSE_CORE_PATH . 'modules/forms/class-form-source-selector.php';

$required_record_keys = array(
	'form_code',
	'internal_code',
	'title',
	'court',
	'category',
	'county_specific',
	'counties_supported',
	'source_files',
	'preferred_source',
	'editable_source',
	'fillable_pdf_available',
	'docx_available',
	'wpd_available',
	'import_status',
	'workflow_references',
	'fillable_strategy',
	'field_mapping_status',
	'generation_ready',
);

$valid_courts              = array( 'supreme_court', 'family_court' );
$valid_fillable_strategies = array( 'docx_template', 'pdf_acroform', 'pdf_overlay', 'none' );
$valid_mapping_statuses    = array( 'unmapped', 'partial', 'mapped', 'not_required' );

$form_files = array_merge(
	glob( $forms_base . '/supreme_court/*.json' ) ?: array(),
	glob( $forms_base . '/family_court/*.json' ) ?: array()
);

$workflow_files = array_merge(
	glob( $workflow_base . '/divorce/*.json' ) ?: array(),
	glob( $workflow_base . '/family_court/*.json' ) ?: array()
);

$errors   = array();
$forms    = array();
$seen_codes = array();

foreach ( $form_files as $file ) {
	$raw = file_get_contents( $file );

	if ( false === $raw ) {
		$errors[] = basename( $file ) . ': cannot read file';
		continue;
	}

	$data = json_decode( $raw, true );

	if ( JSON_ERROR_NONE !== json_last_error() ) {
		$errors[] = basename( $file ) . ': invalid JSON — ' . json_last_error_msg();
		continue;
	}

	$form_code = (string) ( $data['form_code'] ?? '' );

	if ( '' === $form_code ) {
		$errors[] = basename( $file ) . ': missing form_code';
		continue;
	}

	if ( isset( $seen_codes[ $form_code ] ) ) {
		$errors[] = basename( $file ) . ": duplicate form_code '$form_code'";
	}

	$seen_codes[ $form_code ] = true;

	foreach ( $required_record_keys as $key ) {
		if ( ! array_key_exists( $key, $data ) ) {
			$errors[] = basename( $file ) . ": missing required key '$key'";
		}
	}

	if ( isset( $data['court'] ) && ! in_array( $data['court'], $valid_courts, true ) ) {
		$errors[] = basename( $file ) . ': invalid court ' . $data['court'];
	}

	if ( isset( $data['fillable_strategy'] ) && ! in_array( $data['fillable_strategy'], $valid_fillable_strategies, true ) ) {
		$errors[] = basename( $file ) . ': invalid fillable_strategy ' . $data['fillable_strategy'];
	}

	if ( isset( $data['field_mapping_status'] ) && ! in_array( $data['field_mapping_status'], $valid_mapping_statuses, true ) ) {
		$errors[] = basename( $file ) . ': invalid field_mapping_status ' . $data['field_mapping_status'];
	}

	$preferred = (string) ( $data['preferred_source'] ?? '' );
	$source_files = (array) ( $data['source_files'] ?? array() );

	if ( '' !== $preferred && ! isset( $source_files[ $preferred ] ) ) {
		$errors[] = basename( $file ) . ": preferred_source '$preferred' not found in source_files";
	}

	foreach ( $source_files as $slot => $entry ) {
		if ( ! is_array( $entry ) ) {
			$errors[] = basename( $file ) . ": source_files.$slot is not an object";
			continue;
		}

		$path = (string) ( $entry['path'] ?? '' );

		if ( '' !== $path && ! is_readable( $path ) ) {
			$errors[] = basename( $file ) . ": source_files.$slot path does not exist: $path";
		}
	}

	$computed = \ProSe\Core\Forms\Form_Source_Selector::compute( $source_files );

	if ( isset( $data['fillable_strategy'] ) && (string) $data['fillable_strategy'] !== $computed['fillable_strategy'] ) {
		$errors[] = basename( $file ) . ': fillable_strategy does not match computed value';
	}

	if ( isset( $data['generation_ready'] ) && (bool) $data['generation_ready'] !== $computed['generation_ready'] ) {
		$errors[] = basename( $file ) . ': generation_ready does not match computed value';
	}

	$forms[ $form_code ] = $data;
}

$workflows         = array();
$workflow_coverage = array();

foreach ( $workflow_files as $file ) {
	$raw = file_get_contents( $file );

	if ( false === $raw ) {
		$errors[] = basename( $file ) . ': cannot read workflow file';
		continue;
	}

	$data = json_decode( $raw, true );

	if ( ! is_array( $data ) || empty( $data['workflow'] ) ) {
		$errors[] = basename( $file ) . ': invalid workflow JSON';
		continue;
	}

	$workflow_key = (string) $data['workflow'];
	$required_codes = array();
	$seen_req = array();

	foreach ( (array) ( $data['required_forms'] ?? array() ) as $stage_block ) {
		foreach ( (array) ( $stage_block['forms'] ?? array() ) as $form ) {
			$code = (string) ( $form['code'] ?? '' );

			if ( '' === $code || isset( $seen_req[ $code ] ) ) {
				continue;
			}

			$seen_req[ $code ] = true;
			$required_codes[]  = $code;
		}
	}

	$workflow_coverage[ $workflow_key ] = array(
		'workflow' => $workflow_key,
		'court'    => (string) ( $data['court'] ?? '' ),
		'forms'    => array(),
	);

	foreach ( $required_codes as $code ) {
		$record = $forms[ $code ] ?? null;
		$exists = null !== $record;

		if ( ! $exists ) {
			$errors[] = "Workflow $workflow_key: missing required form $code";
		}

		$docx_available = false;
		$fillable_available = false;

		if ( $exists ) {
			$docx_available     = ! empty( $record['docx_available'] );
			$fillable_available = ! empty( $record['fillable_pdf_available'] );
		}

		$workflow_coverage[ $workflow_key ]['forms'][] = array(
			'code'               => $code,
			'exists'             => $exists,
			'docx_available'     => $docx_available,
			'fillable_available' => $fillable_available,
		);
	}

	$workflows[ $workflow_key ] = $data;
}

$manifest = array(
	'generated_at'            => gmdate( 'c' ),
	'total_forms'             => count( $forms ),
	'supreme_court_forms'     => count( array_filter( $forms, static fn( $f ) => 'supreme_court' === ( $f['court'] ?? '' ) ) ),
	'family_court_forms'      => count( array_filter( $forms, static fn( $f ) => 'family_court' === ( $f['court'] ?? '' ) ) ),
	'docx_available'          => count( array_filter( $forms, static fn( $f ) => ! empty( $f['docx_available'] ) ) ),
	'fillable_pdf_available'  => count( array_filter( $forms, static fn( $f ) => ! empty( $f['fillable_pdf_available'] ) ) ),
	'wpd_available'           => count( array_filter( $forms, static fn( $f ) => ! empty( $f['wpd_available'] ) ) ),
	'generation_ready'        => count( array_filter( $forms, static fn( $f ) => ! empty( $f['generation_ready'] ) ) ),
	'workflow_referenced'     => count( array_filter( $forms, static fn( $f ) => ! empty( $f['workflow_references'] ) ) ),
);

$expected_manifest_counts = array(
	'total_forms'            => count( $forms ),
	'supreme_court_forms'    => $manifest['supreme_court_forms'],
	'family_court_forms'     => $manifest['family_court_forms'],
	'docx_available'         => $manifest['docx_available'],
	'fillable_pdf_available' => $manifest['fillable_pdf_available'],
	'wpd_available'          => $manifest['wpd_available'],
);

foreach ( $expected_manifest_counts as $key => $expected ) {
	if ( $manifest[ $key ] !== $expected ) {
		$errors[] = "Manifest count mismatch for $key";
	}
}

if ( ! empty( $errors ) ) {
	fwrite( STDERR, "Validation failed:\n" );

	foreach ( $errors as $error ) {
		fwrite( STDERR, "  - $error\n" );
	}

	exit( 1 );
}

$manifest_written = file_put_contents(
	$manifest_path,
	json_encode( $manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n"
);

if ( false === $manifest_written ) {
	fwrite( STDERR, "Failed to write manifest.json\n" );
	exit( 1 );
}

$coverage_md = "# Workflow Form Coverage\n\n";
$coverage_md .= "Generated: " . gmdate( 'c' ) . "\n\n";
$coverage_md .= "Purpose: identify missing forms and asset gaps before Package Builder.\n\n";

ksort( $workflow_coverage );

foreach ( $workflow_coverage as $entry ) {
	$coverage_md .= "## Workflow: " . $entry['workflow'] . "\n\n";
	$coverage_md .= 'Court: ' . $entry['court'] . "\n\n";
	$coverage_md .= "### Required Forms\n\n";

	foreach ( $entry['forms'] as $form_entry ) {
		$exists_mark    = $form_entry['exists'] ? '✓ Exists' : '✗ Missing';
		$docx_mark      = $form_entry['docx_available'] ? '✓ DOCX Available' : '✗ DOCX Missing';
		$fillable_mark  = $form_entry['fillable_available'] ? '✓ Fillable PDF Available' : '✗ Fillable PDF Missing';

		$coverage_md .= '- **' . $form_entry['code'] . "** — $exists_mark | $docx_mark | $fillable_mark\n";
	}

	$coverage_md .= "\n";
}

$coverage_written = file_put_contents( $coverage_path, $coverage_md );

if ( false === $coverage_written ) {
	fwrite( STDERR, "Failed to write workflow_coverage.md\n" );
	exit( 1 );
}

echo 'Validated ' . count( $form_files ) . " form records.\n";
echo "Wrote $manifest_path\n";
echo "Wrote $coverage_path\n";
exit( 0 );
