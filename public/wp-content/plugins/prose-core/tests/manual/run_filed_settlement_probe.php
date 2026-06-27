<?php
/**
 * Reproduce debug export: filed + settlement should land on service guidance.
 *
 * @package ProSeCore
 */

require_once dirname( __DIR__ ) . '/bootstrap.php';

use ProSe\Core\Ai_Intake\AI_Intake_Interpreter;
use ProSe\Core\Ai_Intake\Stub_Ai_Provider;
use ProSe\Core\Forms\Classification\Vocabulary;
use ProSe\Core\Routing\Workflow_Catalog;

Workflow_Catalog::reset_cache();

$interpreter = new AI_Intake_Interpreter( new Stub_Ai_Provider() );

$turn1 = $interpreter->interpret(
	'I need to file for divorce in New York City',
	array(),
	array()
);

$turn2 = $interpreter->interpret(
	'We have one child under 21. We both agree to the divorce and already signed a settlement agreement. I filed the divorce papers in Brooklyn about two weeks ago.',
	$turn1['state'] ?? array(),
	array(
		array( 'role' => 'user', 'content' => 'I need to file for divorce in New York City' ),
		array( 'role' => 'assistant', 'content' => 'Do you have any children under 21?' ),
	)
);

$reply  = (string) ( $turn2['question'] ?? '' );
$node   = (string) ( $turn2['case_profile']['procedural_node'] ?? '' );
$stage  = (string) ( $turn2['case_profile']['roadmap']['current_stage']['id'] ?? '' );
$facts  = $turn2['state']['facts'] ?? array();

echo "node: {$node}\n";
echo "stage: {$stage}\n";
echo "reply: {$reply}\n";

if ( Vocabulary::NODE_1002_SERVICE_COMPLETE !== $node ) {
	fwrite( STDERR, "FAIL: expected service node\n" );
	exit( 1 );
}

if ( str_contains( strtolower( $reply ), 'how a new divorce case usually starts' ) ) {
	fwrite( STDERR, "FAIL: commencement brief still returned\n" );
	exit( 1 );
}

if ( str_contains( strtolower( $reply ), 'ud-1' ) ) {
	fwrite( STDERR, "FAIL: UD-1 mentioned in reply\n" );
	exit( 1 );
}

echo "OK\n";
