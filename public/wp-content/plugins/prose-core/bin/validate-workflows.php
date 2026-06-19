#!/usr/bin/env php
<?php
/**
 * Validate workflow repository JSON files and regenerate inventory.json.
 *
 * Usage: php bin/validate-workflows.php
 *
 * @package ProSeCore
 */

$base = dirname( __DIR__ ) . '/docs/workflows';
$schema_path = $base . '/schema/workflow.schema.json';
$inventory_path = $base . '/inventory.json';

$required_keys = array(
	'workflow',
	'workflow_category',
	'issue_type',
	'court',
	'counties_supported',
	'description',
	'triggers',
	'entry_questions',
	'routing_rules',
	'routing_priority',
	'intake_priority',
	'entry_conditions',
	'required_fields',
	'stages',
	'workflow_outcomes',
	'required_forms',
	'optional_forms',
	'supporting_documents',
);

$valid_courts = array( 'supreme_court', 'family_court' );
$valid_categories = array( 'divorce', 'family_court' );
$valid_field_types = array( 'string', 'integer', 'boolean', 'date', 'array' );

$seen_enums = array();

$workflow_files = array_merge(
	glob( $base . '/divorce/*.json' ) ?: array(),
	glob( $base . '/family_court/*.json' ) ?: array()
);

if ( empty( $workflow_files ) ) {
	fwrite( STDERR, "No workflow files found.\n" );
	exit( 1 );
}

$errors = array();
$inventory = array();
$workflow_names = array();
$routing_refs = array();

