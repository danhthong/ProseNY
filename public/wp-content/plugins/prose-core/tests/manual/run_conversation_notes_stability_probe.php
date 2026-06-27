<?php
/**
 * Verify conversation_summary stays fact-notes-only across turns.
 *
 * @package ProSeCore
 */

require_once dirname( __DIR__ ) . '/bootstrap.php';

use ProSe\Core\Ai_Intake\AI_Intake_Interpreter;
use ProSe\Core\Ai_Intake\Stub_Ai_Provider;
use ProSe\Core\Routing\Workflow_Catalog;

Workflow_Catalog::reset_cache();

$interpreter = new AI_Intake_Interpreter( new Stub_Ai_Provider() );
$state       = array(
	'workflow'     => 'uncontested_divorce_children_nyc',
	'case_profile' => array(
		'workflow'                 => 'uncontested_divorce_children_nyc',
		'procedural_node'          => 'NODE_1010_JUDGMENT',
		'guidance_brief_delivered' => true,
	),
	'facts'        => array(
		'spouse_agrees' => array( 'value' => true, 'confidence' => 0.95, 'confirmed' => true ),
		'child_count'   => array( 'value' => 1, 'confidence' => 0.95, 'confirmed' => true ),
		'county'        => array( 'value' => 'Queens', 'confidence' => 0.95, 'confirmed' => true ),
	),
);

$result1 = $interpreter->interpret( 'hello', $state );
$summary1 = (string) ( $result1['state']['conversation_summary'] ?? '' );

$result2 = $interpreter->interpret( 'what next?', $result1['state'] );
$summary2 = (string) ( $result2['state']['conversation_summary'] ?? '' );

echo "Turn 1: {$summary1}\n";
echo "Turn 2: {$summary2}\n";

if ( str_contains( $summary2, 'Case Summary' ) ) {
	fwrite( STDERR, "FAIL: nested Case Summary in persisted conversation_summary\n" );
	exit( 1 );
}

echo "OK: conversation notes stable\n";
