<?php
/**
 * Manual probe: procedural stage sync after download path.
 *
 * Usage: php tests/manual/run_stage_sync_probe.php
 */

require dirname( __DIR__, 2 ) . '/tests/bootstrap.php';

use ProSe\Core\Intake\Case_Actions_Resolver;
use ProSe\Core\Intake\Procedural_Stage_Completer;
use ProSe\Core\Routing\Workflow_Catalog;

Workflow_Catalog::reset_cache();

$profile = array(
	'workflow'        => 'uncontested_divorce_children_nyc',
	'procedural_node' => 'NODE_1002_SERVICE_COMPLETE',
	'facts'           => array(
		'county'        => 'Queens',
		'spouse_agrees' => true,
		'children'      => true,
		'child_count'   => 1,
	),
	'progress' => 40,
);

$resolver = new Case_Actions_Resolver();
$actions  = $resolver->resolve( $profile );
$stage_id = (string) ( $actions['stage_context']['current_stage']['id'] ?? '' );
$codes    = array_column( $actions['stage_context']['stage_forms'] ?? array(), 'code' );

echo "Stage: {$stage_id}\n";
echo 'Forms: ' . implode( ', ', $codes ) . "\n";

if ( 'service' !== $stage_id || ! in_array( 'UD-3', $codes, true ) ) {
	fwrite( STDERR, "FAIL: service stage / UD-3 not resolved from procedural_node\n" );
	exit( 1 );
}

$completer = new Procedural_Stage_Completer();
$start     = array_merge(
	$profile,
	array( 'procedural_node' => 'NODE_1001_DIVORCE_FILED' )
);
$result    = $completer->complete_current_stage( $start );

if ( empty( $result['advanced'] ) || 'NODE_1002_SERVICE_COMPLETE' !== ( $result['case_profile']['procedural_node'] ?? '' ) ) {
	fwrite( STDERR, "FAIL: commencement completion did not advance to service node\n" );
	exit( 1 );
}

echo "Advance: NODE_1001 -> " . ( $result['case_profile']['procedural_node'] ?? '' ) . "\n";
echo "OK\n";