foreach ( $workflow_files as $file ) {
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

	foreach ( $required_keys as $key ) {
		if ( ! array_key_exists( $key, $data ) ) {
			$errors[] = basename( $file ) . ": missing required key '$key'";
		}
	}

	if ( isset( $data['workflow'] ) ) {
		if ( in_array( $data['workflow'], $workflow_names, true ) ) {
			$errors[] = basename( $file ) . ': duplicate workflow name ' . $data['workflow'];
		}
		$workflow_names[] = $data['workflow'];
	}

	if ( isset( $data['court'] ) && ! in_array( $data['court'], $valid_courts, true ) ) {
		$errors[] = basename( $file ) . ': invalid court ' . $data['court'];
	}

	if ( isset( $data['workflow_category'] ) && ! in_array( $data['workflow_category'], $valid_categories, true ) ) {
		$errors[] = basename( $file ) . ': invalid workflow_category ' . $data['workflow_category'];
	}

	if ( isset( $data['intake_priority'] ) && ! is_int( $data['intake_priority'] ) ) {
		$errors[] = basename( $file ) . ': intake_priority must be an integer';
	}

	if ( isset( $data['triggers'] ) && ( ! is_array( $data['triggers'] ) || count( $data['triggers'] ) < 1 ) ) {
		$errors[] = basename( $file ) . ': triggers must be a non-empty array';
	}

	if ( isset( $data['entry_questions'] ) && ! is_array( $data['entry_questions'] ) ) {
		$errors[] = basename( $file ) . ': entry_questions must be an array';
	}

	if ( isset( $data['workflow_outcomes'] ) && ( ! is_array( $data['workflow_outcomes'] ) || count( $data['workflow_outcomes'] ) < 1 ) ) {
		$errors[] = basename( $file ) . ': workflow_outcomes must be a non-empty array';
	}

	if ( isset( $data['required_fields'] ) && is_array( $data['required_fields'] ) ) {
		foreach ( $data['required_fields'] as $field ) {
			if ( ! isset( $field['key'], $field['type'], $field['required'] ) ) {
				$errors[] = basename( $file ) . ': required_fields entry missing key/type/required';
			} elseif ( ! in_array( $field['type'], $valid_field_types, true ) ) {
				$errors[] = basename( $file ) . ': invalid field type ' . $field['type'];
			}

			if ( empty( $field['question'] ) || ! is_string( $field['question'] ) ) {
				$errors[] = basename( $file ) . ": required field '" . ( $field['key'] ?? '?' ) . "' is missing a question";
			}
		}
	}

	$enum = $data['internal']['workflow_enum'] ?? '';
	if ( '' === $enum ) {
		$errors[] = basename( $file ) . ': missing internal.workflow_enum';
	} elseif ( isset( $seen_enums[ $enum ] ) ) {
		$errors[] = basename( $file ) . ": duplicate workflow_enum '$enum' (also in " . $seen_enums[ $enum ] . ')';
	} else {
		$seen_enums[ $enum ] = basename( $file );
	}

	if ( isset( $data['routing_rules'] ) && is_array( $data['routing_rules'] ) ) {
		foreach ( $data['routing_rules'] as $rule ) {
			if ( empty( $rule['condition'] ) || empty( $rule['workflow'] ) ) {
				$errors[] = basename( $file ) . ': routing_rule missing condition or workflow';
				continue;
			}
			$routing_refs[] = array(
				'file'   => basename( $file ),
				'target' => $rule['workflow'],
			);
		}
	}

	$internal = is_array( $data['internal'] ?? null ) ? $data['internal'] : array();
	$stages   = is_array( $data['stages'] ?? null ) ? $data['stages'] : array();

	if ( isset( $data['stages'] ) && is_array( $data['required_forms'] ) ) {
		foreach ( $data['required_forms'] as $mapping ) {
			if ( isset( $mapping['stage'] ) && ! in_array( $mapping['stage'], $stages, true ) ) {
				$errors[] = basename( $file ) . ': required_forms references unknown stage ' . $mapping['stage'];
			}
			if ( isset( $mapping['forms'] ) ) {
				foreach ( $mapping['forms'] as $form ) {
					if ( empty( $form['code'] ) ) {
						$errors[] = basename( $file ) . ': form entry missing code';
					}
				}
			}
		}
	}

	$nodes    = is_array( $internal['node_sequence'] ?? null ) ? $internal['node_sequence'] : array();
	$progress = is_array( $internal['progression'] ?? null ) ? $internal['progression'] : array();

	if ( empty( $nodes ) ) {
		$errors[] = basename( $file ) . ': internal.node_sequence must be a non-empty array';
	} elseif ( count( $stages ) < count( $nodes ) ) {
		$errors[] = basename( $file ) . ': stages count (' . count( $stages ) . ') must be at least node_sequence count (' . count( $nodes ) . ')';
	}

	foreach ( $nodes as $node_key ) {
		if ( ! is_string( $node_key ) || ! preg_match( '/^NODE_[0-9]+_[A-Z0-9_]+$/', $node_key ) ) {
			$errors[] = basename( $file ) . ': invalid node_sequence entry ' . (string) $node_key;
		}
	}

	if ( empty( $progress ) ) {
		$errors[] = basename( $file ) . ': internal.progression must be a non-empty array';
	} else {
		$first = $progress[0]['node'] ?? '';
		if ( ! empty( $nodes ) && (string) $first !== (string) $nodes[0] ) {
			$errors[] = basename( $file ) . ': progression entry node must match first node_sequence entry';
		}
	}

	if ( isset( $internal['edges'] ) && is_array( $internal['edges'] ) ) {
		foreach ( $internal['edges'] as $edge ) {
			foreach ( array( 'from', 'to' ) as $edge_key ) {
				if ( empty( $edge['condition']['kind'] ) || empty( $edge['condition']['value'] ) ) {
					$errors[] = basename( $file ) . ': edge missing condition kind/value';
				}
			}
		}
	}

	if ( isset( $data['optional_forms'] ) && is_array( $data['optional_forms'] ) && ! empty( $stages ) ) {
		foreach ( $data['optional_forms'] as $mapping ) {
			if ( isset( $mapping['stage'] ) && ! in_array( $mapping['stage'], $stages, true ) ) {
				$errors[] = basename( $file ) . ': optional_forms references unknown stage ' . $mapping['stage'];
			}
		}
	}

	$all_forms = array();
	foreach ( array( 'required_forms', 'optional_forms' ) as $form_key ) {
		if ( ! isset( $data[ $form_key ] ) || ! is_array( $data[ $form_key ] ) ) {
			continue;
		}
		foreach ( $data[ $form_key ] as $mapping ) {
			foreach ( $mapping['forms'] ?? array() as $form ) {
				if ( ! empty( $form['code'] ) ) {
					$all_forms[] = $form['code'];
				}
			}
		}
	}

	$inventory[] = array(
		'workflow'              => $data['workflow'] ?? basename( $file, '.json' ),
		'workflow_category'     => $data['workflow_category'] ?? '',
		'court'                 => $data['court'] ?? '',
		'issue_type'            => $data['issue_type'] ?? '',
		'counties_supported'    => $data['counties_supported'] ?? array(),
		'triggers'              => $data['triggers'] ?? array(),
		'entry_questions'       => $data['entry_questions'] ?? array(),
		'routing_priority'      => $data['routing_priority'] ?? 0,
		'intake_priority'       => $data['intake_priority'] ?? 0,
		'stages'                => $data['stages'] ?? array(),
		'workflow_outcomes'     => $data['workflow_outcomes'] ?? array(),
		'required_fields'       => $data['required_fields'] ?? array(),
		'required_forms'        => $all_forms,
		'supporting_documents'  => $data['supporting_documents'] ?? array(),
	);
}

$forbidden_standalone = array(
	'discovery_nyc',
	'motion_practice_nyc',
	'settlement_nyc',
	'trial_nyc',
	'judgment_nyc',
	'maintenance_nyc',
	'property_division_nyc',
	'modification_nyc',
	'enforcement_nyc',
);

foreach ( $forbidden_standalone as $name ) {
	if ( in_array( $name, $workflow_names, true ) ) {
		$errors[] = "Forbidden standalone workflow detected: $name (must be a stage, not an entry workflow)";
	}
}

foreach ( $routing_refs as $ref ) {
	if ( ! in_array( $ref['target'], $workflow_names, true ) ) {
		$errors[] = $ref['file'] . ': routing_rule references unknown workflow ' . $ref['target'];
	}
}

$expected_count = 12;
if ( count( $workflow_files ) !== $expected_count ) {
	$errors[] = 'Expected ' . $expected_count . ' workflows, found ' . count( $workflow_files );
}

$form_codes = array();
$form_files = array_merge(
	glob( dirname( __DIR__ ) . '/docs/forms/supreme_court/*.json' ) ?: array(),
	glob( dirname( __DIR__ ) . '/docs/forms/family_court/*.json' ) ?: array()
);

foreach ( $form_files as $file ) {
	$raw = file_get_contents( $file );

	if ( false === $raw ) {
		continue;
	}

	$data = json_decode( $raw, true );

	if ( is_array( $data ) && ! empty( $data['form_code'] ) ) {
		$form_codes[ (string) $data['form_code'] ] = true;
	}
}

foreach ( $workflow_files as $file ) {
	$raw = file_get_contents( $file );

	if ( false === $raw ) {
		continue;
	}

	$data = json_decode( $raw, true );

	if ( ! is_array( $data ) ) {
		continue;
	}

	foreach ( (array) ( $data['required_forms'] ?? array() ) as $stage_block ) {
		foreach ( (array) ( $stage_block['forms'] ?? array() ) as $form ) {
			$code = (string) ( $form['code'] ?? '' );

			if ( '' !== $code && ! isset( $form_codes[ $code ] ) ) {
				$errors[] = basename( $file ) . ": required form '$code' not found in forms catalog";
			}
		}
	}
}

if ( ! empty( $errors ) ) {
	fwrite( STDERR, "Validation failed:\n" );
	foreach ( $errors as $error ) {
		fwrite( STDERR, "  - $error\n" );
	}
	exit( 1 );
}

usort(
	$inventory,
	static function ( array $a, array $b ): int {
		return strcmp( $a['workflow'], $b['workflow'] );
	}
);

$written = file_put_contents(
	$inventory_path,
	json_encode(
		array(
			'generated_at' => gmdate( 'c' ),
			'total'        => count( $inventory ),
			'supreme_court' => count( array_filter( $inventory, static fn( $w ) => 'supreme_court' === $w['court'] ) ),
			'family_court'  => count( array_filter( $inventory, static fn( $w ) => 'family_court' === $w['court'] ) ),
			'workflows'    => $inventory,
		),
		JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
	) . "\n"
);

if ( false === $written ) {
	fwrite( STDERR, "Failed to write inventory.json\n" );
	exit( 1 );
}

echo 'Validated ' . count( $workflow_files ) . " workflow files.\n";
echo "Wrote $inventory_path\n";
exit( 0 );
